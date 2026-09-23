<?php

namespace App\Modules\Automation\Services;

use App\Modules\AI\Services\LlmGateway;

/**
 * Turns a natural-language description into a complete, ready-to-run automation graph
 * (trigger + nodes + edges) using the workspace's configured LLM provider.
 *
 * The model's output is never trusted blindly: every node type and trigger type is
 * validated against what {@see AutomationEngine} can actually execute, dangling edges
 * are dropped, a single trigger is guaranteed, and the canvas is auto-laid-out — so the
 * result always loads in the builder and runs through the engine.
 */
class WorkflowGenerator
{
    /**
     * Only what the release validator will activate.
     *
     * The generator used to offer every node type the engine has ever had and
     * eleven triggers, so a client asking for help got a draft the server
     * would then refuse to switch on. Mirroring the validator means "Generate
     * with AI" can only produce workflows that are actually usable.
     */
    private const NODE_TYPES = WorkflowValidator::TYPES;

    private const TRIGGER_TYPES = ['message.received'];

    public function __construct(private readonly LlmGateway $llmGateway) {}

    /**
     * @return array{name: string, trigger_type: string, trigger_config: array, nodes: array, edges: array}
     *
     * @throws \RuntimeException when the provider fails or returns unusable output
     */
    public function generate(int $workspaceId, string $prompt): array
    {
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => 'Build an automation for this request:'."\n\n".trim($prompt)],
        ];

        $response = $this->llmGateway->chat($workspaceId, $messages, [
            'max_tokens' => 3000,
            'feature_key' => 'automation_workflow_generate',
            'idempotency_key' => request()->header('Idempotency-Key'),
        ]);

        $spec = $this->decode($response->content);
        if (! is_array($spec)) {
            $this->llmGateway->rejectMalformed($response);
            throw new \RuntimeException('The AI did not return a valid workflow. Try rephrasing your request.');
        }

        return $this->normalise($spec);
    }

    private function systemPrompt(): string
    {
        $nodeTypes = implode(', ', self::NODE_TYPES);
        $triggerTypes = implode(', ', self::TRIGGER_TYPES);

        return <<<PROMPT
You are an automation workflow architect for a WhatsApp customer-support platform.
Convert the user's request into ONE automation expressed as strict JSON. Output ONLY the JSON
object — no prose, no markdown, no code fences.

Shape:
{
  "name": "<short title, max 60 chars>",
  "trigger_type": "<one of: {$triggerTypes}>",
  "trigger_config": { },
  "nodes": [ { "id": "n1", "type": "<node type>", "data": { ... } } ],
  "edges": [ { "source": "trigger-1", "target": "n1" }, { "source": "n1", "target": "n2" } ]
}

Rules:
- The trigger is implicit: its node id is always "trigger-1". Do NOT list it in "nodes"; only
  reference it as an edge source. Every "nodes" entry needs a unique "id" and a valid "type".
- The trigger is always a customer sending a WhatsApp message ("message.received"). Build the
  flow as a reply to that message.
- Connect nodes with "edges". The flow must start with an edge whose source is "trigger-1".
- Allowed node types, and no others: {$nodeTypes}.
- Prefer send_whatsapp for replies. Use send_template ONLY when the request names a specific
  approved template, because templates must already be approved by WhatsApp.
- assign_agent hands the chat to a person and ends the automation, so it must be a last step.
- Personalise text with tokens: {{contact.name}}, {{contact.first_name}}, {{contact.phone}},
  {{message.body}}, {{context.<key>}} (answers saved by ask_question).

Node "data" by type:
- send_whatsapp: { "body": "text" }
- send_template: { "template_name": "name", "language": "en", "variables": "one value per line" }
- send_media: { "media_type": "image|video|document", "link": "https://...", "caption": "text" }
- quick_replies: { "body": "text", "buttons": ["Yes","No","Maybe"] }  (max 3 buttons)
- ask_question: { "question": "text", "variable": "snake_case_key", "channel": "whatsapp" }
- condition: { "field": "contact.name|contact.phone|contact.tag|message.body|context.<key>", "operator": "equals|not_equals|contains|not_contains|exists|not_exists", "value": "text" }
  A condition has TWO outgoing edges: one with "sourceHandle": "true" and one with "sourceHandle": "false".
- wait: { "amount": 1, "unit": "minutes|hours|days" }
- add_tag / remove_tag: { "tag": "name" }
- assign_agent: { }

Keep it focused: 2–6 nodes is ideal. Make every message specific and useful.
PROMPT;
    }

    /** Extract a JSON object from the model's reply, tolerating code fences / stray prose. */
    private function decode(string $raw): mixed
    {
        $raw = trim($raw);
        // Strip a leading ```json / ``` fence and a trailing fence if present.
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $raw = preg_replace('/\s*```$/', '', (string) $raw);

        $decoded = json_decode(trim((string) $raw), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Fall back to the outermost {...} block.
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Validate + reshape the model's spec into the exact structure the builder/engine expect.
     *
     * @param  array<string, mixed>  $spec
     * @return array{name: string, trigger_type: string, trigger_config: array, nodes: array, edges: array}
     */
    private function normalise(array $spec): array
    {
        $triggerType = is_string($spec['trigger_type'] ?? null) && in_array($spec['trigger_type'], self::TRIGGER_TYPES, true)
            ? $spec['trigger_type']
            : 'message.received';

        $triggerConfig = is_array($spec['trigger_config'] ?? null) ? $spec['trigger_config'] : [];

        // ── Action nodes ──────────────────────────────────────────────────
        $nodes = [];
        $idMap = [];   // original id → final id
        $used = [];
        $i = 0;

        foreach (($spec['nodes'] ?? []) as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $type = $raw['type'] ?? null;
            if (! is_string($type) || ! in_array($type, self::NODE_TYPES, true)) {
                continue; // skip the trigger or any unknown/hallucinated type
            }
            $origId = isset($raw['id']) ? (string) $raw['id'] : '';
            $id = $this->uniqueId($origId !== '' && $origId !== 'trigger-1' ? $origId : $type.'-'.$i, $used);
            $used[$id] = true;
            if ($origId !== '') {
                $idMap[$origId] = $id;
            }

            $data = is_array($raw['data'] ?? null) ? $raw['data'] : [];
            $data['nodeType'] = $type;
            $data['configured'] = true;

            $nodes[] = ['id' => $id, 'type' => $type, 'data' => $data, 'position' => ['x' => 250, 'y' => 50]];
            $i++;
        }

        // Always seed exactly one trigger node at the top.
        $idMap['trigger-1'] = 'trigger-1';
        array_unshift($nodes, [
            'id' => 'trigger-1',
            'type' => 'trigger',
            'data' => ['triggerType' => $triggerType, 'label' => 'Trigger'],
            'position' => ['x' => 250, 'y' => 50],
        ]);

        $validIds = array_column($nodes, 'id');

        // ── Edges ─────────────────────────────────────────────────────────
        $edges = [];
        $seen = [];
        $e = 0;
        foreach (($spec['edges'] ?? []) as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $source = $idMap[$raw['source'] ?? ''] ?? ($raw['source'] ?? null);
            $target = $idMap[$raw['target'] ?? ''] ?? ($raw['target'] ?? null);
            if (! in_array($source, $validIds, true) || ! in_array($target, $validIds, true) || $source === $target) {
                continue;
            }
            $handle = in_array($raw['sourceHandle'] ?? null, ['true', 'false'], true) ? $raw['sourceHandle'] : null;
            $key = $source.'>'.$target.'>'.($handle ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $edges[] = ['id' => 'e'.$e, 'source' => $source, 'target' => $target, 'sourceHandle' => $handle];
            $e++;
        }

        $edges = $this->ensureConnectivity($nodes, $edges);
        $this->layout($nodes, $edges);

        $name = is_string($spec['name'] ?? null) && trim($spec['name']) !== ''
            ? mb_substr(trim($spec['name']), 0, 120)
            : 'AI Automation';

        return [
            'name' => $name,
            'trigger_type' => $triggerType,
            'trigger_config' => $triggerConfig,
            'nodes' => $nodes,
            'edges' => $edges,
        ];
    }

    private function uniqueId(string $candidate, array $used): string
    {
        $id = $candidate !== '' ? $candidate : 'n';
        $n = 1;
        while (isset($used[$id])) {
            $id = $candidate.'-'.$n++;
        }

        return $id;
    }

    /**
     * Guarantee the trigger reaches the rest of the flow. If the model produced no edge from the
     * trigger, or no edges at all, fall back to a linear chain in node order.
     */
    private function ensureConnectivity(array $nodes, array $edges): array
    {
        $actionIds = array_values(array_filter(array_column($nodes, 'id'), fn ($id) => $id !== 'trigger-1'));
        if (empty($actionIds)) {
            return $edges;
        }

        if (empty($edges)) {
            $chain = array_merge(['trigger-1'], $actionIds);
            $out = [];
            for ($k = 0; $k < count($chain) - 1; $k++) {
                $out[] = ['id' => 'e'.$k, 'source' => $chain[$k], 'target' => $chain[$k + 1], 'sourceHandle' => null];
            }

            return $out;
        }

        $hasTriggerEdge = (bool) array_filter($edges, fn ($e) => $e['source'] === 'trigger-1');
        if (! $hasTriggerEdge) {
            // Connect the trigger to the first node that nothing else points to (a natural entry).
            $targets = array_column($edges, 'target');
            $entry = null;
            foreach ($actionIds as $id) {
                if (! in_array($id, $targets, true)) {
                    $entry = $id;
                    break;
                }
            }
            array_unshift($edges, ['id' => 'e-trigger', 'source' => 'trigger-1', 'target' => $entry ?? $actionIds[0], 'sourceHandle' => null]);
        }

        return $edges;
    }

    /** Layered top-down auto-layout via BFS from the trigger, spreading siblings horizontally. */
    private function layout(array &$nodes, array $edges): void
    {
        $adj = [];
        foreach ($edges as $edge) {
            $adj[$edge['source']][] = $edge['target'];
        }

        $level = ['trigger-1' => 0];
        $queue = ['trigger-1'];
        while ($queue) {
            $cur = array_shift($queue);
            foreach ($adj[$cur] ?? [] as $next) {
                if (! isset($level[$next])) {
                    $level[$next] = $level[$cur] + 1;
                    $queue[] = $next;
                }
            }
        }

        // Any node not reached from the trigger is stacked below everything else.
        $maxLevel = $level ? max($level) : 0;
        foreach ($nodes as $node) {
            if (! isset($level[$node['id']])) {
                $level[$node['id']] = ++$maxLevel;
            }
        }

        $byLevel = [];
        foreach ($level as $id => $lvl) {
            $byLevel[$lvl][] = $id;
        }

        $pos = [];
        foreach ($byLevel as $lvl => $ids) {
            $count = count($ids);
            foreach (array_values($ids) as $idx => $id) {
                $pos[$id] = [
                    'x' => (int) (250 + ($idx - ($count - 1) / 2) * 280),
                    'y' => 50 + $lvl * 150,
                ];
            }
        }

        foreach ($nodes as &$node) {
            if (isset($pos[$node['id']])) {
                $node['position'] = $pos[$node['id']];
            }
        }
        unset($node);
    }
}
