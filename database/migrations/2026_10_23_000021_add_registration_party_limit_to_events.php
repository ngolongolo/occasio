<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->unsignedTinyInteger('max_attendees_per_registration')->default(2)->after('plus_ones_allowed');
        });
    }

    public function down(): void
    {
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn('max_attendees_per_registration'));
    }
};
