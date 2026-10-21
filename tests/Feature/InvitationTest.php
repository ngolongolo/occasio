<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use App\Models\{User,Event,Invitee,Delivery,NotificationDelivery,PledgePayment};
use App\Services\GuestImporter;
use App\Services\InvitationTemplate;
use App\Jobs\SendInvitation;
use App\Jobs\SendConfiguredNotification;
use App\Mail\InvitationMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
class InvitationTest extends TestCase {
 use RefreshDatabase;
 private function event(): Event {$u=User::create(['name'=>'Host','email'=>uniqid().'@example.com','password'=>'passwordlong']);return Event::create(['user_id'=>$u->id,'title'=>'Gala','host'=>'HTAF','description'=>'Welcome','starts_at'=>now()->addDays(30),'rsvp_deadline'=>now()->addDays(20),'venue'=>'Mwanza']);}
 private function guest(Event $e): Invitee {return $e->invitees()->create(['name'=>'Guest','email'=>'guest@example.com','identity_key'=>hash('sha256','guest@example.com'),'token'=>str_repeat('a',64),'max_guests'=>2]);}
 public function test_organiser_can_use_the_guided_event_creation_form(): void {
  $organiser=User::create(['name'=>'Host','email'=>'creator@example.com','password'=>'passwordlong']);
  $this->actingAs($organiser)->get('/events/create')->assertOk()->assertSee('Bring the occasion to life.')->assertSee('event-title')->assertSee('event-start')->assertSee('event-deadline')->assertSee('event-venue');
  $response=$this->actingAs($organiser)->post('/events',['title'=>'Leadership Forum','host'=>'BlueTick','description'=>'Welcome to the forum.','starts_at'=>now()->addMonth()->format('Y-m-d H:i:s'),'rsvp_deadline'=>now()->addWeeks(3)->format('Y-m-d'),'venue'=>'Dar es Salaam','dress_code'=>'Business formal','contact'=>'+255784311111']);
  $event=Event::where('title','Leadership Forum')->firstOrFail();$response->assertRedirect(route('events.show',$event));
  $this->assertSame($organiser->id,$event->user_id);$this->assertSame('Dar es Salaam',$event->venue);
 }
 public function test_dashboard_summarises_the_organisers_event_portfolio(): void {
  $event=$this->event();$organiser=User::findOrFail($event->user_id);$guest=$this->guest($event);$guest->update(['rsvp_status'=>'accepted','attending_count'=>1]);
  $this->actingAs($organiser)->get('/dashboard')->assertOk()->assertSee('Welcome back')->assertSee($organiser->name)->assertSee('Event portfolio')->assertSee($event->title)->assertSee('1 of 1')->assertSee('Create a new event');
 }
 public function test_shared_layout_uses_the_animated_okesheni_loader(): void {
  $this->get('/login')->assertOk()->assertSee('submit-loader-brand')->assertSee('/assets/logo/favicon.png',false)->assertDontSee('class="spinner"',false);
 }
 public function test_organiser_cannot_access_another_event(): void {$e=$this->event();$other=User::create(['name'=>'Other','email'=>'other@example.com','password'=>'passwordlong']);$this->actingAs($other)->get(route('events.show',$e))->assertForbidden();$this->actingAs($other)->post(route('events.send',$e),['channels'=>['email'],'consent'=>1])->assertForbidden();}
 public function test_rsvp_limits_and_decline(): void {$g=$this->guest($this->event());$url='/rsvp/'.$g->token;$this->post($url,['rsvp_status'=>'accepted','attending_count'=>3])->assertSessionHasErrors('attending_count');$this->post($url,['rsvp_status'=>'accepted','attending_count'=>2])->assertRedirect();$this->assertDatabaseHas('invitees',['id'=>$g->id,'attending_count'=>2]);$this->post($url,['rsvp_status'=>'declined'])->assertRedirect();$this->assertDatabaseHas('invitees',['id'=>$g->id,'rsvp_status'=>'declined','attending_count'=>0]);}
 public function test_started_event_links_reject_changes(): void {$e=$this->event();$e->update(['starts_at'=>now()->subHour(),'rsvp_deadline'=>now()->subDay()]);$g=$this->guest($e);$this->post('/rsvp/'.$g->token,['rsvp_status'=>'accepted','attending_count'=>1])->assertStatus(410);$this->get('/rsvp/unknown')->assertNotFound();}
 public function test_responses_and_invitation_sending_continue_after_requested_deadline(): void {
  Queue::fake();$e=$this->event();$e->update(['rsvp_deadline'=>now()->subDay()]);$g=$this->guest($e);$organiser=User::findOrFail($e->user_id);
  $this->post($g->rsvpUrl(),['rsvp_status'=>'accepted','attending_count'=>1])->assertRedirect();
  $this->actingAs($organiser)->post(route('events.send',$e),['guest_ids'=>[$g->id],'channels'=>['email'],'consent'=>1])->assertRedirect()->assertSessionHas('success');
  Queue::assertPushed(SendInvitation::class);
 }
 public function test_import_reports_duplicates_and_invalid_phone(): void {$e=$this->event();$path=tempnam(sys_get_temp_dir(),'guests');file_put_contents($path,"name,email,phone,max_guests\nGuest,guest@example.com,,2\nRepeat,guest@example.com,,1\nInvalid,,0784311111,1\n");$f=new UploadedFile($path,'guests.csv','text/csv',null,true);$report=app(GuestImporter::class)->import($f,$e);$this->assertSame(1,$report['imported']);$this->assertSame(1,$report['duplicates']);$this->assertCount(1,$report['errors']);unlink($path);}
 public function test_preview_does_not_submit_to_providers(): void {$g=$this->guest($this->event());$d=Delivery::create(['invitee_id'=>$g->id,'channel'=>'email','status'=>'queued']);\Illuminate\Support\Facades\Mail::fake();\Illuminate\Support\Facades\Http::preventStrayRequests();(new SendInvitation($d->id))->handle();$this->assertSame('previewed',$d->fresh()->status);\Illuminate\Support\Facades\Mail::assertNothingSent();}
 public function test_live_email_sends_personal_invitation(): void {
  config(['services.delivery_mode'=>'live','mail.default'=>'smtp']);
  Mail::fake();
  $g=$this->guest($this->event());
  $d=Delivery::create(['invitee_id'=>$g->id,'channel'=>'email','status'=>'queued']);
  (new SendInvitation($d->id))->handle();
  $this->assertSame('submitted',$d->fresh()->status);
  Mail::assertSent(InvitationMail::class,fn(InvitationMail $mail)=>$mail->hasTo($g->email));
  $mail=new InvitationMail($g);
  $this->assertSame('Your invitation: '.$g->event->title,$mail->envelope()->subject);
  $this->assertStringContainsString($g->rsvpUrl(),$mail->render());
 }
 public function test_live_sms_is_submitted_to_sprint_sms(): void {
  config(['services.delivery_mode'=>'live','services.sprint_sms'=>['url'=>'https://api.sprintsmsservice.com/api/SendSMS','api_id'=>'test-id','api_password'=>'test-password','sender_id'=>'LAINA']]);
  Http::fake(['api.sprintsmsservice.com/*'=>Http::response(['message_id'=>'sprint-123'])]);
  $g=$this->guest($this->event());$g->update(['phone'=>'+255784311111']);
  $d=Delivery::create(['invitee_id'=>$g->id,'channel'=>'sms','status'=>'queued']);
  (new SendInvitation($d->id))->handle();
  $this->assertDatabaseHas('deliveries',['id'=>$d->id,'status'=>'submitted','provider_id'=>'sprint-123']);
  Http::assertSent(fn($request)=>$request->method()==='GET'
   &&str_starts_with($request->url(),'https://api.sprintsmsservice.com/api/SendSMS?')
   &&$request['api_id']==='test-id'&&$request['api_password']==='test-password'
   &&$request['sms_type']==='P'&&$request['encoding']==='T'&&$request['sender_id']==='LAINA'
   &&$request['phonenumber']==='+255784311111'
   &&str_contains($request['textmessage'],$g->rsvpUrl()));
 }
 public function test_failed_sprint_sms_request_marks_delivery_failed(): void {
  config(['services.delivery_mode'=>'live','services.sprint_sms'=>['url'=>'https://api.sprintsmsservice.com/api/SendSMS','api_id'=>'test-id','api_password'=>'test-password','sender_id'=>'LAINA']]);
  Http::fake(['api.sprintsmsservice.com/*'=>Http::response('Service unavailable',503)]);
  $g=$this->guest($this->event());$g->update(['phone'=>'+255784311111']);
  $d=Delivery::create(['invitee_id'=>$g->id,'channel'=>'sms','status'=>'queued']);
  (new SendInvitation($d->id))->handle();
  $this->assertSame('failed',$d->fresh()->status);
  $this->assertSame('Messaging provider temporarily unavailable (HTTP 503). Retry this delivery later.',$d->fresh()->error);
 }
 public function test_organiser_can_add_edit_and_delete_a_guest(): void {
  $event=$this->event();
  $organiser=User::findOrFail($event->user_id);
  $this->actingAs($organiser)->post(route('events.guests.store',$event),['name'=>'Jane Guest','email'=>'JANE@example.com','phone'=>'','max_guests'=>3])->assertRedirect(route('events.participants',$event));
  $guest=$event->invitees()->where('email','jane@example.com')->firstOrFail();
  $this->actingAs($organiser)->get(route('events.guests.edit',[$event,$guest]))->assertOk()->assertSee('Jane Guest')->assertSee('jane@example.com');
  $this->actingAs($organiser)->put(route('events.guests.update',[$event,$guest]),['name'=>'Jane Updated','email'=>'jane@example.com','phone'=>'+255 784 311 111','max_guests'=>2])->assertRedirect(route('events.participants',$event));
  $this->assertDatabaseHas('invitees',['id'=>$guest->id,'name'=>'Jane Updated','phone'=>'+255784311111','max_guests'=>2]);
  $this->actingAs($organiser)->delete(route('events.guests.destroy',[$event,$guest]))->assertRedirect(route('events.participants',$event));
  $this->assertDatabaseMissing('invitees',['id'=>$guest->id]);
 }
 public function test_event_dashboard_has_single_participant_form(): void {
  $event=$this->event();$organiser=User::findOrFail($event->user_id);
  $this->actingAs($organiser)->get(route('events.participants',$event))->assertOk()->assertSee('Add one participant')->assertSee('participant-name')->assertSee('participant-email')->assertSee('participant-phone')->assertSee('participant-max-guests');
 }
 public function test_event_workspace_combines_invitation_rsvp_and_attendance_operations(): void {
  $event=$this->event();$event->update(['pledge_enabled'=>true,'payment_details'=>'Bank 123']);$organiser=User::findOrFail($event->user_id);$this->guest($event);
  $this->actingAs($organiser)->get(route('events.show',$event))->assertOk()->assertSee('Keep your event moving')->assertDontSee('Add one participant');
  $this->actingAs($organiser)->get(route('events.participants',$event))->assertOk()->assertViewHas('workspaceTab','participants')->assertSee('Invitations, RSVP and attendance')->assertSee('send-invitations-form')->assertSee('Invitation delivery')->assertDontSee('Confirm selected attendance');
  $this->actingAs($organiser)->get(route('events.invitations',$event))->assertRedirect(route('events.participants',$event));
  $this->actingAs($organiser)->get(route('events.attendance',$event))->assertRedirect(route('events.participants',$event));
  $this->actingAs($organiser)->get(route('events.pledges',$event))->assertOk()->assertViewHas('workspaceTab','pledges')->assertSee('Pledges and collections');
 }
 public function test_participant_directory_enables_attendance_during_or_after_event(): void {
  $event=$this->event();$event->update(['starts_at'=>now()->subMinute(),'rsvp_deadline'=>now()->subHour()]);$organiser=User::findOrFail($event->user_id);$this->guest($event);
  $this->actingAs($organiser)->get(route('events.participants',$event))->assertOk()->assertSee('bulk-attendance-form')->assertSee('Confirm selected attendance')->assertSee('Attendance confirmation');
 }
 public function test_organiser_cannot_manage_guests_from_another_event(): void {
  $event=$this->event();$guest=$this->guest($event);
  $other=$this->event();
  $otherOrganiser=User::findOrFail($other->user_id);
  $this->actingAs($otherOrganiser)->get(route('events.guests.edit',[$event,$guest]))->assertForbidden();
  $this->actingAs($otherOrganiser)->delete(route('events.guests.destroy',[$event,$guest]))->assertForbidden();
  $this->assertDatabaseHas('invitees',['id'=>$guest->id]);
 }
 public function test_guest_import_template_downloads(): void {
  $this->get(route('guest-template'))->assertOk()->assertDownload('okesheni-guest-import-template.xlsx');
 }
 public function test_organiser_can_save_branding_card_and_sms_template(): void {
  Storage::fake('local');
  $event=$this->event();$organiser=User::findOrFail($event->user_id);
  $logo=UploadedFile::fake()->image('logo.png',120,60);
  $card=UploadedFile::fake()->image('gala-card.jpg',900,1200);
  $template='Hi {guest_name}, join {event_title} at {venue}. RSVP: {rsvp_url}';
  $this->actingAs($organiser)->put(route('events.design.update',$event),['primary_color'=>'#123456','background_color'=>'#f1f2f3','text_color'=>'#222222','logo'=>$logo,'invitation_card'=>$card,'sms_template'=>$template])->assertRedirect(route('events.design',$event));
  $event->refresh();
  $this->assertSame('#123456',$event->primary_color);
  $this->assertSame('#f1f2f3',$event->background_color);
  $this->assertSame('#222222',$event->text_color);
  $this->assertSame($template,$event->sms_template);
  Storage::disk('local')->assertExists($event->logo_path);
  Storage::disk('local')->assertExists($event->invitation_card_path);
  $guest=$this->guest($event);
  $this->assertStringContainsString('Hi Guest, join Gala at Mwanza.',InvitationTemplate::sms($guest,$event));
  $mail=new InvitationMail($guest);
  $rendered=$mail->render();
  $this->assertStringContainsString('background:#f1f2f3',$rendered);
  $this->assertStringContainsString('color:#222222',$rendered);
  $attachments=$mail->attachments();
  $this->assertCount(0,$attachments);
  $this->assertSame('image/jpeg',$event->invitation_card_mime);
 }
 public function test_organiser_can_edit_event_details_from_settings(): void {
  $event=$this->event();$organiser=User::findOrFail($event->user_id);$starts=now()->addDays(45)->seconds(0);$deadline=now()->addDays(35)->format('Y-m-d');
  $this->actingAs($organiser)->get(route('events.design',$event))->assertOk()->assertSee('Event settings')->assertSee('id="event-details"',false)->assertSee($event->title);
  $this->actingAs($organiser)->put(route('events.design.update',$event),['title'=>'Updated Gala','host'=>'Updated Host','description'=>'Updated invitation description.','starts_at'=>$starts->format('Y-m-d\TH:i'),'rsvp_deadline'=>$deadline,'venue'=>'New Venue','dress_code'=>'Formal','contact'=>'+255700000000','primary_color'=>'#56283f','background_color'=>'#fcf9f4','text_color'=>'#30252a','sms_template'=>'Hello {guest_name}. RSVP: {rsvp_url}'])->assertRedirect(route('events.design',$event))->assertSessionHas('success','Event settings saved.');
  $this->assertDatabaseHas('events',['id'=>$event->id,'title'=>'Updated Gala','host'=>'Updated Host','description'=>'Updated invitation description.','venue'=>'New Venue','dress_code'=>'Formal','contact'=>'+255700000000']);
 }
 public function test_invitation_card_upload_rejects_non_image_files(): void {
  Storage::fake('local');$event=$this->event();$organiser=User::findOrFail($event->user_id);
  $pdf=UploadedFile::fake()->create('invitation.pdf',100,'application/pdf');
  $this->actingAs($organiser)->put(route('events.design.update',$event),['primary_color'=>'#56283f','background_color'=>'#fcf9f4','text_color'=>'#30252a','invitation_card'=>$pdf,'sms_template'=>'Hello {guest_name}. RSVP: {rsvp_url}'])->assertSessionHasErrors('invitation_card');
  $this->assertNull($event->fresh()->invitation_card_path);
 }
 public function test_invitation_design_exposes_large_image_optimization(): void {
  $event=$this->event();$organiser=User::findOrFail($event->user_id);
  $this->actingAs($organiser)->get(route('events.design',$event))->assertOk()->assertSee('invitation-image-status')->assertSee('createImageBitmap')->assertSee('large images are optimized automatically');
  $this->assertStringContainsString('.studio-preview > img { border-radius: 5px; display: block; height: auto;',file_get_contents(public_path('assets/guests.css')));
 }
 public function test_replacing_invitation_image_busts_the_design_preview_cache(): void {
  Storage::fake('local');$event=$this->event();$organiser=User::findOrFail($event->user_id);$base=['primary_color'=>'#56283f','background_color'=>'#fcf9f4','text_color'=>'#30252a','sms_template'=>'Hello {guest_name}. {rsvp_url}'];
  $this->actingAs($organiser)->put(route('events.design.update',$event),$base+['invitation_card'=>UploadedFile::fake()->image('first.png',600,900)])->assertRedirect();
  $oldPath=$event->fresh()->invitation_card_path;$oldVersion=substr(sha1($oldPath),0,12);
  $this->actingAs($organiser)->put(route('events.design.update',$event),$base+['invitation_card'=>UploadedFile::fake()->image('second.jpg',600,900)])->assertRedirect();
  $event->refresh();$this->assertNotSame($oldPath,$event->invitation_card_path);Storage::disk('local')->assertMissing($oldPath);
  $this->actingAs($organiser)->get(route('events.design',$event))->assertOk()->assertSee(substr(sha1($event->invitation_card_path),0,12))->assertDontSee($oldVersion);
  $this->actingAs($organiser)->get(route('events.design.card',$event))->assertOk()->assertHeader('cache-control','max-age=0, no-store, private');
 }
 public function test_other_organiser_cannot_change_invitation_design(): void {
  $event=$this->event();$other=$this->event();$otherOrganiser=User::findOrFail($other->user_id);
  $this->actingAs($otherOrganiser)->put(route('events.design.update',$event),['primary_color'=>'#123456','sms_template'=>'Hello {guest_name}'])->assertForbidden();
 }
 public function test_png_card_is_email_content_and_appears_on_rsvp_form(): void {
  Storage::fake('local');
  $event=$this->event();$organiser=User::findOrFail($event->user_id);$card=UploadedFile::fake()->image('invitation.png',900,1200);
  $this->actingAs($organiser)->put(route('events.design.update',$event),['primary_color'=>'#56283f','background_color'=>'#fcf9f4','text_color'=>'#30252a','invitation_card'=>$card,'sms_template'=>'Hello {guest_name}. RSVP: {rsvp_url}'])->assertRedirect();
  $event->refresh();$guest=$this->guest($event);$rendered=(new InvitationMail($guest))->render();
  $this->assertStringContainsString('data:image/png;base64,',$rendered);
  $this->assertStringNotContainsString($guest->cardUrl(true),$rendered);
  $this->assertStringContainsString($event->title.' invitation',$rendered);
  $this->assertStringNotContainsString($event->description,$rendered);
  $this->assertLessThan(strpos($rendered,'Confirm your attendance'),strpos($rendered,$event->title.' invitation'));
  $this->assertCount(0,(new InvitationMail($guest))->attachments());
  $this->get($guest->rsvpUrl())->assertOk()->assertSee($guest->cardUrl(true))->assertDontSee($event->description);
  $this->get($guest->cardUrl(true))->assertOk()->assertHeader('content-type','image/png');
 }
 public function test_selected_guest_can_receive_and_resend_invitation(): void {
  Queue::fake();
  $event=$this->event();$organiser=User::findOrFail($event->user_id);$selected=$this->guest($event);
  $unselected=$event->invitees()->create(['name'=>'Other','email'=>'other-guest@example.com','identity_key'=>hash('sha256','other-guest@example.com'),'token'=>str_repeat('b',64),'max_guests'=>1]);
  $delivery=Delivery::create(['invitee_id'=>$selected->id,'channel'=>'email','status'=>'submitted','provider_id'=>'old-id']);
  $this->actingAs($organiser)->post(route('events.send',$event),['guest_ids'=>[$selected->id],'channels'=>['email'],'consent'=>1])->assertRedirect()->assertSessionHas('success');
  $this->assertDatabaseHas('deliveries',['id'=>$delivery->id,'status'=>'queued','provider_id'=>null]);
  $this->assertDatabaseMissing('deliveries',['invitee_id'=>$unselected->id]);
  Queue::assertPushed(SendInvitation::class,fn(SendInvitation $job)=>$job->deliveryId===$delivery->id);
 }
 public function test_send_requires_a_selected_guest(): void {
  $event=$this->event();$organiser=User::findOrFail($event->user_id);
  $this->actingAs($organiser)->post(route('events.send',$event),['channels'=>['email'],'consent'=>1])->assertSessionHasErrors('guest_ids');
 }
 public function test_custom_sms_always_includes_confirmation_link(): void {
  $event=$this->event();$event->update(['sms_template'=>'Welcome {guest_name} to {event_title}.']);$guest=$this->guest($event);
  $this->assertStringContainsString($guest->rsvpUrl(),InvitationTemplate::sms($guest,$event));
 }
 public function test_admin_can_enable_pledges_and_guest_can_submit_one(): void {
  $event=$this->event();$organiser=User::findOrFail($event->user_id);
  $this->actingAs($organiser)->put(route('events.design.update',$event),['primary_color'=>'#56283f','background_color'=>'#fcf9f4','text_color'=>'#30252a','sms_template'=>'Hello {guest_name}. {rsvp_url}','response_options_present'=>1,'participation_enabled'=>1,'pledge_enabled'=>1,'pledge_currency'=>'tzs','pledge_message'=>'Support this cause','payment_details'=>'M-Pesa 0712345678'])->assertRedirect();
  $event->refresh();$this->assertTrue($event->participation_enabled);$this->assertTrue($event->pledge_enabled);$this->assertSame('TZS',$event->pledge_currency);
  $guest=$this->guest($event);
  $this->get($guest->rsvpUrl())->assertOk()->assertSee('Confirm participation')->assertSee('Make a pledge')->assertSee('M-Pesa 0712345678');
  $this->post($guest->rsvpUrl(),['response_type'=>'pledge','amount'=>'250000','payment_method'=>'M-Pesa','payment_reference'=>'ABC123','message'=>'With gratitude'])->assertRedirect()->assertSessionHas('success');
  $this->assertDatabaseHas('pledges',['invitee_id'=>$guest->id,'amount'=>250000,'status'=>'payment_reported','payment_reference'=>'ABC123']);
  $this->assertSame('pending',$guest->fresh()->rsvp_status);
 }
 public function test_pledge_is_opt_in_and_design_saves_without_hidden_pledge_fields(): void {
  $event=$this->event();$organiser=User::findOrFail($event->user_id);$this->assertFalse((bool)$event->fresh()->pledge_enabled);
  $this->actingAs($organiser)->get(route('events.design',$event))->assertOk()->assertSee('id="pledge-enabled"',false)->assertSee('id="pledge-personalization"',false)->assertSee('syncPledgeSettings',false);
  $this->actingAs($organiser)->put(route('events.design.update',$event),['primary_color'=>'#56283f','background_color'=>'#fcf9f4','text_color'=>'#30252a','sms_template'=>'Hello {guest_name}. {rsvp_url}','response_options_present'=>1,'participation_enabled'=>1])->assertRedirect();
  $this->assertFalse($event->fresh()->pledge_enabled);
 }
 public function test_pledge_only_event_blocks_participation_and_public_registration(): void {
  $event=$this->event();$event->update(['participation_enabled'=>false,'pledge_enabled'=>true,'payment_details'=>'Bank account 123']);$guest=$this->guest($event);
  $this->get($guest->rsvpUrl())->assertOk()->assertDontSee('Confirm participation')->assertSee('Submit pledge');
  $this->post($guest->rsvpUrl(),['response_type'=>'participation','rsvp_status'=>'accepted','attending_count'=>1])->assertNotFound();
  $this->get($event->registrationUrl())->assertNotFound();
 }
 public function test_admin_can_confirm_a_reported_pledge(): void {
  $event=$this->event();$event->update(['pledge_enabled'=>true,'payment_details'=>'Bank account 123']);$guest=$this->guest($event);
  $pledge=$guest->pledge()->create(['amount'=>50000,'status'=>'payment_reported','pledged_at'=>now()]);$organiser=User::findOrFail($event->user_id);
  $this->actingAs($organiser)->post(route('events.pledge-payments.store',[$event,$pledge]),['amount'=>50000,'payment_date'=>now()->toDateString(),'payment_method'=>'Bank','reference'=>'PAY-500'])->assertRedirect();
  $this->assertSame('paid',$pledge->fresh()->status);$this->assertNotNull($pledge->fresh()->paid_at);$this->assertSame($organiser->id,$pledge->fresh()->payment_confirmed_by);
  $this->assertDatabaseHas('pledge_payments',['pledge_id'=>$pledge->id,'amount'=>50000,'reference'=>'PAY-500','recorded_by'=>$organiser->id,'status'=>'recorded']);
  $this->post($guest->rsvpUrl(),['response_type'=>'pledge','amount'=>1])->assertStatus(422);$this->assertSame('50000.00',$pledge->fresh()->amount);
 }
 public function test_confirmed_attendance_queues_configured_thank_you_notifications(): void {
  Queue::fake();$event=$this->event();$event->update(['starts_at'=>now()->subHour(),'rsvp_deadline'=>now()->subDay(),'attendance_thanks_enabled'=>true,'attendance_thanks_mode'=>'automatic','attendance_thanks_email'=>true,'attendance_thanks_sms'=>true]);
  $guest=$this->guest($event);$guest->update(['phone'=>'+255784311111']);$organiser=User::findOrFail($event->user_id);
  $this->actingAs($organiser)->put(route('events.attendance.update',[$event,$guest]),['attendance_status'=>'attended','attended_count'=>2])->assertRedirect()->assertSessionHas('success');
  $this->assertDatabaseHas('invitees',['id'=>$guest->id,'attendance_status'=>'attended','attended_count'=>2,'attendance_confirmed_by'=>$organiser->id]);
  $this->assertDatabaseHas('notification_deliveries',['invitee_id'=>$guest->id,'type'=>'attendance_thanks','channel'=>'email','status'=>'queued']);
  $this->assertDatabaseHas('notification_deliveries',['invitee_id'=>$guest->id,'type'=>'attendance_thanks','channel'=>'sms','status'=>'queued']);
  Queue::assertPushed(SendConfiguredNotification::class,2);
 }
 public function test_paid_pledge_queues_configured_payment_thanks_once(): void {
  Queue::fake();$event=$this->event();$event->update(['pledge_enabled'=>true,'payment_details'=>'Bank 123','pledge_thanks_enabled'=>true,'pledge_thanks_mode'=>'automatic','pledge_thanks_email'=>true,'pledge_thanks_sms'=>false]);$guest=$this->guest($event);
  $pledge=$guest->pledge()->create(['amount'=>75000,'status'=>'payment_reported','pledged_at'=>now()]);$organiser=User::findOrFail($event->user_id);
  $this->actingAs($organiser)->post(route('events.pledge-payments.store',[$event,$pledge]),['amount'=>75000,'payment_date'=>now()->toDateString()])->assertRedirect();
  $this->assertSame(1,NotificationDelivery::where(['invitee_id'=>$guest->id,'type'=>'pledge_fully_paid_thanks','channel'=>'email'])->count());
  Queue::assertPushed(SendConfiguredNotification::class,1);
 }
 public function test_admin_can_book_partial_payments_and_void_an_entry(): void {
  $event=$this->event();$event->update(['pledge_enabled'=>true,'payment_details'=>'Bank 123']);$guest=$this->guest($event);$pledge=$guest->pledge()->create(['amount'=>100000,'status'=>'pledged','pledged_at'=>now()]);$organiser=User::findOrFail($event->user_id);
  $this->actingAs($organiser)->get(route('events.pledge-payments.create',[$event,$pledge]))->assertOk()->assertSee('Book a payment')->assertSee($guest->name)->assertSee('100,000.00');
  $this->actingAs($organiser)->post(route('events.pledge-payments.store',[$event,$pledge]),['amount'=>30000,'payment_date'=>now()->toDateString(),'payment_method'=>'Cash'])->assertRedirect(route('events.pledge-payments.create',[$event,$pledge]))->assertSessionHas('success');
  $this->assertSame('partially_paid',$pledge->fresh()->status);$this->assertSame(30000.0,(float)$pledge->fresh()->paid_amount);$this->assertSame(70000.0,(float)$pledge->fresh()->balance);
  $payment=PledgePayment::where('pledge_id',$pledge->id)->firstOrFail();
  $this->actingAs($organiser)->put(route('events.pledge-payments.void',[$event,$pledge,$payment]),['void_reason'=>'Entered against the wrong pledge'])->assertRedirect();
  $this->assertSame('voided',$payment->fresh()->status);$this->assertSame('pledged',$pledge->fresh()->status);$this->assertSame(0.0,(float)$pledge->fresh()->paid_amount);
 }
 public function test_payment_reference_is_optional_but_duplicate_event_reference_is_rejected(): void {
  $event=$this->event();$event->update(['pledge_enabled'=>true,'payment_details'=>'Bank 123']);$first=$this->guest($event);$second=$event->invitees()->create(['name'=>'Second','email'=>'second-pay@example.com','identity_key'=>hash('sha256','second-pay@example.com'),'token'=>str_repeat('p',64),'max_guests'=>1]);$organiser=User::findOrFail($event->user_id);
  $one=$first->pledge()->create(['amount'=>100000,'status'=>'pledged','pledged_at'=>now()]);$two=$second->pledge()->create(['amount'=>100000,'status'=>'pledged','pledged_at'=>now()]);
  $this->actingAs($organiser)->post(route('events.pledge-payments.store',[$event,$one]),['amount'=>10000,'payment_date'=>now()->toDateString()])->assertSessionHasNoErrors();
  $this->actingAs($organiser)->post(route('events.pledge-payments.store',[$event,$one]),['amount'=>10000,'payment_date'=>now()->toDateString(),'reference'=>'TX-123'])->assertSessionHasNoErrors();
  $this->actingAs($organiser)->post(route('events.pledge-payments.store',[$event,$two]),['amount'=>10000,'payment_date'=>now()->toDateString(),'reference'=>'TX-123'])->assertSessionHasErrors('reference');
 }
 public function test_overpayment_requires_an_explicit_override_and_reason(): void {
  $event=$this->event();$event->update(['pledge_enabled'=>true,'payment_details'=>'Bank 123']);$guest=$this->guest($event);$pledge=$guest->pledge()->create(['amount'=>50000,'status'=>'pledged','pledged_at'=>now()]);$organiser=User::findOrFail($event->user_id);$payload=['amount'=>60000,'payment_date'=>now()->toDateString()];
  $this->actingAs($organiser)->post(route('events.pledge-payments.store',[$event,$pledge]),$payload)->assertSessionHasErrors('amount');
  $this->actingAs($organiser)->post(route('events.pledge-payments.store',[$event,$pledge]),$payload+['overpayment_override'=>1])->assertSessionHasErrors('overpayment_reason');
  $this->actingAs($organiser)->post(route('events.pledge-payments.store',[$event,$pledge]),$payload+['overpayment_override'=>1,'overpayment_reason'=>'Donor intentionally added support'])->assertSessionHasNoErrors();
  $this->assertSame('overpaid',$pledge->fresh()->status);$this->assertSame(-10000.0,(float)$pledge->fresh()->balance);
 }
 public function test_each_installment_can_queue_its_own_automatic_receipt(): void {
  Queue::fake();$event=$this->event();$event->update(['pledge_enabled'=>true,'payment_details'=>'Bank 123','payment_receipt_enabled'=>true,'payment_receipt_mode'=>'automatic','payment_receipt_email'=>true,'payment_receipt_sms'=>false]);$guest=$this->guest($event);$pledge=$guest->pledge()->create(['amount'=>100000,'status'=>'pledged','pledged_at'=>now()]);$organiser=User::findOrFail($event->user_id);
  foreach ([30000,20000] as $amount) $this->actingAs($organiser)->post(route('events.pledge-payments.store',[$event,$pledge]),['amount'=>$amount,'payment_date'=>now()->toDateString()])->assertSessionHasNoErrors();
  $this->assertSame(2,NotificationDelivery::where(['invitee_id'=>$guest->id,'type'=>'pledge_payment_receipt','channel'=>'email'])->count());
  $this->assertSame(2,NotificationDelivery::where('invitee_id',$guest->id)->distinct()->count('deduplication_key'));
  Queue::assertPushed(SendConfiguredNotification::class,2);
 }
 public function test_configured_notification_job_honours_preview_mode(): void {
  config(['services.delivery_mode'=>'preview']);$guest=$this->guest($this->event());
  $delivery=NotificationDelivery::create(['invitee_id'=>$guest->id,'type'=>'attendance_thanks','channel'=>'email','status'=>'queued']);
  (new SendConfiguredNotification($delivery->id))->handle();
  $this->assertSame('previewed',$delivery->fresh()->status);
 }
 public function test_admin_can_configure_post_event_notification_templates(): void {
  $event=$this->event();$organiser=User::findOrFail($event->user_id);
  $this->actingAs($organiser)->put(route('events.design.update',$event),['primary_color'=>'#56283f','background_color'=>'#fcf9f4','text_color'=>'#30252a','sms_template'=>'Hello {guest_name}. {rsvp_url}','notification_options_present'=>1,'attendance_thanks_enabled'=>1,'attendance_thanks_mode'=>'automatic','attendance_thanks_email'=>1,'attendance_email_subject'=>'Thank you {guest_name}','attendance_email_message'=>'Thank you for attending {event_title}.','pledge_thanks_enabled'=>1,'pledge_thanks_mode'=>'manual','pledge_thanks_sms'=>1,'pledge_sms_message'=>'Payment received: {currency} {pledge_amount}.'])->assertRedirect();
  $event->refresh();$this->assertTrue($event->attendance_thanks_enabled);$this->assertTrue($event->attendance_thanks_email);$this->assertSame('automatic',$event->attendance_thanks_mode);$this->assertTrue($event->pledge_thanks_sms);$this->assertSame('Payment received: {currency} {pledge_amount}.',$event->pledge_sms_message);
 }
 public function test_admin_can_bulk_confirm_post_event_attendance(): void {
  Queue::fake();$event=$this->event();$event->update(['starts_at'=>now()->subHour(),'rsvp_deadline'=>now()->subDay()]);$organiser=User::findOrFail($event->user_id);$first=$this->guest($event);$second=$event->invitees()->create(['name'=>'Second','email'=>'second@example.com','identity_key'=>hash('sha256','second@example.com'),'token'=>str_repeat('z',64),'max_guests'=>1]);
  $this->actingAs($organiser)->put(route('events.attendance.bulk',$event),['guest_ids'=>[$first->id,$second->id],'attendance_status'=>'attended','attended_count'=>1])->assertRedirect()->assertSessionHas('success');
  $this->assertDatabaseHas('invitees',['id'=>$first->id,'attendance_status'=>'attended','attended_count'=>1]);$this->assertDatabaseHas('invitees',['id'=>$second->id,'attendance_status'=>'attended','attended_count'=>1]);
 }
 public function test_event_urls_use_uuid_instead_of_database_id(): void {
  $event=$this->event();$organiser=User::findOrFail($event->user_id);$url=route('events.show',$event);
  $this->assertStringContainsString('/events/'.$event->uuid,$url);
  $this->assertNotSame('/events/'.$event->id,parse_url($url,PHP_URL_PATH));
  $this->actingAs($organiser)->get('/events/'.$event->id)->assertNotFound();
  $this->actingAs($organiser)->get($url)->assertOk();
 }
 public function test_professional_landing_page_has_client_and_partner_sections(): void {
  $this->get('/')->assertOk()->assertSee('Corporate event operations, simplified')->assertSee('Okesheni')->assertSee('/assets/images/conference.png',false)->assertSee('/assets/images/networking.png',false)->assertSee('/assets/images/award.png',false)->assertSee('Clients')->assertSee('Partners')->assertDontSee('HTAF');
  $this->get('/htaf-sample')->assertNotFound();
 }
 public function test_public_event_link_registers_a_new_guest_and_confirms_attendance(): void {
  $event=$this->event();$url=route('events.registration.show',$event->registration_token);
  $this->get($url)->assertOk()->assertSee($event->title)->assertSee('Confirm your place')->assertSee('registration-description-toggle')->assertSee('Read more');
  $this->post($url,['name'=>'Public Guest','email'=>'PUBLIC@example.com','phone'=>'+255 784 000 111','rsvp_status'=>'accepted','attending_count'=>2,'dietary'=>'Vegetarian'])->assertRedirect()->assertSessionHas('success');
  $this->assertDatabaseHas('invitees',['event_id'=>$event->id,'name'=>'Public Guest','email'=>'public@example.com','phone'=>'+255784000111','rsvp_status'=>'accepted','attending_count'=>2,'max_guests'=>2,'dietary'=>'Vegetarian']);
 }
 public function test_public_registration_displays_an_uploaded_invitation_image(): void {
  Storage::fake('local');$event=$this->event();$path='events/'.$event->id.'/cards/invitation.jpg';Storage::disk('local')->put($path,'image-content');$event->update(['invitation_card_path'=>$path,'invitation_card_preview_path'=>$path,'invitation_card_name'=>'invitation.jpg','invitation_card_mime'=>'image/jpeg']);
  $cardUrl=route('events.registration.card',['token'=>$event->registration_token,'v'=>substr(sha1($path),0,12)]);
  $this->get($event->registrationUrl())->assertOk()->assertSee('registration-invitation-card')->assertSee($cardUrl,false);
  $this->get($cardUrl)->assertOk()->assertHeader('content-type','image/jpeg');
 }
 public function test_public_event_link_updates_an_existing_registered_guest(): void {
  $event=$this->event();$guest=$this->guest($event);$url=route('events.registration.show',$event->registration_token);
  $this->post($url,['name'=>'Guest Updated','email'=>$guest->email,'rsvp_status'=>'declined','dietary'=>''])->assertRedirect()->assertSessionHas('success');
  $this->assertSame(1,$event->invitees()->count());
  $this->assertDatabaseHas('invitees',['id'=>$guest->id,'name'=>'Guest Updated','rsvp_status'=>'declined','attending_count'=>0]);
 }
 public function test_public_event_registration_rejects_started_and_unknown_links(): void {
  $event=$this->event();$event->update(['starts_at'=>now()->subHour(),'rsvp_deadline'=>now()->subDay()]);$url=route('events.registration.show',$event->registration_token);
  $this->get($url)->assertOk()->assertSee('Registration has closed');
  $this->post($url,['name'=>'Late Guest','email'=>'late@example.com','rsvp_status'=>'accepted','attending_count'=>1])->assertStatus(410);
  $this->get('/join/'.str_repeat('x',64))->assertNotFound();
 }
 public function test_event_dashboard_displays_non_numeric_share_link(): void {
  $event=$this->event();$organiser=User::findOrFail($event->user_id);
  $this->actingAs($organiser)->get(route('events.participants',$event))->assertOk()->assertSee($event->registrationUrl())->assertDontSee('/join/'.$event->id.'"',false);
 }
 public function test_guest_list_can_be_paginated_and_filtered(): void {
  $event=$this->event();$organiser=User::findOrFail($event->user_id);
  foreach(range(1,26) as $number){
   $email='guest'.$number.'@example.com';
   $guest=$event->invitees()->create(['name'=>($number<=3?'VIP Guest ':'Guest ').$number,'email'=>$email,'identity_key'=>hash('sha256',$email),'token'=>str_pad((string)$number,64,'x'),'max_guests'=>1,'rsvp_status'=>$number<=3?'accepted':'pending','attending_count'=>$number<=3?1:0]);
   if($number===1)Delivery::create(['invitee_id'=>$guest->id,'channel'=>'email','status'=>'submitted']);
  }
  $this->actingAs($organiser)->get(route('events.participants',$event).'?per_page=10')
   ->assertOk()->assertViewHas('invitees',fn($guests)=>$guests->count()===10&&$guests->total()===26&&$guests->lastPage()===3)->assertSee('Next');
  $this->actingAs($organiser)->get(route('events.participants',$event).'?q=VIP&rsvp=accepted&delivery=not_sent&per_page=10')
   ->assertOk()->assertViewHas('invitees',fn($guests)=>$guests->total()===2)->assertSee('VIP Guest 2')->assertDontSee('VIP Guest 1');
 }
}
