<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->unsignedBigInteger('joined_user_id')->nullable()->after('assigned_user_id');
            $table->timestamp('joined_at')->nullable()->after('joined_user_id');
            $table->index(['workspace_id', 'joined_user_id']);
        });

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('messages', function (Blueprint $table): void {
                $table->enum('direction', ['in', 'out', 'system'])->change();
                $table->enum('sent_by', ['human', 'bot', 'automation', 'broadcast', 'system'])->default('human')->change();
            });

            return;
        }

        DB::statement("ALTER TABLE messages MODIFY COLUMN direction ENUM('in','out','system') NOT NULL");
        DB::statement("ALTER TABLE messages MODIFY COLUMN sent_by ENUM('human','bot','automation','broadcast','system') NOT NULL DEFAULT 'human'");
    }

    public function down(): void
    {
        if (DB::table('messages')->where('direction', 'system')->exists()) {
            throw new RuntimeException('Remove or migrate conversation activities before rolling back this schema.');
        }
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('messages', function (Blueprint $table): void {
                $table->enum('direction', ['in', 'out'])->change();
                $table->enum('sent_by', ['human', 'bot', 'automation', 'broadcast'])->default('human')->change();
            });
        } else {
            DB::statement("ALTER TABLE messages MODIFY COLUMN direction ENUM('in','out') NOT NULL");
            DB::statement("ALTER TABLE messages MODIFY COLUMN sent_by ENUM('human','bot','automation','broadcast') NOT NULL DEFAULT 'human'");
        }

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'joined_user_id']);
            $table->dropColumn(['joined_user_id', 'joined_at']);
        });
    }
};
