<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('participation_enabled')->default(true);
            $table->boolean('pledge_enabled')->default(false);
            $table->string('pledge_currency', 3)->default('TZS');
            $table->text('pledge_message')->nullable();
            $table->text('payment_details')->nullable();
        });

        Schema::create('pledges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invitee_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();
            $table->text('message')->nullable();
            $table->string('status')->default('pledged');
            $table->timestamp('pledged_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pledges');
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn([
            'participation_enabled', 'pledge_enabled', 'pledge_currency', 'pledge_message', 'payment_details',
        ]));
    }
};
