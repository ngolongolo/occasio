<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitees', function (Blueprint $table) {
            $table->string('access_token', 64)->nullable()->after('token');
            $table->timestamp('access_pass_issued_at')->nullable()->after('access_token');
        });
        foreach (DB::table('invitees')->select('id')->orderBy('id')->get() as $invitee) {
            DB::table('invitees')->where('id', $invitee->id)->update(['access_token' => Str::random(64)]);
        }
        Schema::table('invitees', fn (Blueprint $table) => $table->unique('access_token'));

        Schema::create('event_check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invitee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('admitted_count')->default(0);
            $table->string('result');
            $table->text('note')->nullable();
            $table->timestamp('scanned_at');
            $table->timestamps();
            $table->index(['event_id', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_check_ins');
        Schema::table('invitees', function (Blueprint $table) {
            $table->dropUnique(['access_token']);
            $table->dropColumn(['access_token', 'access_pass_issued_at']);
        });
    }
};
