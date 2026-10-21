<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // sha256 of the code in the t.me deep link
            $table->string('code_hash', 64)->unique();
            // sha256 of the secret only the requesting app knows; it is needed to collect the token
            $table->string('secret_hash', 64);
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('telegram_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('device_name', 120);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_requests');
    }
};
