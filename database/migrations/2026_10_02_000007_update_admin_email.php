<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const OLD_EMAIL = 'admin@occasio.local';
    private const NEW_EMAIL = 'nicholaus.ngolongolo@bluetick.co.tz';

    public function up(): void
    {
        if (DB::table('users')->where('email', self::NEW_EMAIL)->exists()) {
            throw new RuntimeException('The replacement email already belongs to another user.');
        }

        DB::table('users')
            ->where('email', self::OLD_EMAIL)
            ->update(['email' => self::NEW_EMAIL, 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (DB::table('users')->where('email', self::OLD_EMAIL)->exists()) {
            throw new RuntimeException('The original email already belongs to another user.');
        }

        DB::table('users')
            ->where('email', self::NEW_EMAIL)
            ->update(['email' => self::OLD_EMAIL, 'updated_at' => now()]);
    }
};
