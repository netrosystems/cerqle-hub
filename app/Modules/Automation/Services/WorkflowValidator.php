<?php

namespace App\Modules\Automation\Services;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Modules\Whatsapp\Services\CloudApiClient;
use Illuminate\Validation\ValidationException;

class WorkflowValidator
{
    public const TYPES = ['send_whatsapp', 'send_template', 'send_media', 'quick_replies', 'ask_question', 'condition', 'wait', 'add_tag', 'remove_tag', 'assign_agent'];

    /** @param list<array<string, mixed>> $nodes
     * @param  list<array<string, mixed>>  $edges
     * @return array<string, string>
     */
    public function errors(array $nodes, array $edges, bool $complete = true): array
    {
        $errors = [];
        $ids = [];
        foreach ($nodes as $node) {
            $id = $node['id'] ?? '';
            if (! is_string($id) || $id === '' || strlen($id) > 64 || isset($ids[$id])) {
                $errors['nodes'] = 'Every node needs a unique ID of at most 64 characters.';

                continue;
            }
            $ids[$id] = $node;
        }
        $out = [];
        foreach ($edges as $edge) {
            $source = $edge['source'] ?? '';
            $target = $edge['target'] ?? '';
            if (! isset($ids[$source], $ids[$target])) {
                $errors['edges'] = 'Remove connections to missing nodes.';

                continue;
            }
            $out[$source][] = $edge;
        }
        $visiting = $done = [];
        $visit = function ($id) use (&$visit, &$visiting, &$done, &$errors, $out): void {
            if (isset($visiting[$id])) {
                $errors['edges'] = 'Loops are not supported.';

                return;
            }
            if (isset($done[$id])) {
                return;
            }
            $visiting[$id] = true;
            foreach ($out[$id] ?? [] as $edge) {
                $visit($edge['target']);
            }
            unset($visiting[$id]);
            $done[$id] = true;
        };
        foreach (array_keys($ids) as $id) {
            $visit($id);
        }
        if (! $complete) {
            return $errors;
        }
        $triggers = 0;
        foreach ($ids as $id => $node) {
            $type = $node['data']['nodeType'] ?? $node['type'] ?? '';
            $data = $node['data'] ?? [];
            foreach (['body', 'question', 'variable', 'unit', 'tag', 'template_name', 'language', 'link', 'media_type', 'field', 'operator', 'channel'] as $field) {
                if (isset($data[$field]) && ! is_string($data[$field])) {
                    $errors['nodes.'.$id] = 'Configuration fields must have valid text values.';

                    continue 2;
                }
            }
            $connections = $out[$id] ?? [];
            $error = null;
            if (in_array($type, ['trigger', 'triggerNode'], true)) {
                $triggers++;
            } elseif (! in_array($type, self::TYPES, true)) {
                $error = 'This legacy node is not available in the initial release.';
            } elseif (in_array($type, ['send_whatsapp', 'quick_replies'], true) && trim($data['body'] ?? '') === '') {
                $error = 'Message text is required.';
            } elseif ($type === 'ask_question' && (trim($data['question'] ?? '') === '' || ! preg_match('/^[a-zA-Z_]\w{0,63}$/', $data['variable'] ?? 'answer'))) {
                $error = 'Enter a question and a valid reply variable.';
            } elseif ($type === 'ask_question' && (isset($data['timeout_hours']) && (filter_var($data['timeout_hours'], FILTER_VALIDATE_INT) === false || $data['timeout_hours'] < 1 || $data['timeout_hours'] > 168))) {
                $error = 'Reply timeout must be 1–168 whole hours.';
            } elseif ($type === 'wait' && (! in_array($data['unit'] ?? 'minutes', ['minutes', 'hours', 'days'], true) || filter_var($data['amount'] ?? 0, FILTER_VALIDATE_INT) === false || (int) ($data['amount'] ?? 0) < 1)) {
                $error = 'Enter a positive whole-number delay.';
            } elseif (in_array($type, ['add_tag', 'remove_tag'], true) && trim($data['tag'] ?? '') === '') {
                $error = 'Tag name is required.';
            } elseif ($type === 'send_template' && trim($data['template_name'] ?? '') === '') {
                $error = 'Choose an approved template.';
            } elseif ($type === 'send_media' && (! filter_var($data['link'] ?? '', FILTER_VALIDATE_URL) || ! str_starts_with($data['link'] ?? '', 'https://') || ! in_array($data['media_type'] ?? 'image', ['image', 'video', 'document'], true))) {
                $error = 'Select image/video/document and a public HTTPS link.';
            }
            if ($type === 'quick_replies') {
                $buttons = array_values(array_filter((array) ($data['buttons'] ?? []), fn ($b) => is_string($b) && trim($b) !== ''));
                if (count($buttons) < 1 || count($buttons) > 3 || count(array_unique($buttons)) !== count($buttons) || collect($buttons)->contains(fn ($b) => mb_strlen($b) > 20)) {
                    $error = 'Enter one to three unique button titles, at most 20 characters each.';
                }
            }
            if (in_array($type, ['wait', 'ask_question', 'quick_replies', 'trigger', 'triggerNode'], true) && count($connections) !== 1) {
                $error = 'Connect this node to exactly one next step.';
            }
            if ($type === 'assign_agent' && count($connections)) {
                $error = 'Human handoff must be the final step.';
            }
            if ($type === 'condition') {
                $handles = array_column($connections, 'sourceHandle');
                sort($handles);
                if ($handles !== ['false', 'true'] || ! preg_match('/^(contact\.(name|email|phone|tag)|message\.body|context\.\w+)$/', $data['field'] ?? '') || ! in_array($data['operator'] ?? 'equals', ['equals', 'not_equals', 'contains', 'not_contains', 'exists', 'not_exists'], true)) {
                    $error = 'Configure a valid condition and connect both Yes and No branches.';
                }
            } elseif (count($connections) > 1) {
                $error = 'Only conditions can have multiple outgoing connections.';
            }
            if (($data['channel'] ?? 'whatsapp') !== 'whatsapp') {
                $error = 'Only WhatsApp is available for new workflows.';
            }
            if ($error) {
                $errors['nodes.'.$id] = $error;
            }
        }
        if ($triggers !== 1) {
            $errors['nodes'] = 'Exactly one trigger is required.';
        }
        $trigger = collect($ids)->first(fn ($n) => in_array($n['type'] ?? '', ['trigger', 'triggerNode'], true));
        if ($trigger) {
            $reachable = [];
            $walk = function ($id) use (&$walk, &$reachable, $out): void {
                if (isset($reachable[$id])) {
                    return;
                } $reachable[$id] = true;
                foreach ($out[$id] ?? [] as $e) {
                    $walk($e['target']);
                }
            };
            $walk($trigger['id']);
            if (count($reachable) !== count($ids)) {
                $errors['edges'] = 'Every node must be reachable from the trigger.';
            }
            if (collect($edges)->contains(fn ($e) => ($e['target'] ?? '') === $trigger['id'])) {
                $errors['edges'] = 'The trigger cannot have incoming connections.';
            }
        }

        return $errors;
    }

