<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('primary_color', 7)->default('#56283f');
            $table->string('logo_path')->nullable();
            $table->string('invitation_card_path')->nullable();
            $table->string('invitation_card_name')->nullable();
            $table->string('invitation_card_mime', 100)->nullable();
            $table->text('sms_template')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['primary_color', 'logo_path', 'invitation_card_path', 'invitation_card_name', 'invitation_card_mime', 'sms_template']);
        });
    }
};
