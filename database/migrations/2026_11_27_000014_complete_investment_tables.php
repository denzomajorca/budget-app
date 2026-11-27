<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The investment tables grew while step 7 was being finished. A copy
     * installed from an earlier step 7 zip already ran 000013 without the
     * later columns, so this adds whatever is missing. On a fresh install
     * everything already exists and nothing happens.
     */
    public function up(): void
    {
        $add = function (string $table, string $column, callable $define) {
            if (! Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $t) => $define($t));
            }
        };
        $add('investment_accounts', 'started_empty', fn (Blueprint $t) => $t->boolean('started_empty')->default(false));
        $add('investment_accounts', 'target_stock_share', fn (Blueprint $t) => $t->unsignedTinyInteger('target_stock_share')->nullable());
        $add('investment_balances', 'loan_balance', fn (Blueprint $t) => $t->bigInteger('loan_balance')->nullable());
        $add('investment_balances', 'money_in', fn (Blueprint $t) => $t->bigInteger('money_in')->nullable());

        if (! Schema::hasTable('investment_funds')) {
            Schema::create('investment_funds', function (Blueprint $table) {
                $table->id();
                $table->string('key', 150)->unique();
                $table->string('asset_class', 20);
                $table->unsignedTinyInteger('stock_share')->default(100);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Nothing to undo: 000013 owns these tables and columns.
    }
};
