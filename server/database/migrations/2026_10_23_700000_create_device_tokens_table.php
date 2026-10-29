<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Phones that take push notifications (docs/PLAN.md, D31).
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The sign-in the phone holds: signing out deletes that token, and the phone's pushes go with it
            $table->foreignId('personal_access_token_id')->nullable()->constrained()->cascadeOnDelete();
            // The Firebase registration token, opaque
            $table->string('token', 512)->unique();
            // android (iphone later)
            $table->string('platform', 16)->default('android');
            $table->string('device_name', 100)->nullable();
            // The app sends its token on every start: a phone not seen for two months is forgotten
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
