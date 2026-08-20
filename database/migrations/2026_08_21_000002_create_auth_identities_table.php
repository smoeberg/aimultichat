<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('provider'); // e.g. 'entra'
            $table->string('provider_user_id'); // OID or Sub
            $table->timestamp('last_login_at')->nullable();
            $table->json('raw_claims')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_identities');
    }
};
