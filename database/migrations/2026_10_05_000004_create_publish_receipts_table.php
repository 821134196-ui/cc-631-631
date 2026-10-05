<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 每次渠道回执（含迟到回执）都追加一条日志，绝不更新历史行。
        // accepted=false 表示回执到达时对应版本已不是最新，未改写渠道当前状态。
        Schema::create('publish_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_publish_id')->constrained()->cascadeOnDelete();
            $table->foreignId('adjustment_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 30);
            $table->unsignedInteger('version');          // 回执对应的调整版本号（快照）
            $table->unsignedInteger('attempt')->default(1);
            $table->string('result', 10);                // success | failed
            $table->json('payload')->nullable();         // 渠道回执原文
            $table->boolean('accepted')->default(false); // 是否被采纳并改写渠道当前状态
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['adjustment_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publish_receipts');
    }
};
