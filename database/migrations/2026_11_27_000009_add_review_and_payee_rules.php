<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Imported rows start "to review" until you check their category.
            $table->boolean('needs_review')->default(false)->after('memo')->index();
        });

        // "Payee contains ..." → category, used on future imports.
        Schema::create('payee_rules', function (Blueprint $table) {
            $table->id();
            $table->string('match', 100)->unique();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        // Everything already imported gets one review pass.
        DB::table('transactions')->whereNotNull('import_id')->update(['needs_review' => true]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payee_rules');
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['needs_review']);
            $table->dropColumn('needs_review');
        });
    }
};
