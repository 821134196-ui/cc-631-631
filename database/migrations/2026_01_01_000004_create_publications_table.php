<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 一次发布 = 把一份内容同时发往所有渠道。
        // epoch 单调递增（取本表 id），是"旧回执不得覆盖新内容"的判定依据。
        Schema::create('publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('adjustment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20); // apply=应用调整 | restore=恢复常规
            $table->unsignedBigInteger('epoch')->default(0)->index();
            $table->json('payload'); // 发布内容快照
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publications');
    }
};
