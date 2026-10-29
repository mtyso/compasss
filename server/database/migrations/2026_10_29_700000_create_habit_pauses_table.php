<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A trip or an illness: days in a pause neither break a habit's streak nor add to it
        Schema::create('habit_pauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();

            $table->index(['user_id', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('habit_pauses');
    }
};
