<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('notes')->nullable();
            // No date means «когда-нибудь»; a repeating task always has one
            $table->date('due_on')->nullable();
            $table->string('due_time', 5)->nullable();
            // 0 обычная, 1 важная, 2 срочная
            $table->unsignedTinyInteger('priority')->default(0);
            // {"unit": "day|week|month|year", "every": 1, "days": [1, 3, 5]}; completing moves due_on ahead
            $table->json('repeat')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'done_at', 'due_on']);
        });

        Schema::create('habits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            // check: done or not; count: reach the target; limit: stay at or under it
            $table->string('type', 5);
            $table->unsignedInteger('target')->default(1);
            $table->string('unit', 20)->nullable();
            // {"days": [1..7]} (ISO weekdays) or {"per_week": 3}
            $table->json('schedule');
            $table->string('color', 20)->default('green');
            $table->string('icon', 30)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->date('started_on');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('habit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('habit_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('value');
            $table->timestamps();

            $table->unique(['habit_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('habit_logs');
        Schema::dropIfExists('habits');
        Schema::dropIfExists('tasks');
    }
};
