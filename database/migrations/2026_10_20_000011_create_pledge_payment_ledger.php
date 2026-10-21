<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('events', 'payment_receipt_enabled')) {
            Schema::table('events', function (Blueprint $table) {
                $table->boolean('payment_receipt_enabled')->default(false);
                $table->string('payment_receipt_mode')->default('manual');
                $table->boolean('payment_receipt_email')->default(true);
                $table->boolean('payment_receipt_sms')->default(false);
                $table->string('payment_receipt_email_subject')->nullable();
                $table->text('payment_receipt_email_message')->nullable();
                $table->text('payment_receipt_sms_message')->nullable();
            });
        }

        if (! Schema::hasTable('pledge_payments')) {
            Schema::create('pledge_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pledge_id')->constrained()->cascadeOnDelete();
                $table->decimal('amount', 14, 2);
                $table->date('payment_date');
                $table->string('payment_method')->nullable();
                $table->string('reference')->nullable();
                $table->text('note')->nullable();
                $table->boolean('overpayment_override')->default(false);
                $table->text('overpayment_reason')->nullable();
                $table->string('status')->default('recorded');
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('voided_at')->nullable();
                $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('void_reason')->nullable();
                $table->timestamps();
                $table->index(['pledge_id', 'status']);
            });
        }

        if (! Schema::hasColumn('notification_deliveries', 'deduplication_key')) {
            Schema::table('notification_deliveries', function (Blueprint $table) {
                // MySQL needs a separate supporting index before the old unique index
                // can be replaced because invitee_id is also a foreign key.
                $table->index('invitee_id', 'notification_deliveries_invitee_fk_index');
                $table->dropUnique(['invitee_id', 'type', 'channel']);
                $table->string('deduplication_key')->default('default');
                $table->foreignId('pledge_payment_id')->nullable()->constrained()->cascadeOnDelete();
                $table->unique(['invitee_id', 'type', 'channel', 'deduplication_key'], 'notification_delivery_dedup_unique');
            });
        }

        DB::table('notification_deliveries')->where('type', 'pledge_payment_thanks')->update(['type' => 'pledge_fully_paid_thanks']);
        foreach (DB::table('pledges')->whereIn('status', ['paid', 'confirmed'])->get() as $pledge) {
            DB::table('pledge_payments')->insert([
                'pledge_id' => $pledge->id, 'amount' => $pledge->amount,
                'payment_date' => substr((string) ($pledge->paid_at ?: $pledge->confirmed_at ?: now()), 0, 10),
                'payment_method' => $pledge->payment_method, 'reference' => $pledge->payment_reference,
                'note' => 'Migrated from the previous paid-pledge record.', 'status' => 'recorded',
                'recorded_by' => $pledge->payment_confirmed_by, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('pledges')->where('id', $pledge->id)->update(['status' => 'paid']);
        }
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropUnique('notification_delivery_dedup_unique');
            $table->dropConstrainedForeignId('pledge_payment_id');
            $table->dropColumn('deduplication_key');
            $table->unique(['invitee_id', 'type', 'channel']);
            $table->dropIndex('notification_deliveries_invitee_fk_index');
        });
        Schema::dropIfExists('pledge_payments');
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn(['payment_receipt_enabled', 'payment_receipt_mode', 'payment_receipt_email', 'payment_receipt_sms', 'payment_receipt_email_subject', 'payment_receipt_email_message', 'payment_receipt_sms_message']));
    }
};
