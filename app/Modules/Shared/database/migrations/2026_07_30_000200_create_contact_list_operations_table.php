<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_list_operations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('segment_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('type', 32);
            $table->string('status', 20)->default('queued');
            $table->unsignedBigInteger('total')->nullable();
            $table->unsignedBigInteger('processed')->default(0);
            $table->unsignedBigInteger('added')->default(0);
            $table->unsignedBigInteger('updated')->default(0);
            $table->unsignedBigInteger('skipped')->default(0);
            $table->json('options')->nullable();
            $table->string('source_path', 512)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'segment_id', 'created_at'], 'contact_list_ops_lookup');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_list_operations');
    }
};
