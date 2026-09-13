<?php

namespace App\Notifications\Contracts;

use App\Models\User;

interface WorkspaceWorkNotification
{
    public function notificationWorkspaceId(): ?int;

    public function allowsUnscopedDelivery(): bool;

    public function notificationPreferenceEvent(): string;

    public function initialAvailability(User $user): bool;
}
