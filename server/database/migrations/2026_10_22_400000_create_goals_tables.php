<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // save: collect a sum; buy: collect, then spend it; debt: pay a debt off; other: no money
            $table->string('type', 5);
            $table->string('title', 80);
            $table->string('icon', 30)->nullable();
            // Money goals: the sum in minor units of `currency` (D8); for a debt, the debt when the goal was set
            $table->bigInteger('target_amount')->nullable();
            $table->string('currency', 3)->nullable();
            // The goal's jar (save, buy) or the debt account; the money stays there if the goal is deleted
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            // What the jar held (or how much of the debt was paid) when the goal was set: the line «в графике» starts there
            $table->bigInteger('start_amount')->default(0);
            $table->date('deadline')->nullable();
            // Chosen contribution a month, minor units; with a deadline it only counts when it is more than the deadline needs
            $table->bigInteger('monthly_amount')->nullable();
            // Debt: yearly interest, percent
            $table->decimal('rate', 6, 2)->nullable();
            // Goals without money measured by a number: from 85 to 80 kg
            $table->string('unit', 20)->nullable();
            $table->decimal('start_value', 14, 2)->nullable();
            $table->decimal('target_value', 14, 2)->nullable();
            // Order on «Цели»; the first active goal is the main one
            $table->unsignedSmallInteger('sort')->default(0);
            // The plan counts from here: a goal set mid-period takes only the rest of that period
            $table->date('started_on');
            $table->timestamp('done_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('goal_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('value', 14, 2);
            $table->timestamps();

            $table->unique(['goal_id', 'date']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            // A step towards a goal
            $table->foreignId('goal_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        Schema::table('habits', function (Blueprint $table) {
            $table->foreignId('goal_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            // A money habit: what a kept day saves («день без доставки» = 45 000), in som
            $table->bigInteger('saves_uzs')->nullable()->after('unit');
        });
    }

    public function down(): void
    {
        Schema::table('habits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('goal_id');
            $table->dropColumn('saves_uzs');
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('goal_id');
        });
        Schema::dropIfExists('goal_checkins');
        Schema::dropIfExists('goals');
    }
};
