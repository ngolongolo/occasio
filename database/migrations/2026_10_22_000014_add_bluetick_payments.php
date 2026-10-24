<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('participation_collection_method')->default('manual')->after('participation_payment_timing');
            $table->string('payout_payment_type')->nullable()->after('cancellation_policy');
            $table->string('payout_number')->nullable()->after('payout_payment_type');
        });

        Schema::create('participation_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invitee_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->unique();
            $table->string('callback_token', 64)->unique();
            $table->string('msisdn', 15);
            $table->unsignedTinyInteger('network');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('TZS');
            $table->string('status')->default('initiated');
            $table->string('provider_payment_reference')->nullable()->unique();
            $table->date('provider_payment_date')->nullable();
            $table->text('provider_remarks')->nullable();
            $table->json('request_response')->nullable();
            $table->json('callback_payload')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['invitee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participation_payments');
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn(['participation_collection_method', 'payout_payment_type', 'payout_number']));
    }
};
