<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('attendance_thanks_enabled')->default(false);
            $table->string('attendance_thanks_mode')->default('manual');
            $table->boolean('attendance_thanks_email')->default(true);
            $table->boolean('attendance_thanks_sms')->default(false);
            $table->string('attendance_email_subject')->nullable();
            $table->text('attendance_email_message')->nullable();
            $table->text('attendance_sms_message')->nullable();
            $table->boolean('pledge_thanks_enabled')->default(false);
            $table->string('pledge_thanks_mode')->default('manual');
            $table->boolean('pledge_thanks_email')->default(true);
            $table->boolean('pledge_thanks_sms')->default(false);
            $table->string('pledge_email_subject')->nullable();
            $table->text('pledge_email_message')->nullable();
            $table->text('pledge_sms_message')->nullable();
        });

        Schema::table('invitees', function (Blueprint $table) {
            $table->string('attendance_status')->default('pending');
            $table->unsignedTinyInteger('attended_count')->default(0);
            $table->timestamp('attendance_confirmed_at')->nullable();
            $table->foreignId('attendance_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('pledges', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('payment_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
        });
        DB::table('pledges')->where('status', 'confirmed')->update(['status' => 'paid', 'paid_at' => DB::raw('confirmed_at')]);

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invitee_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('channel');
            $table->string('status')->default('queued');
            $table->string('provider_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['invitee_id', 'type', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::table('pledges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_confirmed_by');
            $table->dropColumn('paid_at');
        });
        Schema::table('invitees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attendance_confirmed_by');
            $table->dropColumn(['attendance_status', 'attended_count', 'attendance_confirmed_at']);
        });
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn([
            'attendance_thanks_enabled', 'attendance_thanks_mode', 'attendance_thanks_email', 'attendance_thanks_sms', 'attendance_email_subject', 'attendance_email_message', 'attendance_sms_message',
            'pledge_thanks_enabled', 'pledge_thanks_mode', 'pledge_thanks_email', 'pledge_thanks_sms', 'pledge_email_subject', 'pledge_email_message', 'pledge_sms_message',
        ]));
    }
};
