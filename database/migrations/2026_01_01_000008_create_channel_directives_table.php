<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 本地渠道模拟的故障/延迟注入指令（演示与测试用）。
        Schema::create('channel_directives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete(); // null=所有门店
            $table->string('channel', 20);
            $table->string('mode', 20); // fail_once=下次失败 | fail_always=一直失败 | delay_once=下次延迟回执
            $table->unsignedInteger('delay_seconds')->default(60);
            $table->string('message')->nullable();
            $table->boolean('consumed')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_directives');
    }
};
