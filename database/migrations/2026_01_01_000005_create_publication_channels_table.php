<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 每次发布在每个渠道上的一条回执记录。失败可单独重试（attempts 累加）。
        Schema::create('publication_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publication_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20); // website=官网 | screen=店内屏 | delivery=外卖平台
            // pending=等待回执 | success=已同步 | failed=失败可重试 | stale_ignored=晚到回执已忽略
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error')->nullable();
            $table->dateTime('deliver_after')->nullable(); // 模拟延迟回执的到达时间
            $table->dateTime('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['publication_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_channels');
    }
};
