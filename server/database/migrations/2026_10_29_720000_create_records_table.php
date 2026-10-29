<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The records journal (docs/PLAN.md, D39): one line per fact, kept for good. `ref` names the fact, so
        // a check that runs again finds it, even one the user removed (soft deleted) from the journal.
        Schema::create('records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('subject', 32)->default('');
            $table->string('ref', 96);
            $table->bigInteger('value')->nullable();
            $table->date('date');
            $table->json('payload')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'ref']);
            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('records');
    }
};
