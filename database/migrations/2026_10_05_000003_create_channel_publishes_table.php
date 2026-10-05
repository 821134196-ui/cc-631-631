<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 每个调整版本 × 渠道 一条“当前发布状态”；重试在同一行上累加 attempts。
        Schema::create('channel_publishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adjustment_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 30); // website | screen | delivery
            $table->string('kind', 10)->default('apply'); // apply 发布调整 | restore 到期恢复常规时间
            $table->string('status', 20)->default('pending'); // pending | success | failed | stale
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['adjustment_id', 'channel', 'kind']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_publishes');
    }
};
