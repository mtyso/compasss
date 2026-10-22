<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Main expense categories only; subcategories count towards their parent
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('month', 7);
            $table->bigInteger('limit_uzs');
            $table->timestamps();

            $table->unique(['user_id', 'month', 'category_id']);
        });

        Schema::create('income_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            // 1–31; a day past the end of a short month means its last day
            $table->unsignedTinyInteger('day_of_month');
            $table->bigInteger('expected_amount');
            $table->string('currency', 3);
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('recurring_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->bigInteger('amount');
            $table->string('currency', 3);
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('day_of_month');
            // The next unpaid due date; paying moves it a month ahead
            $table->date('next_due_on');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_payments');
        Schema::dropIfExists('income_schedules');
        Schema::dropIfExists('budgets');
    }
};
