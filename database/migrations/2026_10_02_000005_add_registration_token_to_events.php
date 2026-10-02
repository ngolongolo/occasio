<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('registration_token', 64)->nullable()->unique()->after('uuid');
        });

        DB::table('events')->orderBy('id')->each(function ($event) {
            DB::table('events')->where('id', $event->id)->update(['registration_token' => Str::random(64)]);
        });
    }

    public function down(): void
    {
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn('registration_token'));
    }
};
