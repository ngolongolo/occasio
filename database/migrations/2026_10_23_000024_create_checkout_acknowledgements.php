<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invitee_id')->constrained()->cascadeOnDelete();
            $table->string('version', 20);
            $table->json('snapshot');
            $table->char('snapshot_hash', 64);
            $table->timestamp('acknowledged_at');
            $table->timestamps();

            $table->index(['invitee_id', 'acknowledged_at']);
        });

        Schema::table('participation_payments', function (Blueprint $table) {
            $table->foreignId('checkout_acknowledgement_id')->nullable()->after('invitee_id')
                ->constrained('checkout_acknowledgements')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('participation_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checkout_acknowledgement_id');
        });
        Schema::dropIfExists('checkout_acknowledgements');
    }
};
