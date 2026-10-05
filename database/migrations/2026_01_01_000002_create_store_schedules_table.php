<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 门店每周基础营业时间（常规时间表）
        Schema::create('store_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 0=周日 ... 6=周六
            $table->string('open_time', 5)->default('09:00');
            $table->string('close_time', 5)->default('21:00');
            $table->boolean('is_closed')->default(false);
            $table->timestamps();

            $table->unique(['store_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_schedules');
    }
};
