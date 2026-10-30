<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A prayer marked as prayed (docs/PLAN.md, D40): in its time, or made up later (qaza). Unmarking deletes it.
        Schema::create('prayer_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('prayer', 10);
            $table->string('status', 10);
            $table->timestamps();

            $table->unique(['user_id', 'date', 'prayer']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prayer_logs');
    }
};
