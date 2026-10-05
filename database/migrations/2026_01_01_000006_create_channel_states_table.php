<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 渠道当前真实对外展示的内容（本地模拟官网/店内屏/外卖平台）。
        // applied_epoch 是防旧回执覆盖的闸门：只有 epoch >= applied_epoch 的回执才允许写入。
        Schema::create('channel_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20);
            $table->unsignedBigInteger('applied_epoch')->default(0);
            $table->unsignedBigInteger('applied_publication_id')->nullable();
            $table->json('content')->nullable(); // 渠道当前展示的营业时间说明
            $table->timestamps();

            $table->unique(['store_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_states');
    }
};