    /** @param array<string, mixed> $workflow */
    public function validate(int $workspaceId, array $workflow, bool $complete): void
    {
        $errors = $this->errors($workflow['nodes'] ?? [], $workflow['edges'] ?? [], $complete);
        if ($complete) {
            if (($workflow['trigger_type'] ?? '') !== 'message.received') {
                $errors['trigger_type'] = 'The initial release supports Message Received only.';
            }
            $account = ChannelAccount::where('workspace_id', $workspaceId)->where('channel', 'whatsapp')->where('status', 'active')->find($workflow['trigger_config']['channel_account_id'] ?? 0);
            if (! $account) {
                $errors['trigger_config'] = 'Choose an active WhatsApp sending account.';
            }
            if ($account) {
                $waba = WhatsappBusinessAccount::where('workspace_id', $workspaceId)->where('status', 'active')->where('waba_id', $account->business_account_id)->first();
                $phone = $waba ? WhatsappPhoneNumber::where('waba_id_fk', $waba->id)->where('phone_number_id', $account->phone_number_id)->first() : null;
                if (! $phone || data_get($phone->coexistence_meta, 'disconnected_at') || ! CloudApiClient::forPhoneNumber($account->phone_number_id, $workspaceId)) {
                    $errors['trigger_config'] = 'Selected WABA/phone is disconnected or lacks credentials.';
                }
            }
            foreach ($workflow['nodes'] ?? [] as $node) {
                if (($node['type'] ?? '') !== 'send_template') {
                    continue;
                }
                $data = $node['data'] ?? [];
                $template = WhatsappTemplate::where('workspace_id', $workspaceId)->where('waba_id', $account?->business_account_id)->where('status', 'APPROVED')->where('name', $data['template_name'] ?? '')->where('language', $data['language'] ?? 'en')->first();
                if (! $template) {
                    $errors['nodes.'.$node['id']] = 'Template must be approved and belong to the selected WABA.';

                    continue;
                }
                $requirements = [];
                foreach ($template->components ?? [] as $component) {
                    if (strtoupper($component['type'] ?? '') === 'BODY') {
                        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $component['text'] ?? '', $matches);
                        $requirements = $matches[1];
                        if (preg_match('/\{\{\s*[^\d\s}][^}]*\}\}/', $component['text'] ?? '')) {
                            $errors['nodes.'.$node['id']] = 'Named template parameters are not supported in the initial release.';
                        }
                    }
                    if (strtoupper($component['type'] ?? '') !== 'BODY' && (str_contains(json_encode($component), '{{') || in_array(strtoupper($component['format'] ?? ''), ['IMAGE', 'VIDEO', 'DOCUMENT'], true) || str_contains(json_encode($component), 'VOICE_CALL') || str_contains(json_encode($component), 'COPY_CODE'))) {
                        $errors['nodes.'.$node['id']] = 'This template requires unsupported header/button parameters. Choose a body-only template.';
                    }
                }
                $variables = (array) ($data['variables'] ?? []);
                if (count($variables) !== count(array_unique($requirements)) || collect($variables)->contains(fn ($v) => ! is_string($v) || trim($v) === '')) {
                    $errors['nodes.'.$node['id']] = 'Complete all template body variables.';
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
