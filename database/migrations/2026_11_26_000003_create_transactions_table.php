<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('payee');
            // Cents. Negative = money out, positive = money in.
            $table->bigInteger('amount');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->text('memo')->nullable();
            $table->timestamps();
            $table->index('date');
            $table->index(['account_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
