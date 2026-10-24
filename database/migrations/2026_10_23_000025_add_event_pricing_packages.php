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
            $table->string('pricing_mode', 20)->default('single')->after('participation_price');
        });

        Schema::create('event_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->decimal('price', 14, 2);
            $table->string('currency', 3)->default('TZS');
            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedTinyInteger('max_per_registration')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['event_id', 'is_active', 'sort_order']);
        });

        Schema::table('invitees', function (Blueprint $table) {
            $table->foreignId('event_package_id')->nullable()->after('event_id')
                ->constrained('event_packages')->nullOnDelete();
            $table->string('package_name_snapshot', 100)->nullable()->after('event_package_id');
            $table->decimal('package_unit_price_snapshot', 14, 2)->nullable()->after('package_name_snapshot');
            $table->string('package_currency_snapshot', 3)->nullable()->after('package_unit_price_snapshot');
        });

        $now = now();
        DB::table('events')
            ->select(['id', 'participation_price', 'participation_currency', 'plus_ones_allowed', 'max_attendees_per_registration'])
            ->whereIn('participation_payment_timing', ['pay_now', 'pay_at_event'])
            ->whereNotNull('participation_price')
            ->where('participation_price', '>', 0)
            ->orderBy('id')
            ->each(function (object $event) use ($now): void {
                $currency = strtoupper(substr((string) ($event->participation_currency ?: 'TZS'), 0, 3));
                $registrationLimit = $event->plus_ones_allowed
                    ? max(1, min(10, (int) ($event->max_attendees_per_registration ?: 2)))
                    : 1;
                $packageId = DB::table('event_packages')->insertGetId([
                    'event_id' => $event->id,
                    'name' => 'General admission',
                    'description' => null,
                    'price' => $event->participation_price,
                    'currency' => $currency,
                    'capacity' => null,
                    'max_per_registration' => $registrationLimit,
                    'sort_order' => 0,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('invitees')->where('event_id', $event->id)->update([
                    'event_package_id' => $packageId,
                    'package_name_snapshot' => 'General admission',
                    'package_unit_price_snapshot' => $event->participation_price,
                    'package_currency_snapshot' => $currency,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('invitees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_package_id');
            $table->dropColumn([
                'package_name_snapshot',
                'package_unit_price_snapshot',
                'package_currency_snapshot',
            ]);
        });

        Schema::dropIfExists('event_packages');
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn('pricing_mode'));
    }
};
