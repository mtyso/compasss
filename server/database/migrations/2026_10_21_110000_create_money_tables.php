<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('type', 16);
            $table->string('currency', 3);
            // Minor units of the account currency, like every amount in this schema (D8)
            $table->bigInteger('opening_balance')->default(0);
            // Savings and the safety cushion are kept out of the money the advisor lets you spend
            $table->boolean('spendable')->default(true);
            $table->boolean('is_cushion')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->cascadeOnDelete();
            $table->string('kind', 8);
            // Standard categories are named by key and translated; custom ones keep the typed name (D10)
            $table->string('system_key', 40)->nullable();
            $table->string('name', 60)->nullable();
            $table->string('icon', 32)->nullable();
            $table->string('color', 16)->nullable();
            $table->string('necessity', 10)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'system_key']);
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('currency', 3);
            $table->date('date');
            // Som per one unit of the currency
            $table->decimal('rate', 18, 4);
            $table->string('source', 8)->default('cbu');
            $table->timestamps();

            $table->unique(['currency', 'date']);
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 8);
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('to_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->bigInteger('amount');
            $table->string('currency', 3);
            $table->bigInteger('to_amount')->nullable();
            $table->decimal('rate', 18, 4);
            // The amount in som at the rate of the day: totals and budgets add these up (D8)
            $table->bigInteger('amount_uzs');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->dateTime('occurred_at');
            $table->boolean('is_one_off')->default(false);
            $table->string('source', 8)->default('app');
            $table->unsignedBigInteger('recurring_payment_id')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'occurred_at']);
            $table->index(['user_id', 'category_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('accounts');
    }
};
