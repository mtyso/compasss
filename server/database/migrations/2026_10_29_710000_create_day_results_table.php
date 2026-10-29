<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A day as the money streaks see it (docs/PLAN.md, D38): its daily limit, written through the day by
        // streaks:close-day because the limit depends on that day's balances, and «Трат не было».
        Schema::create('day_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->bigInteger('limit_uzs')->nullable();
            $table->bigInteger('spent_uzs')->nullable();
            $table->boolean('no_spending')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'date']);
        });

        // What a pay period asked of the goals and what was still due, written through the period;
        // the last write before the next income is the period's result.
        Schema::create('contribution_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->bigInteger('planned_uzs')->default(0);
            $table->bigInteger('due_uzs')->default(0);
            $table->json('goals')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_periods');
        Schema::dropIfExists('day_results');
    }
};
