<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('phone', 20)->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        if (DB::table('users')->whereNull('email')->exists()) {
            throw new RuntimeException('Cannot remove mobile login while phone-only user accounts exist. Add an email to those accounts before rolling back this migration.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropColumn('phone');
        });
    }
};
