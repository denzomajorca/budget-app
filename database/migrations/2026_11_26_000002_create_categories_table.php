<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Starter categories, named like the ones in DC's bank downloads
     * ("Food and Dining:Groceries"). Imports add any that are missing.
     */
    private const DEFAULTS = [
        'Food and Dining' => ['Groceries', 'Restaurants', 'Food Delivery', 'Coffee Shops'],
        'Home' => ['Mortgage', 'Property Tax', 'Maintenance', 'Furnishings'],
        'Utilities' => ['Electric', 'Water and Sewer', 'Internet', 'Mobile Phone'],
        'Insurance' => ['Home Insurance', 'Auto Insurance', 'Health Insurance'],
        'Auto and Transport' => ['Gas', 'Parking', 'Service and Parts'],
        'Travel' => ['Airfare', 'Hotel', 'Rental Car'],
        'Shopping' => ['Clothing', 'Shopping Home', 'Electronics'],
        'Subscriptions' => ['Streaming', 'Software'],
        'Health' => ['Doctor', 'Pharmacy'],
        'Entertainment' => [],
        'Gifts and Donations' => ['Charity', 'Gifts'],
        'Personal Care' => [],
        'Fees and Charges' => [],
        'Taxes' => [],
        'Income' => ['Paycheck', 'Interest', 'Refunds'],
        'Transfer' => ['Credit Card Payment'],
    ];

    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name');
            // expense, income or transfer; subcategories follow their main category.
            $table->string('kind', 10)->default('expense');
            $table->timestamps();
            $table->index(['parent_id', 'name']);
        });

        $now = now();
        foreach (self::DEFAULTS as $main => $subs) {
            $kind = match ($main) {
                'Income' => 'income',
                'Transfer' => 'transfer',
                default => 'expense',
            };
            $id = DB::table('categories')->insertGetId(['name' => $main, 'kind' => $kind, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($subs as $sub) {
                DB::table('categories')->insert(['parent_id' => $id, 'name' => $sub, 'kind' => $kind, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
