<?php

namespace App\Modules\Whatsapp\Jobs;

use App\Events\MessageSent;
use App\Modules\Whatsapp\Models\WhatsappEchoReceipt;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Services\CoexistenceMessageStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

class ProcessCoexistenceEchoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public readonly int $receiptId)
    {
        $this->onQueue('whatsapp');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(CoexistenceMessageStore $store): void
    {
        $message = DB::transaction(function () use ($store) {
            $receipt = WhatsappEchoReceipt::whereKey($this->receiptId)->lockForUpdate()->first();
            if (! $receipt || $receipt->status !== 'pending') {
                return null;
            }
            $phone = WhatsappPhoneNumber::whereKey($receipt->phone_id)
                ->where('connection_mode', 'coexistence')
                ->whereHas('businessAccount', fn ($query) => $query->where('workspace_id', $receipt->workspace_id))
                ->first();
            $message = $phone ? $store->store($phone->businessAccount->waba_id,
                $phone->phone_number_id, $receipt->payload ?? [], false) : null;
            // Raw receipt contents are removed after processing, including rejected data.
            $receipt->update(['payload' => null, 'status' => $message ? 'done' : 'ignored']);

            return $message;
        });
        if ($message && $message->origin === 'whatsapp_business_app') {
            MessageSent::dispatch($message);
        }
    }
}
