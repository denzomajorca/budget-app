<?php

use App\Support\Transfers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // The other side of a transfer between two of your accounts.
            $table->foreignId('transfer_id')->nullable()->after('category_id')->constrained('transactions')->nullOnDelete();
        });

        // Categories named after accounts ("Checking", "Credit Cards", ...) came
        // from bank files and are really transfers; income-like ones are income.
        Transfers::fixCategories();
        DB::table('categories')->whereNull('parent_id')->where('kind', 'expense')
            ->where(fn ($q) => $q->where('name', 'like', '%income%')->orWhere('name', 'like', '%paycheck%')
                ->orWhere('name', 'like', '%payroll%')->orWhere('name', 'like', '%salary%'))
            ->get(['id'])->each(function ($c) {
                DB::table('categories')->where('id', $c->id)->orWhere('parent_id', $c->id)->update(['kind' => 'income']);
            });
        if (! DB::table('categories')->whereNull('parent_id')->where('kind', 'income')->exists()) {
            DB::table('categories')->insert(['name' => 'Income', 'kind' => 'income', 'created_at' => now(), 'updated_at' => now()]);
        }
        Transfers::pair();
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transfer_id');
        });
    }
};
