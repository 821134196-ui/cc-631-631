<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 每个门店 × 渠道：该渠道“此刻对外显示什么”。只有被采纳的回执才能改这张表。
        Schema::create('channel_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 30);
            $table->foreignId('adjustment_id')->nullable()->constrained()->nullOnDelete(); // null = 显示常规营业时间
            $table->json('payload')->nullable(); // 渠道当前展示内容快照
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_states');
    }
};
