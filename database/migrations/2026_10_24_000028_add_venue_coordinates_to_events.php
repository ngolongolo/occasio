<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->decimal('venue_latitude', 10, 7)->nullable()->after('venue');
            $table->decimal('venue_longitude', 10, 7)->nullable()->after('venue_latitude');
            $table->string('venue_source', 32)->nullable()->after('venue_longitude');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['venue_latitude', 'venue_longitude', 'venue_source']);
        });
    }
};
