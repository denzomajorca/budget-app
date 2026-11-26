<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // checking, savings, credit_card, cash, loan, investment, other
            $table->string('type', 20);
            $table->string('institution')->nullable();
            // Money is stored in whole cents to avoid rounding errors.
            $table->bigInteger('opening_balance')->default(0);
            $table->date('opening_date')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
