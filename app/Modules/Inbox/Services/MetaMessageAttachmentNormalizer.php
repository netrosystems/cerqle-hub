<?php

namespace App\Modules\Inbox\Services;

use Illuminate\Support\Str;

class MetaMessageAttachmentNormalizer
{
    /**
     * @param  array<string, mixed>  $event
     * @return array<int, array{type:string, body:string, payload:array<string, mixed>, provider_message_id:?string}>
     */
    public function messagesFromEvent(array $event, string $channel): array
    {
        $message = is_array($event['message'] ?? null) ? $event['message'] : [];
        $text = trim((string) ($message['text'] ?? ''));
        $mid = isset($message['mid']) ? (string) $message['mid'] : null;
        $attachments = array_values(array_filter(
            is_array($message['attachments'] ?? null) ? $message['attachments'] : [],
            fn ($attachment) => is_array($attachment),
        ));

        if ($attachments === []) {
            return [[
                'type' => 'text',
                'body' => $text,
                'payload' => $event,
                'provider_message_id' => $mid,
            ]];
        }

        return array_map(function (array $attachment, int $index) use ($event, $channel, $text, $mid): array {
            $rawType = strtolower((string) ($attachment['type'] ?? 'file'));
            $type = match ($rawType) {
                'image' => 'image',
                'video' => 'video',
                'audio' => 'audio',
                'file' => 'document',
                'sticker' => 'sticker',
                default => 'unsupported',
            };

            $attachmentPayload = is_array($attachment['payload'] ?? null) ? $attachment['payload'] : [];
            $url = is_string($attachmentPayload['url'] ?? null) ? $attachmentPayload['url'] : null;
            $filename = $this->filename($attachmentPayload, $url, $type);
            $mimeType = $this->mimeType($type, $filename);
            $body = $text !== '' ? $text : $this->fallbackBody($type, $filename);
            $providerId = $mid ? ($index === 0 ? $mid : "{$mid}:{$index}") : null;
            $mediaId = (string) ($attachmentPayload['attachment_id'] ?? $attachmentPayload['id'] ?? '');
            if ($mediaId === '' && $url) {
                $mediaId = hash('sha256', $url);
            }

            $mediaPayload = array_filter([
                'id' => $mediaId ?: null,
                'url' => $url,
                'filename' => $filename,
                'mime_type' => $mimeType,
                'caption' => $text !== '' ? $text : null,
            ], fn ($value) => $value !== null && $value !== '');

            $payload = array_merge($event, [
                '_meta_attachment' => [
                    'provider' => 'meta',
                    'channel' => $channel,
                    'original_type' => $rawType,
                    'index' => $index,
                    'url' => $url,
                ],
                $type => $mediaPayload,
                'filename' => $filename,
                'mime_type' => $mimeType,
                'caption' => $text !== '' ? $text : null,
            ]);

            return [
                'type' => $type,
                'body' => $body,
                'payload' => $payload,
                'provider_message_id' => $providerId,
            ];
        }, $attachments, array_keys($attachments));
    }

    private function fallbackBody(string $type, ?string $filename): string
    {
        return match ($type) {
            'image', 'video', 'audio', 'sticker' => '',
            'document' => $filename ?: 'Document',
            default => 'Unsupported attachment',
        };
    }

    /** @param array<string, mixed> $payload */
    private function filename(array $payload, ?string $url, string $type): ?string
    {
        foreach (['name', 'filename', 'file_name'] as $key) {
            if (is_string($payload[$key] ?? null) && trim($payload[$key]) !== '') {
                return basename(trim($payload[$key]));
            }
        }

        if ($url) {
            $path = parse_url($url, PHP_URL_PATH);
            $basename = is_string($path) ? basename($path) : '';
            if ($basename !== '' && str_contains($basename, '.')) {
                return Str::limit($basename, 120, '');
            }
        }

        return $type === 'document' ? 'attachment' : null;
    }

    private function mimeType(string $type, ?string $filename): ?string
    {
        $extension = strtolower((string) pathinfo((string) $filename, PATHINFO_EXTENSION));

        return match (true) {
            in_array($extension, ['jpg', 'jpeg'], true) => 'image/jpeg',
            $extension === 'png' => 'image/png',
            $extension === 'gif' => 'image/gif',
            $extension === 'webp' => 'image/webp',
            $extension === 'mp4' => 'video/mp4',
            $extension === 'mov' => 'video/quicktime',
            $extension === 'mp3' => 'audio/mpeg',
            $extension === 'm4a' => 'audio/mp4',
            $extension === 'pdf' => 'application/pdf',
            $type === 'image' => 'image/jpeg',
            $type === 'video' => 'video/mp4',
            $type === 'audio' => 'audio/mpeg',
            default => null,
        };
    }
}
