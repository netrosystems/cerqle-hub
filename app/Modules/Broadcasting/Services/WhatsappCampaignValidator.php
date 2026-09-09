<?php

namespace App\Modules\Broadcasting\Services;

use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Modules\Whatsapp\Services\CloudApiClient;
use Illuminate\Validation\ValidationException;

class WhatsappCampaignValidator
{
    /** @return array{waba: WhatsappBusinessAccount, phone: WhatsappPhoneNumber, channel: ChannelAccount, template: WhatsappTemplate, client: CloudApiClient} */
    public function validate(Campaign $campaign): array
    {
        return $this->validateSelection($campaign->workspace_id, [
            'whatsapp_waba_id' => $campaign->whatsapp_waba_id,
            'whatsapp_phone_number_id' => $campaign->whatsapp_phone_number_id,
            'template_ref' => $campaign->template_ref,
        ]);
    }

    /** @return array{waba: WhatsappBusinessAccount, phone: WhatsappPhoneNumber, channel: ChannelAccount, template: WhatsappTemplate, client: CloudApiClient} */
    public function validateSelection(int $workspaceId, array $data): array
    {
        $wabaId = trim((string) ($data['whatsapp_waba_id'] ?? ''));
        $phoneId = trim((string) ($data['whatsapp_phone_number_id'] ?? ''));
        if ($wabaId === '' || $phoneId === '') {
            throw ValidationException::withMessages([
                'whatsapp_waba_id' => 'Choose a WhatsApp Business Account and sending phone number.',
            ]);
        }

        $waba = WhatsappBusinessAccount::where('workspace_id', $workspaceId)
            ->where('waba_id', $wabaId)->where('status', 'active')->first();
        if (! $waba) {
            throw ValidationException::withMessages(['whatsapp_waba_id' => 'The selected WhatsApp Business Account is not active in this workspace.']);
        }

        $phone = WhatsappPhoneNumber::where('waba_id_fk', $waba->id)
            ->where('phone_number_id', $phoneId)->first();
        if (! $phone || filled(data_get($phone->coexistence_meta, 'disconnected_at'))) {
            throw ValidationException::withMessages(['whatsapp_phone_number_id' => 'The selected WhatsApp phone is unavailable or disconnected.']);
        }

        $channel = ChannelAccount::where('workspace_id', $workspaceId)
            ->where('channel', 'whatsapp')->where('status', 'active')
            ->where('phone_number_id', $phoneId)->where('business_account_id', $wabaId)->first();
        if (! $channel) {
            throw ValidationException::withMessages(['whatsapp_phone_number_id' => 'The selected WhatsApp phone does not have an active channel connection.']);
        }

        $reference = is_array($data['template_ref'] ?? null) ? $data['template_ref'] : [];
        $name = trim((string) ($reference['name'] ?? ''));
        $language = trim((string) ($reference['language'] ?? 'en')) ?: 'en';
        $template = WhatsappTemplate::where('workspace_id', $workspaceId)
            ->where('waba_id', $wabaId)->where('name', $name)
            ->where('language', $language)->where('status', 'APPROVED')->first();
        if (! $template) {
            throw ValidationException::withMessages(['template_ref' => 'Choose an APPROVED template belonging to the selected WhatsApp Business Account.']);
        }

        $this->validateParameters($template, (array) ($reference['components'] ?? []));
        $client = CloudApiClient::forPhoneNumber($phoneId, $workspaceId);
        if (! $client) {
            throw ValidationException::withMessages(['whatsapp_phone_number_id' => 'WhatsApp credentials are unavailable for the selected phone.']);
        }

        return compact('waba', 'phone', 'channel', 'template', 'client');
    }

    private function validateParameters(WhatsappTemplate $template, array $submitted): void
    {
        $byType = [];
        foreach ($submitted as $component) {
            if (is_array($component)) {
                $byType[strtolower((string) ($component['type'] ?? ''))][] = $component;
            }
        }

        foreach ((array) $template->components as $component) {
            if (! is_array($component)) {
                continue;
            }
            $type = strtolower((string) ($component['type'] ?? ''));
            if (! in_array($type, ['header', 'body'], true)) {
                continue;
            }
            $expected = preg_match_all('/\{\{\s*\d+\s*\}\}/', (string) ($component['text'] ?? ''));
            $format = strtoupper((string) ($component['format'] ?? ''));
            if ($type === 'header' && in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
                $expected = 1;
            }
            if ($expected === 0) {
                continue;
            }
            $parameters = (array) data_get($byType, $type.'.0.parameters', []);
            if (count($parameters) !== $expected) {
                throw ValidationException::withMessages(['template_ref' => "Complete every {$type} parameter required by the selected template."]);
            }
            foreach ($parameters as $parameter) {
                $value = data_get($parameter, 'text')
                    ?? data_get($parameter, 'image.link') ?? data_get($parameter, 'video.link') ?? data_get($parameter, 'document.link');
                if (! is_string($value) || trim($value) === '') {
                    throw ValidationException::withMessages(['template_ref' => 'Template parameter values cannot be empty.']);
                }
                if ($type === 'header' && in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'], true) && ! str_starts_with($value, 'https://')) {
                    throw ValidationException::withMessages(['template_ref' => 'WhatsApp template media must use a public HTTPS URL.']);
                }
            }
        }

        $submittedButtons = $byType['button'] ?? [];
        foreach ((array) $template->components as $component) {
            if (! is_array($component) || strtoupper((string) ($component['type'] ?? '')) !== 'BUTTONS') {
                continue;
            }
            foreach ((array) ($component['buttons'] ?? []) as $index => $button) {
                $buttonType = strtoupper((string) ($button['type'] ?? ''));
                $dynamic = ($buttonType === 'URL' && str_contains((string) ($button['url'] ?? ''), '{{')) || $buttonType === 'COPY_CODE';
                if (! $dynamic) {
                    continue;
                }
                $provided = collect($submittedButtons)->first(fn ($item) => (string) ($item['index'] ?? '') === (string) $index);
                $value = data_get($provided, 'parameters.0.text');
                if (! is_string($value) || trim($value) === '') {
                    throw ValidationException::withMessages(['template_ref' => 'Complete every dynamic button parameter required by the selected template.']);
                }
            }
        }
    }
}
