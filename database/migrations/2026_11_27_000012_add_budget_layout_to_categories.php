<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // Budgets page: null = show it when it has a budget or spending; true/false = your choice.
            $table->boolean('budget_hidden')->nullable()->after('kind');
            // Budgets page: your own order (smallest first); null = after the ordered ones, by name.
            $table->unsignedInteger('budget_position')->nullable()->after('budget_hidden');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['budget_hidden', 'budget_position']);
        });
    }
};
