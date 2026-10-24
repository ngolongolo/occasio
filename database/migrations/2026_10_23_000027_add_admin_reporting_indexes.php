<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->index('starts_at', 'events_admin_starts_at_idx');
            $table->index('created_at', 'events_admin_created_at_idx');
        });
        Schema::table('invitees', function (Blueprint $table): void {
            $table->index(['rsvp_status', 'confirmation_status', 'responded_at'], 'invitees_admin_rsvp_idx');
            $table->index('participation_payment_status', 'invitees_admin_payment_status_idx');
        });
        Schema::table('participation_payments', function (Blueprint $table): void {
            $table->index(['status', 'currency', 'created_at', 'id'], 'participation_payments_admin_ledger_idx');
        });
        Schema::table('event_settlements', function (Blueprint $table): void {
            $table->index(['status', 'currency', 'created_at', 'id'], 'event_settlements_admin_ledger_idx');
        });
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->index(['status', 'updated_at'], 'deliveries_admin_status_idx');
        });
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->index(['status', 'updated_at'], 'notification_deliveries_admin_status_idx');
        });
        Schema::table('organiser_verifications', function (Blueprint $table): void {
            $table->index(['status', 'submitted_at'], 'organiser_verifications_admin_queue_idx');
            $table->index('expires_at', 'organiser_verifications_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropIndex('events_admin_starts_at_idx');
            $table->dropIndex('events_admin_created_at_idx');
        });
        Schema::table('invitees', function (Blueprint $table): void {
            $table->dropIndex('invitees_admin_rsvp_idx');
            $table->dropIndex('invitees_admin_payment_status_idx');
        });
        Schema::table('participation_payments', fn (Blueprint $table) => $table->dropIndex('participation_payments_admin_ledger_idx'));
        Schema::table('event_settlements', fn (Blueprint $table) => $table->dropIndex('event_settlements_admin_ledger_idx'));
        Schema::table('deliveries', fn (Blueprint $table) => $table->dropIndex('deliveries_admin_status_idx'));
        Schema::table('notification_deliveries', fn (Blueprint $table) => $table->dropIndex('notification_deliveries_admin_status_idx'));
        Schema::table('organiser_verifications', function (Blueprint $table): void {
            $table->dropIndex('organiser_verifications_admin_queue_idx');
            $table->dropIndex('organiser_verifications_expiry_idx');
        });
    }
};
