<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

class AiRun extends Model
{
    protected $table = 'ai_runs';

    protected $fillable = ['workspace_id', 'chatbot_id', 'conversation_id', 'credit_usage_id', 'feature_key', 'provider_source', 'provider', 'prompt_tokens', 'completion_tokens', 'cost_cents', 'cost_microusd', 'latency_ms', 'model', 'status'];
}
