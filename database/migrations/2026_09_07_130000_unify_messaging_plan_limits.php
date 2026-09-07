<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $seedUsage = ! Schema::hasTable('messaging_quota_periods');
        if ($seedUsage) {
            Schema::create('messaging_quota_periods', function (Blueprint $table) {
                $table->id();
                $table->string('account_key', 64);
                $table->unsignedInteger('period');
                $table->unsignedBigInteger('value')->default(0);
                $table->timestamps();
                $table->unique(['account_key', 'period']);
            });
        }

        DB::table('plans')->orderBy('id')->each(function ($plan) {
            $limits = json_decode($plan->limits ?? '{}', true) ?: [];
            foreach (['messaging_channels' => 'whatsapp_accounts', 'messaging_messages_per_month' => 'whatsapp_messages_per_month'] as $key => $old) {
                if (! array_key_exists($key, $limits)) {
                    $limits[$key] = $limits[$old] ?? null;
                }
            }
            $limits += ['website_widgets' => null, 'whatsapp_chatbots' => null];
            DB::table('plans')->where('id', $plan->id)->update(['limits' => json_encode($limits)]);
        });

        if (! $seedUsage) {
            return;
        }

        // Preserve current-month usage on rollout; old meters only counted campaign WhatsApp sends.
        $period = (int) now()->format('Ym');
        DB::table('workspaces')->orderBy('id')->each(function ($workspace) use ($period) {
            $counts = DB::table('messages')->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
                ->where('conversations.workspace_id', $workspace->id)->where('messages.direction', 'out')
                ->where('messages.created_at', '>=', now()->startOfMonth())->where('messages.created_at', '<', now()->startOfMonth()->addMonth())
                ->whereIn('messages.status', ['sent', 'delivered', 'read'])
                ->whereIn('messages.channel', ['whatsapp', 'messenger', 'instagram'])
                ->groupBy('messages.channel')->selectRaw('messages.channel, COUNT(*) as total')->pluck('total', 'channel');
            $legacy = (int) DB::table('usage_meters')->where('workspace_id', $workspace->id)
                ->where('period', $period)->where('metric', 'whatsapp_messages')->value('value');
            $used = max($legacy, (int) ($counts['whatsapp'] ?? 0)) + (int) ($counts['messenger'] ?? 0) + (int) ($counts['instagram'] ?? 0);
            $accountKey = $workspace->client_id ? 'client:'.$workspace->client_id : 'user:'.$workspace->owner_id;
            DB::table('messaging_quota_periods')->insertOrIgnore([
                'account_key' => $accountKey, 'period' => $period,
                'value' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('messaging_quota_periods')->where('account_key', $accountKey)->where('period', $period)->increment('value', $used);
        });
    }

    public function down(): void
    {
        // Keep plan values and usage history: rolling code back must not erase billing data.
    }
};
