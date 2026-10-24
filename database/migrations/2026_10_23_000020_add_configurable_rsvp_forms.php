<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
 public function up():void{Schema::table('events',function(Blueprint $t){$t->string('rsvp_form_preset')->default('simple')->after('what_to_bring');$t->string('rsvp_confirmation_mode')->default('automatic')->after('rsvp_form_preset');$t->json('rsvp_field_config')->nullable()->after('rsvp_confirmation_mode');});Schema::table('invitees',function(Blueprint $t){$t->string('confirmation_status')->default('confirmed')->after('rsvp_status');$t->json('rsvp_answers')->nullable()->after('dietary');});DB::table('invitees')->where('rsvp_status','declined')->update(['confirmation_status'=>'rejected']);}
 public function down():void{Schema::table('invitees',fn(Blueprint $t)=>$t->dropColumn(['confirmation_status','rsvp_answers']));Schema::table('events',fn(Blueprint $t)=>$t->dropColumn(['rsvp_form_preset','rsvp_confirmation_mode','rsvp_field_config']));}
};
