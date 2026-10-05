<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 每一次“临时调整意图”都是一个新版本行；同一逻辑调整的各版本共享 group_id。
        // 旧版本永不删除，只流转状态（approved -> superseded / expired）。
        Schema::create('adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->uuid('group_id')->index();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('supersedes_id')->nullable()->constrained('adjustments')->nullOnDelete();
            $table->string('type', 20); // closure 临时停业 | extended 延长营业 | modified 自定义时段
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('open_time', 5)->nullable();   // closure 时为空
            $table->string('close_time', 5)->nullable();
            $table->text('reason')->nullable();
            // pending 审批中 | approved 已批准(生效中) | rejected 已驳回
            // superseded 被新版本取代 | expired 已到期恢复
            $table->string('status', 20)->default('pending')->index();
            $table->string('submitted_by')->nullable();
            $table->string('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'version']);
            $table->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adjustments');
    }
};
