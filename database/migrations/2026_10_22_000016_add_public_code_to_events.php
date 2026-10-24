<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', fn (Blueprint $table) => $table->string('public_code', 5)->nullable()->after('uuid'));
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $used = [];
        foreach (DB::table('events')->select('id')->orderBy('id')->get() as $event) {
            do {
                $code = '';
                for ($i = 0; $i < 5; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            } while (isset($used[$code]));
            $used[$code] = true;
            DB::table('events')->where('id', $event->id)->update(['public_code' => $code]);
        }
        Schema::table('events', fn (Blueprint $table) => $table->unique('public_code'));
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropUnique(['public_code']);
            $table->dropColumn('public_code');
        });
    }
};
