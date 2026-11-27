<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('retirement_plans')) {
            return;
        }
        Schema::create('retirement_plans', function (Blueprint $table) {
            $table->id();
            // Your answers on the Retirement page (birth year, ages, amounts, assumptions).
            $table->json('inputs')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retirement_plans');
    }
};
