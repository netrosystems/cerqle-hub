<?php

namespace App\Http\Resources\Api\V1;

use App\Modules\Inbox\Services\MessageMediaResolver;
use App\Support\Demo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $mediaResolver = app(MessageMediaResolver::class);
        $payload = $mediaResolver->augmentPayload(
            $this->resource,
            $request,
            'api.v1.mobile.conversations.messages.media.signed',
        );

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'direction' => $this->direction,
            'channel' => $this->channel,
            'type' => $this->type,
            'body' => Demo::text($mediaResolver->displayBody($this->resource)),
            'payload' => $payload,
            'status' => $this->status,
            'provider_message_id' => $this->provider_message_id,
            'sent_by' => $this->sent_by,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
