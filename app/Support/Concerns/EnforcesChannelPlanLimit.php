<?php

namespace App\Support\Concerns;

use App\Services\ChannelPlanLimitService;

trait EnforcesChannelPlanLimit
{
    public function save(array $options = [])
    {
        $key = $this->channelPlanLimitKey();

        return $key === null ? parent::save($options)
            : app(ChannelPlanLimitService::class)->save($this, $key, fn () => parent::save($options));
    }
}
