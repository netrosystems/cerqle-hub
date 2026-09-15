<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecommerce_oauth_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('ecommerce_stores')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
        Schema::table('ai_kb_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('crawl_root_id')->nullable()->index();
            $table->unsignedTinyInteger('sitemap_depth')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecommerce_oauth_attempts');
        Schema::table('ai_kb_documents', function (Blueprint $table) {
            $table->dropColumn(['crawl_root_id', 'sitemap_depth']);
        });
    }
};
