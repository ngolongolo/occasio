<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('event_type')->default('event')->after('user_id');
            $table->dateTime('ends_at')->nullable()->after('starts_at');
            $table->unsignedInteger('capacity')->nullable()->after('rsvp_deadline');
            $table->string('visibility')->default('invite_only')->after('capacity');
            $table->boolean('plus_ones_allowed')->default(false)->after('visibility');
            $table->text('what_to_bring')->nullable()->after('contact');
            $table->string('participation_payment_timing')->default('free')->after('participation_enabled');
            $table->decimal('participation_price', 14, 2)->nullable()->after('participation_payment_timing');
            $table->string('participation_currency', 3)->default('TZS')->after('participation_price');
            $table->text('participation_payment_details')->nullable()->after('participation_currency');
            $table->string('participation_payment_url')->nullable()->after('participation_payment_details');
            $table->text('cancellation_policy')->nullable()->after('participation_payment_url');
        });
        Schema::table('invitees', function (Blueprint $table) {
            $table->string('participation_payment_status')->default('not_required')->after('rsvp_status');
            $table->string('participation_payment_reference')->nullable()->after('participation_payment_status');
            $table->timestamp('participation_payment_reported_at')->nullable()->after('participation_payment_reference');
        });
    }

    public function down(): void
    {
        Schema::table('invitees', fn (Blueprint $table) => $table->dropColumn(['participation_payment_status', 'participation_payment_reference', 'participation_payment_reported_at']));
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn(['event_type', 'ends_at', 'capacity', 'visibility', 'plus_ones_allowed', 'what_to_bring', 'participation_payment_timing', 'participation_price', 'participation_currency', 'participation_payment_details', 'participation_payment_url', 'cancellation_policy']));
    }
};
