<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // An occurrence of a repeating event edited on its own («Только это»): its series and original date
            $table->foreignId('series_id')->nullable()->constrained('events')->cascadeOnDelete();
            $table->date('series_date')->nullable();
            $table->string('title', 200);
            $table->text('notes')->nullable();
            // Local dates and times (Asia/Tashkent). No times means all day; ends_on >= starts_on (multi-day allowed)
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('start_time', 5)->nullable();
            $table->string('end_time', 5)->nullable();
            $table->string('color', 20)->default('indigo');
            // Same rule as tasks (App\Support\Repeat); occurrences stop after repeat_until, skip_dates are left out
            $table->json('repeat')->nullable();
            $table->date('repeat_until')->nullable();
            $table->json('skip_dates')->nullable();
            // Minutes before the start; an all-day event counts from 09:00 of its day. Null: no reminder
            $table->unsignedInteger('remind_before')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'starts_on']);
            $table->index('series_id');
        });

        Schema::create('routine_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('habit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 60);
            // sleep, work, study, sport, meal, road, rest, family, other: icon and colour in the app
            $table->string('kind', 20);
            $table->string('start_time', 5);
            // Not after start_time means the block ends the next day (sleep 23:00–07:00)
            $table->string('end_time', 5);
            // ISO weekdays the block starts on, e.g. [1,2,3,4,5]
            $table->json('days');
            $table->boolean('remind')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        // Reminders already handed out, so each goes once (App\Calendar\Reminders)
        Schema::create('calendar_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->timestamp('sent_at');

            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_reminders');
        Schema::dropIfExists('routine_blocks');
        Schema::dropIfExists('events');
    }
};
