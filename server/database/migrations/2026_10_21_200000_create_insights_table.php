<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the advisor said (docs/PLAN.md, 2.5). Texts are not stored: they are rendered
        // from lang/*/advisor.php in the reader's language, so the app and the bot say the same thing.
        Schema::create('insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('rule', 40);
            // good | info | warn | alert
            $table->string('severity', 10);
            // The period one insight covers (a month, a week, a day, an operation), so it is never repeated within it
            $table->string('period_key', 20);
            // What it is about within the period, such as a category; '' when nothing in particular
            $table->string('subject', 40)->default('');
            $table->json('payload');
            // new | seen | useful | dismissed
            $table->string('status', 10)->default('new');
            // Channels that already delivered it, such as ["telegram"]
            $table->json('sent_via')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'rule', 'period_key', 'subject']);
            $table->index(['user_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insights');
    }
};
