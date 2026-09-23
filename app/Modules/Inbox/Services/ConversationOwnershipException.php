<?php

namespace App\Modules\Inbox\Services;

use RuntimeException;

class ConversationOwnershipException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(string $message, public readonly int $status = 409, public readonly array $context = [])
    {
        parent::__construct($message);
    }

    public function render()
    {
        return response()->json(array_merge([
            'message' => $this->getMessage(),
            'error' => $this->getMessage(),
        ], $this->context), $this->status);
    }
}
