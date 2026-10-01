<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
 Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('email')->unique();$t->string('password');$t->rememberToken();$t->timestamps();});
 Schema::create('events',function(Blueprint $t){$t->id();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->string('title');$t->string('host');$t->text('description');$t->dateTime('starts_at');$t->date('rsvp_deadline');$t->string('venue');$t->string('dress_code')->nullable();$t->string('contact')->nullable();$t->timestamps();});
 Schema::create('invitees',function(Blueprint $t){$t->id();$t->foreignId('event_id')->constrained()->cascadeOnDelete();$t->string('name');$t->string('email')->nullable();$t->string('phone')->nullable();$t->string('identity_key');$t->string('token',64)->unique();$t->unsignedTinyInteger('max_guests')->default(1);$t->string('rsvp_status')->default('pending');$t->unsignedTinyInteger('attending_count')->default(0);$t->text('dietary')->nullable();$t->dateTime('responded_at')->nullable();$t->timestamps();$t->unique(['event_id','identity_key']);});
 Schema::create('deliveries',function(Blueprint $t){$t->id();$t->foreignId('invitee_id')->constrained()->cascadeOnDelete();$t->string('channel');$t->string('status')->default('queued');$t->string('provider_id')->nullable();$t->text('error')->nullable();$t->timestamps();$t->unique(['invitee_id','channel']);});
 Schema::create('jobs',function(Blueprint $t){$t->id();$t->string('queue')->index();$t->longText('payload');$t->unsignedTinyInteger('attempts');$t->unsignedInteger('reserved_at')->nullable();$t->unsignedInteger('available_at');$t->unsignedInteger('created_at');});
 Schema::create('failed_jobs',function(Blueprint $t){$t->id();$t->string('uuid')->unique();$t->text('connection');$t->text('queue');$t->longText('payload');$t->longText('exception');$t->timestamp('failed_at')->useCurrent();});
 }
 public function down(): void {foreach(['failed_jobs','jobs','deliveries','invitees','events','users'] as $n) Schema::dropIfExists($n);}
};
