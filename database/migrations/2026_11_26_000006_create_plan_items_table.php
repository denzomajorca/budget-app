<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The expense plan: bills on a schedule, everyday spending targets,
        // and planned one-time expenses saved toward.
        Schema::create('plan_items', function (Blueprint $table) {
            $table->id();
            $table->string('group', 10); // bill, flex, goal
            $table->string('name');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount'); // cents: bill amount, monthly target, or goal total
            $table->unsignedTinyInteger('every_months')->nullable(); // bills: 1, 3, 6 or 12
            $table->date('next_due')->nullable(); // bills
            $table->boolean('is_estimate')->default(false); // bills that vary
            $table->bigInteger('saved')->default(0); // goals
            $table->date('due_by')->nullable(); // goals
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_items');
    }
};
