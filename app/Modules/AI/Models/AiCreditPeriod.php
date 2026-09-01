<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiCreditPeriod extends Model
{
    protected $fillable = ['account_type', 'account_id', 'subscription_type', 'subscription_id', 'period_start', 'period_end', 'allowance', 'used_credits', 'reserved_credits'];

    protected function casts(): array
    {
        return ['period_start' => 'datetime', 'period_end' => 'datetime', 'allowance' => 'integer', 'used_credits' => 'integer', 'reserved_credits' => 'integer'];
    }

    public function usages(): HasMany
    {
        return $this->hasMany(AiCreditUsage::class, 'period_id');
    }
}
