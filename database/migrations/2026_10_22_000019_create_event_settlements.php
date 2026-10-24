<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void { Schema::create('event_settlements', function (Blueprint $table) { $table->id(); $table->foreignId('event_id')->constrained()->cascadeOnDelete(); $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete(); $table->string('reference')->unique(); $table->string('callback_token',32)->unique(); $table->decimal('amount',14,2); $table->string('currency',3)->default('TZS'); $table->string('payment_type'); $table->string('payment_number',50); $table->string('status')->default('initiated'); $table->string('provider_reference')->nullable(); $table->text('remarks')->nullable(); $table->json('request_response')->nullable(); $table->json('callback_payload')->nullable(); $table->timestamp('requested_at')->nullable(); $table->timestamp('paid_at')->nullable(); $table->timestamp('failed_at')->nullable(); $table->timestamps(); $table->index(['event_id','status']); }); }
    public function down(): void { Schema::dropIfExists('event_settlements'); }
};
