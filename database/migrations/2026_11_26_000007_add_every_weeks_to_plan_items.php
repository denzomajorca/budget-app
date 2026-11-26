<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Weekly (1) and every-2-weeks (2) bills; every_months stays empty for these.
        Schema::table('plan_items', function (Blueprint $table) {
            $table->unsignedTinyInteger('every_weeks')->nullable()->after('every_months');
        });
    }

    public function down(): void
    {
        Schema::table('plan_items', fn (Blueprint $table) => $table->dropColumn('every_weeks'));
    }
};
