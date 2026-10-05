<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 营业时间临时调整申请。延期/提前结束会生成同一 root_id 下的新版本，
        // 旧版本置为 superseded 保留记录，绝不物理删除。
        Schema::create('adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('root_id')->index(); // 版本链根（v1 的 id）
            $table->unsignedInteger('version')->default(1);
            $table->string('type', 20); // closure=临时停业 | extended=延长营业
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->string('open_time', 5)->nullable();  // 仅 extended：窗口期内对外开门时间
            $table->string('close_time', 5)->nullable(); // 仅 extended：窗口期内对外打烊时间
            $table->string('reason', 200);
            // pending=审核中 | approved=已批准(当前生效版本) | rejected=已驳回
            // superseded=已被新版本取代 | expired=已自然到期
            $table->string('status', 20)->default('pending')->index();
            $table->string('submitted_by', 50);
            $table->string('reviewed_by', 50)->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('review_note', 200)->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status']);
            $table->index(['root_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adjustments');
    }
};
