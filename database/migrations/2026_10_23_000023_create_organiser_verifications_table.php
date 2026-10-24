<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organiser_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('organiser_type', 24)->default('individual')->index();
            $table->string('verification_level', 24)->default('none')->index();
            $table->string('status', 24)->default('unverified')->index();

            // These are the only organiser-profile fields intended for guest-facing use.
            $table->string('public_name', 180)->nullable();
            $table->string('public_contact_email', 255)->nullable();
            $table->string('public_contact_phone', 32)->nullable();

            // Sensitive review data is encrypted by the model and never included in public summaries.
            $table->text('legal_name')->nullable();
            $table->text('registration_number')->nullable();
            $table->text('tax_identifier')->nullable();
            $table->text('review_notes')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('payout_name_verified_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'verification_level'], 'organiser_verification_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organiser_verifications');
    }
};
