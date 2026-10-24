<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_access_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invitee_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('channel');
            $table->string('destination');
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('delivery_status')->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['event_id', 'invitee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_access_challenges');
    }
};
