<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

class AiCreditUsage extends Model
{
    protected $fillable = ['period_id', 'workspace_id', 'actor_user_id', 'feature_key', 'rate_version', 'idempotency_key', 'provider_source', 'provider', 'model', 'reserved_credits', 'charged_credits', 'prompt_tokens', 'completion_tokens', 'cost_microusd', 'status', 'error_code', 'result_payload', 'completed_at'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'result_payload' => 'encrypted:array'];
    }
}
