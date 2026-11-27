<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Investment accounts (401(k), IRA, brokerage...) are kept apart from
        // bank accounts: their value comes from statements, not from adding
        // up transactions.
        Schema::create('investment_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('kind', 20); // 401k, ira, roth_ira, brokerage, hsa, other
            $table->string('institution', 100)->nullable();
            // A new account that held nothing before its first deposit, so
            // growth can be worked out from $0 without a starting statement.
            $table->boolean('started_empty')->default(false);
            $table->timestamps();
        });

        // One file brought in, so it can be undone as a whole.
        Schema::create('investment_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investment_account_id')->constrained()->cascadeOnDelete();
            $table->string('file_name');
            $table->string('type', 20); // activity, holdings
            $table->unsignedInteger('added')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->timestamps();
        });

        // Money in and out: contributions, loan repayments, deposits,
        // withdrawals, buys and sells, dividends, fees.
        Schema::create('investment_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investment_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investment_import_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('kind', 20);
            $table->string('description');
            $table->string('fund', 150)->nullable();
            $table->string('symbol', 20)->nullable();
            $table->bigInteger('amount'); // cents; money into the account is positive
            $table->decimal('units', 20, 6)->nullable();
            $table->decimal('price', 18, 6)->nullable();
            $table->string('import_key', 64)->nullable();
            $table->timestamps();
            $table->index(['investment_account_id', 'date']);
            $table->unique(['investment_account_id', 'import_key']);
        });

        // The account's total value on a date, typed from a statement or
        // added up from a holdings file.
        Schema::create('investment_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investment_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investment_import_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->bigInteger('balance'); // cents
            $table->string('source', 20)->default('typed'); // typed, holdings
            $table->timestamps();
            $table->unique(['investment_account_id', 'date']);
        });

        // What the account holds on a date: one row per fund or stock.
        Schema::create('investment_holdings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investment_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investment_import_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('as_of');
            $table->string('symbol', 20)->nullable();
            $table->string('name', 150);
            $table->decimal('quantity', 20, 6)->nullable();
            $table->decimal('price', 18, 6)->nullable();
            $table->bigInteger('value'); // cents
            $table->bigInteger('cost')->nullable(); // cents
            $table->timestamps();
            $table->index(['investment_account_id', 'as_of']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_holdings');
        Schema::dropIfExists('investment_balances');
        Schema::dropIfExists('investment_activities');
        Schema::dropIfExists('investment_imports');
        Schema::dropIfExists('investment_accounts');
    }
};
