<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 到期恢复任务：批准调整时按 end_at 生成。
        // 延期后旧任务会被取消；即使漏取消，执行时也会复核调整是否仍是当前有效版本。
        Schema::create('restore_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version'); // 创建时的版本快照
            $table->dateTime('run_at')->index();
            // pending=待执行 | done=已执行恢复 | cancelled_superseded=因延期被取消 | skipped_stale=执行时发现已失效
            $table->string('status', 30)->default('pending');
            $table->dateTime('executed_at')->nullable();
            $table->string('result_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restore_tasks');
    }
};
