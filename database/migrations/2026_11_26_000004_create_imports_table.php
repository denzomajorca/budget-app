<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('file_name');
            $table->unsignedInteger('rows_imported')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->unsignedInteger('categories_created')->default(0);
            $table->date('first_date')->nullable();
            $table->date('last_date')->nullable();
            $table->timestamps();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('import_id')->nullable()->after('memo')->constrained()->nullOnDelete();
        });

        Schema::table('accounts', function (Blueprint $table) {
            // Column matching and options remembered from the last import.
            $table->json('import_settings')->nullable()->after('is_archived');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', fn (Blueprint $table) => $table->dropColumn('import_settings'));
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_id');
        });
        Schema::dropIfExists('imports');
    }
};
