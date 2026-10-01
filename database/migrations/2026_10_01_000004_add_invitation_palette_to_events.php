<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('background_color', 7)->default('#fcf9f4')->after('primary_color');
            $table->string('text_color', 7)->default('#56283f')->after('background_color');
        });
    }

    public function down(): void
    {
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn(['background_color', 'text_color']));
    }
};
