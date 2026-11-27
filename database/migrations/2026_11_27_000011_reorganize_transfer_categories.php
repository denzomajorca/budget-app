<?php

use App\Support\TransferCategories;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Transfer › Credit Card Payment and Transfer › Savings; Room2Go becomes spending. */
    public function up(): void
    {
        TransferCategories::reorganize();
    }

    public function down(): void
    {
        // The old groups came from bank files and are not brought back.
    }
};
