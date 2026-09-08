<?php

namespace App\Modules\Whatsapp\Services;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Whatsapp\Jobs\ProcessCoexistenceEchoJob;
use App\Modules\Whatsapp\Models\WhatsappEchoReceipt;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use Illuminate\Support\Facades\DB;

/** Called only after HMAC verification, before webhook acknowledgement. */
class CoexistenceWebhookIngress
{
    /** @param list<array<string, mixed>> $entries */
    public function capture(array $entries, ?string $requiredWabaId = null): void
    {
        foreach ($entries as $entry) {
            $wabaId = (string) ($entry['id'] ?? '');
            if ($requiredWabaId !== null && $wabaId !== $requiredWabaId) {
                continue;
            }
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? '') === 'account_update'
                    && in_array($change['value']['event'] ?? '', ['PARTNER_REMOVED', 'ACCOUNT_OFFBOARDED'], true)) {
                    $this->disconnect($wabaId);
                }
                if (($change['field'] ?? '') !== 'smb_message_echoes') {
                    continue;
                }
                $value = $change['value'] ?? [];
                $phone = WhatsappPhoneNumber::where('phone_number_id', $value['metadata']['phone_number_id'] ?? '')
                    ->where('connection_mode', 'coexistence')
                    ->whereHas('businessAccount', fn ($query) => $query->where('waba_id', $wabaId))
                    ->first();
                if (! $phone) {
                    continue;
                }
                foreach ($value['message_echoes'] ?? [] as $echo) {
                    if (! is_array($echo) || ! is_string($echo['id'] ?? null) || strlen($echo['id']) > 128) {
                        continue;
                    }
                    $receipt = WhatsappEchoReceipt::firstOrCreate([
                        'event_key' => hash('sha256', $phone->id.':'.$echo['id']),
                    ], [
                        'workspace_id' => $phone->businessAccount->workspace_id, 'phone_id' => $phone->id,
                        'payload' => $echo, 'status' => 'pending',
                    ]);
                    // Replayed pending receipts are dispatched again if a prior queue
                    // connection failed. The job itself is serialized and idempotent.
                    if ($receipt->status === 'pending') {
                        ProcessCoexistenceEchoJob::dispatch($receipt->id);
                    }
                }
            }
        }
    }

    private function disconnect(string $wabaId): void
    {
        DB::transaction(function () use ($wabaId) {
            $phones = WhatsappPhoneNumber::where('connection_mode', 'coexistence')
                ->whereHas('businessAccount', fn ($query) => $query->where('waba_id', $wabaId))
                ->lockForUpdate()->get();
            foreach ($phones as $phone) {
                $phone->update(['coexistence_meta' => array_merge($phone->coexistence_meta ?? [], [
                    'disconnected_at' => now()->toIso8601String(),
                ])]);
                ChannelAccount::where('workspace_id', $phone->businessAccount->workspace_id)
                    ->where('channel', 'whatsapp')->where('business_account_id', $wabaId)
                    ->where('phone_number_id', $phone->phone_number_id)->update(['status' => 'inactive']);
            }
        });
    }
}
