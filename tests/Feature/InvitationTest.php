<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use App\Models\{User,Event,Invitee,Delivery,NotificationDelivery,PledgePayment,ParticipationPayment};
use App\Services\GuestImporter;
use App\Services\InvitationTemplate;
use App\Jobs\SendInvitation;
use App\Jobs\SendConfiguredNotification;
use App\Mail\{InvitationMail,EventAccessCodeMail};
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
class InvitationTest extends TestCase {
 use RefreshDatabase;
 private function event(): Event {$u=User::create(['name'=>'Host','email'=>uniqid().'@example.com','password'=>'passwordlong']);return Event::create(['user_id'=>$u->id,'title'=>'Gala','host'=>'HTAF','description'=>'Welcome','starts_at'=>now()->addDays(30),'rsvp_deadline'=>now()->addDays(20),'venue'=>'Mwanza','visibility'=>'link']);}
 private function guest(Event $e): Invitee {return $e->invitees()->create(['name'=>'Guest','email'=>'guest@example.com','identity_key'=>hash('sha256','guest@example.com'),'token'=>str_repeat('a',64),'max_guests'=>2]);}
 private function verifyForPayments(Event $event): void {$event->user->organiserVerification()->create(['organiser_type'=>'professional','verification_level'=>'identity','status'=>'approved','public_name'=>$event->host,'verified_at'=>now(),'payout_name_verified_at'=>now()]);}
 public function test_organiser_can_use_the_guided_event_creation_form(): void {
  Storage::fake('local');$organiser=User::create(['name'=>'Host Name','email'=>'creator@example.com','password'=>'passwordlong']);
  $this->actingAs($organiser)->get('/events/create')->assertOk()->assertSee('Bring the occasion to life.')->assertSee('event-title')->assertSee('event-start')->assertSee('event-deadline')->assertSee('event-venue')->assertSee('Okesheni Code')->assertSee('Professional Event')->assertSee('name="event_type" value="hangout" checked',false)->assertSee('name="host" value="Host Name"',false)->assertSee('name="contact" value="creator@example.com"',false)->assertSee('enctype="multipart/form-data"',false)->assertSee('name="invitation_card"',false)->assertSee('Event card picture')->assertDontSee('<select id="event-type"',false);
  $card=UploadedFile::fake()->image('leadership-card.jpg',900,1200);
  $response=$this->actingAs($organiser)->post('/events',['title'=>'Leadership Forum','host'=>'BlueTick','description'=>'Welcome to the forum.','starts_at'=>now()->addMonth()->format('Y-m-d H:i:s'),'rsvp_deadline'=>now()->addWeeks(3)->format('Y-m-d'),'venue'=>'Dar es Salaam','dress_code'=>'Business formal','contact'=>'+255784311111','invitation_card'=>$card]);
  $event=Event::where('title','Leadership Forum')->firstOrFail();$response->assertRedirect(route('events.show',$event));
  $this->assertSame($organiser->id,$event->user_id);$this->assertSame('Dar es Salaam',$event->venue);$this->assertSame('leadership-card.jpg',$event->invitation_card_name);$this->assertSame($event->invitation_card_path,$event->invitation_card_preview_path);Storage::disk('local')->assertExists($event->invitation_card_path);
  $mobileOrganiser=User::create(['name'=>'Mobile Host','phone'=>'+255712345678','password'=>'passwordlong']);
  $this->actingAs($mobileOrganiser)->get('/events/create')->assertOk()->assertSee('name="contact" value="+255712345678"',false);
 }
 public function test_event_creation_rejects_a_non_image_card_before_creating_the_event(): void {
  Storage::fake('local');$organiser=User::create(['name'=>'Host','email'=>'invalid-card@example.com','password'=>'passwordlong']);$pdf=UploadedFile::fake()->create('event-card.pdf',100,'application/pdf');
  $this->actingAs($organiser)->post('/events',['title'=>'Invalid Card Event','host'=>'Host','description'=>'Welcome','starts_at'=>now()->addMonth()->format('Y-m-d H:i:s'),'rsvp_deadline'=>now()->addWeeks(3)->format('Y-m-d'),'venue'=>'Dar es Salaam','invitation_card'=>$pdf])->assertSessionHasErrors('invitation_card');
  $this->assertDatabaseMissing('events',['title'=>'Invalid Card Event']);
 }
 public function test_dashboard_summarises_the_organisers_event_portfolio(): void {
  $event=$this->event();$organiser=User::findOrFail($event->user_id);$guest=$this->guest($event);$guest->update(['rsvp_status'=>'accepted','attending_count'=>1]);
  $this->actingAs($organiser)->get('/dashboard')->assertOk()->assertSee('Welcome back')->assertSee($organiser->name)->assertSee('Event portfolio')->assertSee($event->title)->assertSee('1 of 1')->assertSee('Create a new event');
 }
 public function test_shared_layout_uses_the_animated_okesheni_loader(): void {
  $this->get('/login')->assertOk()->assertSee('submit-loader-brand')->assertSee('/assets/logo/favicon.png',false)->assertDontSee('class="spinner"',false);
 }
 public function test_application_exposes_installable_pwa_metadata_and_offline_support(): void {
  $this->get('/')->assertOk()->assertSee('/manifest.webmanifest',false)->assertSee("serviceWorker.register('/service-worker.js?v=3'",false)->assertSee("updateViaCache: 'none'",false)->assertSee('pwa-install')->assertSee('network-status');
  $manifest=json_decode(file_get_contents(public_path('manifest.webmanifest')),true,flags:JSON_THROW_ON_ERROR);
  $this->assertSame('Okesheni',$manifest['name']);$this->assertSame('standalone',$manifest['display']);$this->assertCount(3,$manifest['icons']);
  $worker=file_get_contents(public_path('service-worker.js'));
  $this->assertStringContainsString('/offline.html',$worker);$this->assertStringContainsString("'/rsvp/'",$worker);$this->assertFileExists(public_path('offline.html'));
 }
 public function test_authentication_pages_use_the_redesigned_access_experience(): void {
  $this->get('/login')->assertOk()->assertSee('auth-page')->assertSee('Sign in to Okesheni')->assertSee('Email address or mobile number')->assertSee('name="identifier"', false)->assertSee('Keep me signed in')->assertSee('/assets/auth.css',false);
  $this->get('/register')->assertOk()->assertSee('auth-page')->assertSee('Create your account')->assertSee('Use your email address or mobile number.')->assertSee('name="identifier"', false)->assertDontSee('name="email"', false)->assertDontSee('name="phone"', false)->assertSee('Use at least 8 characters')->assertSee('password-guidance')->assertSee('Make every gathering feel considered.');
 }
 public function test_landing_event_choice_is_preserved_through_registration(): void {
  $this->get('/register?event_type=event')->assertOk()->assertSee('name="event_type" value="event"',false)->assertSee('/login?event_type=event',false);
  $this->post('/register',['name'=>'Professional Host','identifier'=>'professional-host@example.com','password'=>'passwordlong','password_confirmation'=>'passwordlong','event_type'=>'event'])->assertRedirect('/events/create?event_type=event');
  $this->get('/events/create?event_type=event')->assertOk()->assertSee('name="event_type" value="event" checked',false);
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
 public function test_event_dashboard_has_participant_repeater_form(): void {
  $event=$this->event();$organiser=User::findOrFail($event->user_id);
  $this->actingAs($organiser)->get(route('events.participants',$event))->assertOk()->assertSee('Add participants')->assertSee('participant-repeater-form')->assertSee('participants[0][name]',false)->assertSee('Add another participant')->assertSee('Save participants');
 }
 public function test_organiser_can_add_multiple_participants_in_one_submission(): void {
  $event=$this->event();$organiser=User::findOrFail($event->user_id);
  $participants=[['name'=>'Jane Guest','email'=>'JANE@example.com','phone'=>'','max_guests'=>2],['name'=>'John Guest','email'=>'','phone'=>'+255 784 311 111','max_guests'=>3]];
  $this->actingAs($organiser)->post(route('events.guests.store',$event),compact('participants'))->assertRedirect(route('events.participants',$event))->assertSessionHas('success','2 participants added successfully.');
  $this->assertDatabaseHas('invitees',['event_id'=>$event->id,'name'=>'Jane Guest','email'=>'jane@example.com','max_guests'=>2]);
  $this->assertDatabaseHas('invitees',['event_id'=>$event->id,'name'=>'John Guest','phone'=>'+255784311111','max_guests'=>3]);
 }
 public function test_participant_repeater_rejects_duplicate_contacts_without_partial_insert(): void {
  $event=$this->event();$organiser=User::findOrFail($event->user_id);$participants=[['name'=>'First','email'=>'same@example.com','phone'=>'','max_guests'=>1],['name'=>'Duplicate','email'=>'SAME@example.com','phone'=>'','max_guests'=>1]];
  $this->actingAs($organiser)->post(route('events.guests.store',$event),compact('participants'))->assertSessionHasErrors('participants.1.email');
  $this->assertSame(0,$event->invitees()->count());
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
  $this->get($guest->rsvpUrl())->assertOk()->assertSee($guest->cardUrl(true))->assertSee('Invitation details')->assertSee($event->description);
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
 public function test_landing_page_promotes_professional_events_and_okesheni_code_equally(): void {
  $this->get('/')->assertOk()->assertSee('Professional events. Personal occasions.')->assertSee('Bring people together.')->assertSee('Professional Events')->assertSee('Okesheni Code')->assertSee('Enter your 5-character event code')->assertSee('/register?event_type=event',false)->assertSee('/register?event_type=hangout',false)->assertSee('/assets/images/conference.png',false)->assertSee('/assets/gala-hero.png',false)->assertSee('/assets/images/networking.png',false)->assertSee('/assets/images/award.png',false)->assertSee('Friends &amp; families',false)->assertSee('Partners')->assertDontSee('HTAF');
  $this->get('/htaf-sample')->assertNotFound();
 }
 public function test_public_event_link_registers_a_new_guest_and_confirms_attendance(): void {
  $event=$this->event();$event->update(['plus_ones_allowed'=>true,'max_attendees_per_registration'=>2]);$url=$event->registrationUrl();
  $this->get($url)->assertOk()->assertSee($event->title)->assertSee('Confirm your place')->assertSee('registration-description-toggle')->assertSee('Read more');
  $this->post($url,['name'=>'Public Guest','email'=>'PUBLIC@example.com','phone'=>'+255 784 000 111','rsvp_status'=>'accepted','attending_count'=>2,'dietary'=>'Vegetarian'])->assertRedirect()->assertSessionHas('success');
  $this->assertDatabaseHas('invitees',['event_id'=>$event->id,'name'=>'Public Guest','email'=>'public@example.com','phone'=>'+255784000111','rsvp_status'=>'accepted','attending_count'=>2,'max_guests'=>2,'dietary'=>'Vegetarian']);
  $guest=$event->invitees()->where('email','public@example.com')->firstOrFail();$this->post($url,['name'=>'Public Guest','email'=>'public@example.com','rsvp_status'=>'accepted','attending_count'=>2])->assertRedirect(route('rsvp.show',$guest->token));
 }
 public function test_public_paid_registration_continues_to_bluetick_payment_page(): void {
  config(['services.snippe.key'=>'snp_test_key','services.snippe.webhook_base_url'=>null]);Http::fake(['api.snippe.sh/v1/payments'=>Http::response(['status'=>'success','code'=>201,'data'=>['reference'=>'snippe-registration-1','status'=>'pending']],201)]);
  $event=$this->event();$this->verifyForPayments($event);$event->update(['participation_payment_timing'=>'pay_now','participation_collection_method'=>'bluetick_pay','participation_price'=>10000,'participation_currency'=>'TZS','plus_ones_allowed'=>true,'max_attendees_per_registration'=>3]);$url=$event->registrationUrl();
  $this->get($url)->assertOk()->assertSee('join-simple-shell')->assertSee('Attendance &amp; payment',false)->assertSee('Pay TZS 10,000.00 · confirm 1 place')->assertSee('Review before confirming')->assertSee('Okesheni fee: TZS 0.00')->assertSee('name="checkout_acknowledged"',false)->assertSee('Identity verified')->assertSee('Report this event')->assertSee('name="phone"',false)->assertDontSee('name="payment_msisdn"',false)->assertSee('max="3"',false)->assertSee('You can register up to')->assertSee('3 people')->assertDontSee('Will you attend?')->assertSee('>Total<',false)->assertDontSee('Total attendance payment')->assertDontSee('attendance-progress');
  $response=$this->post($url,['name'=>'Paying Guest','email'=>'payer@example.com','phone'=>'+255656414320','rsvp_status'=>'declined','attending_count'=>2,'checkout_acknowledged'=>1]);
  $guest=$event->invitees()->where('email','payer@example.com')->firstOrFail();
  $response->assertRedirect(route('rsvp.payment.show',$guest->token));
  $this->assertSame('pending',$guest->fresh()->participation_payment_status);
  $this->assertSame('accepted',$guest->rsvp_status);$this->assertSame(2,$guest->attending_count);
  $this->assertDatabaseHas('participation_payments',['invitee_id'=>$guest->id,'status'=>'pending','amount'=>20000]);
  $this->get(route('rsvp.payment.show',$guest->token))->assertOk()->assertSee('Complete the prompt on your phone')->assertSee('TZS 20,000.00')->assertSee('Waiting for payment confirmation')->assertDontSee('name="msisdn"',false);
  $this->get(route('rsvp.payment.status',$guest->token))->assertOk()->assertJson(['status'=>'pending','confirmed'=>false,'payment_status'=>'pending']);
 }
 public function test_public_manual_paid_registration_continues_to_reference_payment_page(): void {
  $event=$this->event();$event->update(['participation_payment_timing'=>'pay_now','participation_collection_method'=>'manual','participation_price'=>5000,'participation_currency'=>'TZS','participation_payment_details'=>'Pay 0712345678']);
  $response=$this->post($event->registrationUrl(),['name'=>'Manual Guest','email'=>'manual@example.com','rsvp_status'=>'accepted','attending_count'=>1,'participation_payment_reference'=>'MANUAL-123','checkout_acknowledged'=>1]);
  $guest=$event->invitees()->where('email','manual@example.com')->firstOrFail();
  $response->assertRedirect(route('rsvp.payment.show',$guest->token));
  $this->get(route('rsvp.payment.show',$guest->token))->assertOk()->assertSee('Payment verification pending')->assertSee('Organizer verification')->assertDontSee('Transaction/reference number');
 }
 public function test_public_registration_party_size_respects_organizer_limit_and_remaining_capacity(): void {
  $event=$this->event();$event->update(['visibility'=>'link','plus_ones_allowed'=>true,'max_attendees_per_registration'=>3,'capacity'=>2]);$url=$event->registrationUrl();
  $this->get($url)->assertOk()->assertSee('max="2"',false)->assertSee('2 places remaining');
  $this->post($url,['name'=>'First Party','email'=>'party@example.com','rsvp_status'=>'accepted','attending_count'=>2])->assertRedirect();
  $this->post($url,['name'=>'Late Guest','email'=>'late-party@example.com','rsvp_status'=>'accepted','attending_count'=>1])->assertSessionHasErrors('attending_count');
  $this->get($url)->assertOk()->assertSee('maximum attendance has been reached')->assertDontSee('id="registration-count"',false);
 }
 public function test_public_registration_displays_an_uploaded_invitation_image(): void {
  Storage::fake('local');$event=$this->event();$path='events/'.$event->id.'/cards/invitation.jpg';Storage::disk('local')->put($path,'image-content');$event->update(['invitation_card_path'=>$path,'invitation_card_preview_path'=>$path,'invitation_card_name'=>'invitation.jpg','invitation_card_mime'=>'image/jpeg']);
  $cardUrl=route('events.registration.card',['token'=>$event->public_code,'v'=>substr(sha1($path),0,12)]);
  $this->get($event->registrationUrl())->assertOk()->assertSee('registration-invitation-card')->assertSee($cardUrl,false);
  $this->get($cardUrl)->assertOk()->assertHeader('content-type','image/jpeg');
 }
 public function test_attendee_pages_cobrand_the_organisation_logo_with_okesheni(): void {
  Storage::fake('local');$event=$this->event();$event->update(['visibility'=>'link','logo_path'=>'events/'.$event->id.'/branding/logo.png']);Storage::disk('local')->put($event->logo_path,'png-content');$guest=$this->guest($event);$guest->update(['rsvp_status'=>'accepted','attending_count'=>1,'participation_payment_status'=>'not_required']);
  $publicLogo=route('events.registration.logo',$event->public_code);$privateLogo=route('rsvp.logo',$guest->token);
  $this->get($event->registrationUrl())->assertOk()->assertSee($publicLogo,false)->assertSee('Hosted by '.$event->host)->assertSee('/assets/logo/logo_main.png',false);
  $this->get($publicLogo)->assertOk();
  $this->get($guest->rsvpUrl())->assertOk()->assertSee($privateLogo,false)->assertSee('Hosted by '.$event->host)->assertSee('/assets/logo/logo_main.png',false);
  $this->get(route('rsvp.pass.show',$guest->token))->assertOk()->assertSee($privateLogo,false)->assertSee('/assets/logo/logo_main.png',false);
  $event->update(['participation_payment_timing'=>'pay_now','participation_collection_method'=>'bluetick_pay','participation_price'=>10000]);$guest->update(['participation_payment_status'=>'payment_required']);
  $this->get(route('rsvp.payment.show',$guest->token))->assertOk()->assertSee($privateLogo,false)->assertSee('Securely powered by Okesheni')->assertSee('Hosted by '.$event->host);
 }
 public function test_public_event_link_updates_an_existing_registered_guest(): void {
  $event=$this->event();$guest=$this->guest($event);$url=$event->registrationUrl();
  $this->post($url,['name'=>'Guest Updated','email'=>$guest->email,'rsvp_status'=>'declined','dietary'=>''])->assertRedirect()->assertSessionHas('success');
  $this->assertSame(1,$event->invitees()->count());
  $this->assertDatabaseHas('invitees',['id'=>$guest->id,'name'=>'Guest Updated','rsvp_status'=>'declined','attending_count'=>0]);
 }
 public function test_public_event_registration_rejects_started_and_unknown_links(): void {
  $event=$this->event();$event->update(['starts_at'=>now()->subHour(),'rsvp_deadline'=>now()->subDay()]);$url=$event->registrationUrl();
  $this->get($url)->assertOk()->assertSee('Registration has closed');
  $this->post($url,['name'=>'Late Guest','email'=>'late@example.com','rsvp_status'=>'accepted','attending_count'=>1])->assertStatus(410);
  $this->get('/join/'.str_repeat('x',64))->assertNotFound();
 }
 public function test_event_dashboard_displays_non_numeric_share_link(): void {
  $event=$this->event();$organiser=User::findOrFail($event->user_id);
  $this->assertStringEndsWith('/join/'.$event->public_code,$event->registrationUrl());
  $this->actingAs($organiser)->get(route('events.participants',$event))->assertOk()->assertSee($event->registrationUrl())->assertDontSee('/join/'.$event->id.'"',false)->assertDontSee($event->registration_token);
  $this->get(route('events.registration.show',$event->registration_token))->assertRedirect($event->registrationUrl());
  $this->get('/join/'.strtolower($event->public_code))->assertRedirect($event->registrationUrl());
 }
 public function test_every_event_gets_a_unique_five_character_public_code(): void {
  $first=$this->event();$second=$this->event();
  $this->assertMatchesRegularExpression('/^[A-Z0-9]{5}$/',$first->public_code);
  $this->assertMatchesRegularExpression('/^[A-Z0-9]{5}$/',$second->public_code);
  $this->assertNotSame($first->public_code,$second->public_code);
  $organiser=User::findOrFail($first->user_id);
  $this->actingAs($organiser)->get(route('events.show',$first))->assertOk()->assertSee($first->public_code)->assertSee('copy-event-code');
 }
 public function test_homepage_code_finds_only_public_events(): void {
  $public=$this->event();$public->update(['visibility'=>'link']);
  $private=$this->event();$private->update(['visibility'=>'invite_only']);
  $this->get('/')->assertOk()->assertSee('Joining an event?')->assertSee('Enter your 5-character event code')->assertSee('public-event-code');
  $this->get(route('events.public.find',['code'=>strtolower($public->public_code)]))->assertRedirect($public->registrationUrl());
  $this->get(route('events.public.find',['code'=>$private->public_code]))->assertRedirect(route('events.access.show',$private->public_code));
  $this->get(route('events.public.find',['code'=>'BAD!']))->assertSessionHasErrors('code');
 }
 public function test_invite_only_code_requires_matching_details_and_email_otp_before_revealing_event(): void {
  Mail::fake();$event=$this->event();$event->update(['event_type'=>'hangout','visibility'=>'invite_only']);$guest=$this->guest($event);
  $this->get(route('events.access.show',$event->public_code))->assertOk()->assertSee('Find your invitation')->assertDontSee($event->title)->assertDontSee($event->venue);
  $this->post(route('events.access.request',$event->public_code),['contact'=>'unknown@example.com'])->assertRedirect()->assertSessionHas('success');
  Mail::assertNothingSent();
  $otp=null;
  $this->post(route('events.access.request',$event->public_code),['contact'=>strtoupper($guest->email)])->assertRedirect()->assertSessionHas('success');
  Mail::assertSent(EventAccessCodeMail::class,function($mail)use(&$otp){$otp=$mail->code;return true;});
  $this->assertMatchesRegularExpression('/^\d{6}$/',$otp);
  $this->get(route('events.access.show',$event->public_code))->assertOk()->assertSee('Enter your verification code')->assertDontSee($event->title);
  $this->post(route('events.access.verify',$event->public_code),['otp'=>'000000'])->assertSessionHasErrors('otp');
  $this->post(route('events.access.verify',$event->public_code),['otp'=>$otp])->assertRedirect($guest->rsvpUrl())->assertSessionHas('success');
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
 public function test_organiser_can_create_a_paid_hangout(): void {
  $organiser=User::create(['name'=>'Host','email'=>'hangout-host@example.com','password'=>'passwordlong']);
  $organiser->organiserVerification()->create(['organiser_type'=>'individual','verification_level'=>'identity','status'=>'approved','verified_at'=>now()]);
  $response=$this->actingAs($organiser)->post('/events',['event_type'=>'hangout','title'=>'Games Night','host'=>'Nicholaus','description'=>'An easy evening with friends.','starts_at'=>now()->addWeek()->format('Y-m-d H:i:s'),'ends_at'=>now()->addWeek()->addHours(3)->format('Y-m-d H:i:s'),'rsvp_deadline'=>now()->addDays(5)->format('Y-m-d'),'venue'=>'Home','contact'=>'+255784311111','capacity'=>8,'visibility'=>'invite_only','plus_ones_allowed'=>1,'what_to_bring'=>'Your favourite game','participation_payment_timing'=>'pay_now','participation_collection_method'=>'manual','participation_price'=>15000,'participation_currency'=>'tzs','participation_payment_details'=>'Pay by mobile money','cancellation_policy'=>'Refunds until two days before.']);
  $event=Event::where('title','Games Night')->firstOrFail();$response->assertRedirect(route('events.show',$event));
  $this->assertSame('hangout',$event->event_type);$this->assertSame('pay_now',$event->participation_payment_timing);$this->assertSame('TZS',$event->participation_currency);$this->assertTrue($event->plus_ones_allowed);
  $this->get($event->registrationUrl())->assertRedirect(route('events.access.show',$event->public_code));
 }
 public function test_pay_now_hangout_collects_payment_reference_inside_rsvp(): void {
  $event=$this->event();$event->update(['event_type'=>'hangout','participation_payment_timing'=>'pay_now','participation_collection_method'=>'manual','participation_price'=>10000,'participation_currency'=>'TZS','participation_payment_details'=>'Pay to 0712345678']);$guest=$this->guest($event);
  $this->get($guest->rsvpUrl())->assertOk()->assertSee('Okesheni Code RSVP')->assertSee("Organiser's payment details",false)->assertSee('Payment transaction/reference number')->assertSee('Review before confirming')->assertSee('name="checkout_acknowledged"',false);
  $this->post($guest->rsvpUrl(),['rsvp_status'=>'accepted','attending_count'=>1])->assertSessionHasErrors('participation_payment_reference');
  $this->post($guest->rsvpUrl(),['rsvp_status'=>'accepted','attending_count'=>1,'participation_payment_reference'=>'TX-123','checkout_acknowledged'=>1])->assertRedirect()->assertSessionHas('success');
  $this->assertDatabaseHas('invitees',['id'=>$guest->id,'rsvp_status'=>'accepted','participation_payment_status'=>'payment_reported','participation_payment_reference'=>'TX-123']);
 }
 public function test_pay_at_hangout_reserves_a_place_and_capacity_is_enforced(): void {
  $event=$this->event();$event->update(['event_type'=>'hangout','capacity'=>2,'participation_payment_timing'=>'pay_at_event','participation_price'=>5000,'participation_currency'=>'TZS']);$first=$this->guest($event);$second=$event->invitees()->create(['name'=>'Friend','email'=>'friend@example.com','identity_key'=>hash('sha256','friend@example.com'),'token'=>str_repeat('h',64),'max_guests'=>2]);
  $this->post($first->rsvpUrl(),['rsvp_status'=>'accepted','attending_count'=>2,'checkout_acknowledged'=>1])->assertRedirect();
  $this->assertSame('due_at_event',$first->fresh()->participation_payment_status);
  $this->post($second->rsvpUrl(),['rsvp_status'=>'accepted','attending_count'=>1,'checkout_acknowledged'=>1])->assertSessionHasErrors('attending_count');
 }
 public function test_bluetick_pay_detects_network_requests_payment_and_confirms_callback(): void {
  config(['services.snippe.key'=>'snp_test_key','services.snippe.webhook_secret'=>null,'services.snippe.webhook_base_url'=>'https://okesheni.example/api/snippe/webhook']);Http::fake(['api.snippe.sh/v1/payments'=>Http::response(['status'=>'success','code'=>201,'data'=>['reference'=>'snippe-payment-1','status'=>'pending','amount'=>['value'=>20000,'currency'=>'TZS']]],201)]);
  $event=$this->event();$this->verifyForPayments($event);$event->update(['event_type'=>'hangout','participation_payment_timing'=>'pay_now','participation_collection_method'=>'bluetick_pay','participation_price'=>10000,'participation_currency'=>'TZS','payout_payment_type'=>'MixxByYas','payout_number'=>'0656414320']);$guest=$this->guest($event);
  $this->post($guest->rsvpUrl(),['rsvp_status'=>'accepted','attending_count'=>2,'payment_msisdn'=>'0656 414 320','checkout_acknowledged'=>1])->assertRedirect(route('rsvp.payment.show',$guest->token))->assertSessionHas('success');
  $this->assertSame('pending',$guest->fresh()->participation_payment_status);
  $payment=ParticipationPayment::where('invitee_id',$guest->id)->firstOrFail();
  $this->assertSame(1,$payment->network);$this->assertSame('255656414320',$payment->msisdn);$this->assertSame(20000.0,(float)$payment->amount);$this->assertSame('pending',$payment->status);$this->assertSame(32,strlen($payment->callback_token));
  Http::assertSent(fn($request)=>$request->url()==='https://api.snippe.sh/v1/payments'&&$request->hasHeader('Authorization','Bearer snp_test_key')&&strlen($request->header('Idempotency-Key')[0])<=30&&$request['phone_number']==='255656414320'&&$request['webhook_url']==='https://okesheni.example/api/snippe/webhook');
  $payload=['id'=>'evt-payment-1','type'=>'payment.completed','api_version'=>'2026-01-25','data'=>['reference'=>'snippe-payment-1','external_reference'=>'25704734669025','status'=>'completed','amount'=>['value'=>20000,'currency'=>'TZS'],'customer'=>['phone'=>'+255656414320']]];
  $this->postJson(route('api.payments.callback',$payment->callback_token),$payload)->assertOk()->assertJson(['received'=>true]);
  $this->assertSame('paid',$payment->fresh()->status);$this->assertSame('paid',$guest->fresh()->participation_payment_status);
  $this->get(route('rsvp.payment.status',$guest->token))->assertOk()->assertJson(['status'=>'paid','confirmed'=>true]);
  $this->get(route('rsvp.payment.show',$guest->token))->assertOk()->assertSee('Attendance and payment confirmed')->assertSee('Official access pass')->assertSee(route('rsvp.pass.qr',$guest->token),false);
  $this->postJson(route('api.payments.callback',$payment->callback_token),$payload)->assertOk();
 }
 public function test_combined_attendance_and_payment_uses_the_updated_party_size(): void {
  config(['services.snippe.key'=>'snp_test_key','services.snippe.webhook_base_url'=>null]);Http::fake(['api.snippe.sh/v1/payments'=>Http::response(['status'=>'success','code'=>201,'data'=>['reference'=>'snippe-combined-1','status'=>'pending']],201)]);
  $event=$this->event();$this->verifyForPayments($event);$event->update(['participation_payment_timing'=>'pay_now','participation_collection_method'=>'bluetick_pay','participation_price'=>10000,'participation_currency'=>'TZS']);$guest=$this->guest($event);$guest->update(['rsvp_status'=>'accepted','attending_count'=>1,'max_guests'=>3,'participation_payment_status'=>'payment_required']);
  $this->post(route('rsvp.payment.store',$guest->token),['attending_count'=>3,'msisdn'=>'0656414320','checkout_acknowledged'=>1])->assertRedirect()->assertSessionHas('success');
  $payment=$guest->participationPayments()->firstOrFail();$this->assertSame(3,$guest->fresh()->attending_count);$this->assertSame(30000.0,(float)$payment->amount);$this->assertSame('pending',$payment->status);
  Http::assertSent(fn($request)=>data_get($request->data(),'details.amount')===30000);
 }
 public function test_snippe_payment_omits_an_insecure_local_webhook_url(): void {
  config(['services.snippe.key'=>'snp_test_key','services.snippe.webhook_base_url'=>null]);Http::fake(['api.snippe.sh/v1/payments'=>Http::response(['status'=>'success','code'=>201,'data'=>['reference'=>'snippe-local-1','status'=>'pending']],201)]);
  $event=$this->event();$this->verifyForPayments($event);$event->update(['participation_payment_timing'=>'pay_now','participation_collection_method'=>'bluetick_pay','participation_price'=>10000,'participation_currency'=>'TZS']);$guest=$this->guest($event);$guest->update(['rsvp_status'=>'accepted','attending_count'=>1,'participation_payment_status'=>'payment_required']);
  $this->post(route('rsvp.payment.store',$guest->token),['msisdn'=>'0656414320','checkout_acknowledged'=>1])->assertRedirect()->assertSessionHas('success');
  Http::assertSent(fn($request)=>$request->url()==='https://api.snippe.sh/v1/payments'&&!array_key_exists('webhook_url',$request->data()));
 }
 public function test_snippe_rejection_is_saved_and_returned_by_payment_status(): void {
  config(['services.snippe.key'=>'snp_test_key','services.snippe.webhook_base_url'=>null]);Http::fake(['api.snippe.sh/v1/payments'=>Http::response(['status'=>'error','message'=>'Payment channel is unavailable.'],422)]);
  $event=$this->event();$this->verifyForPayments($event);$event->update(['participation_payment_timing'=>'pay_now','participation_collection_method'=>'bluetick_pay','participation_price'=>10000,'participation_currency'=>'TZS']);$guest=$this->guest($event);$guest->update(['rsvp_status'=>'accepted','attending_count'=>1,'participation_payment_status'=>'payment_required']);
  $this->post(route('rsvp.payment.store',$guest->token),['msisdn'=>'0656414320','checkout_acknowledged'=>1])->assertRedirect()->assertSessionHasErrors('msisdn');
  $payment=$guest->participationPayments()->firstOrFail();$this->assertSame('request_failed',$payment->status);$this->assertSame('Payment channel is unavailable.',$payment->provider_remarks);$this->assertSame(422,$payment->request_response['http_status']);
  $this->get(route('rsvp.payment.status',$guest->token))->assertOk()->assertJson(['status'=>'payment_required','confirmed'=>false,'payment_status'=>'request_failed','message'=>'Payment channel is unavailable.','redirect'=>null]);
 }
 public function test_check_payment_status_reconciles_a_completed_snippe_payment(): void {
  config(['services.snippe.key'=>'snp_test_key']);Http::fake(['api.snippe.sh/v1/payments/snippe-poll-1'=>Http::response(['status'=>'success','code'=>200,'data'=>['reference'=>'snippe-poll-1','status'=>'completed','amount'=>['value'=>20000,'currency'=>'TZS']]],200)]);
  $event=$this->event();$event->update(['participation_payment_timing'=>'pay_now','participation_collection_method'=>'bluetick_pay','participation_price'=>10000,'participation_currency'=>'TZS']);$guest=$this->guest($event);$guest->update(['rsvp_status'=>'accepted','attending_count'=>2,'participation_payment_status'=>'pending','confirmation_status'=>'awaiting_payment']);
  $payment=$guest->participationPayments()->create(['reference'=>'OBP-POLL','callback_token'=>str_repeat('p',32),'msisdn'=>'255656414320','network'=>1,'amount'=>20000,'currency'=>'TZS','status'=>'pending','provider_payment_reference'=>'snippe-poll-1']);
  $this->get(route('rsvp.payment.status',$guest->token))->assertOk()->assertJson(['status'=>'paid','confirmed'=>true,'payment_status'=>'paid','message'=>'Payment confirmed. Your access pass is ready.']);
  $this->assertSame('paid',$payment->fresh()->status);$this->assertSame('paid',$guest->fresh()->participation_payment_status);$this->assertNotNull($guest->fresh()->access_pass_issued_at);
 }
 public function test_unified_snippe_webhook_verifies_and_completes_payment_idempotently(): void {
  config(['services.snippe.webhook_secret'=>'whsec_unified']);$event=$this->event();$event->update(['participation_payment_timing'=>'pay_now','participation_price'=>10000,'participation_currency'=>'TZS']);$guest=$this->guest($event);$guest->update(['rsvp_status'=>'accepted','attending_count'=>2,'participation_payment_status'=>'pending','confirmation_status'=>'awaiting_payment']);$payment=$guest->participationPayments()->create(['reference'=>'OBP-UNIFIED','callback_token'=>str_repeat('u',32),'msisdn'=>'255656414320','network'=>1,'amount'=>20000,'currency'=>'TZS','status'=>'pending','provider_payment_reference'=>'snippe-unified-1']);
  $payload=['id'=>'evt-unified-payment-1','type'=>'payment.completed','api_version'=>'2026-01-25','created_at'=>now()->toIso8601String(),'data'=>['reference'=>'snippe-unified-1','external_reference'=>'SEL-123','status'=>'completed','amount'=>['value'=>20000,'currency'=>'TZS'],'customer'=>['phone'=>'+255656414320'],'metadata'=>['okesheni_payment'=>'OBP-UNIFIED'],'completed_at'=>now()->toIso8601String()]];$raw=json_encode($payload);$timestamp=(string)time();$signature=hash_hmac('sha256',$timestamp.'.'.$raw,'whsec_unified');$server=['CONTENT_TYPE'=>'application/json','HTTP_X_WEBHOOK_EVENT'=>'payment.completed','HTTP_X_WEBHOOK_TIMESTAMP'=>$timestamp,'HTTP_X_WEBHOOK_SIGNATURE'=>$signature];
  $this->call('POST',route('api.snippe.webhook'),[],[],[],$server,(string)$raw)->assertOk()->assertJson(['received'=>true,'event_id'=>'evt-unified-payment-1']);
  $this->assertSame('paid',$payment->fresh()->status);$this->assertSame('paid',$guest->fresh()->participation_payment_status);$this->assertNotNull($guest->fresh()->access_pass_issued_at);
  $this->call('POST',route('api.snippe.webhook'),[],[],[],$server,(string)$raw)->assertOk();$this->assertSame('paid',$payment->fresh()->status);
 }
 public function test_unified_snippe_webhook_rejects_bad_signature_and_handles_payout_reversal(): void {
  config(['services.snippe.webhook_secret'=>'whsec_payout']);$event=$this->event();$settlement=$event->settlements()->create(['requested_by'=>$event->user_id,'reference'=>'OSP-UNIFIED','callback_token'=>str_repeat('r',32),'amount'=>15000,'currency'=>'TZS','payment_type'=>'MixxByYas','payment_number'=>'0656414320','status'=>'paid','provider_reference'=>'snippe-payout-unified','paid_at'=>now()]);
  $payload=['id'=>'evt-unified-payout-1','type'=>'payout.reversed','api_version'=>'2026-01-25','created_at'=>now()->toIso8601String(),'data'=>['reference'=>'snippe-payout-unified','external_reference'=>'REV-123','status'=>'reversed','amount'=>['value'=>15000,'currency'=>'TZS'],'metadata'=>['okesheni_settlement'=>'OSP-UNIFIED']]];$raw=json_encode($payload);$timestamp=(string)time();$server=['CONTENT_TYPE'=>'application/json','HTTP_X_WEBHOOK_EVENT'=>'payout.reversed','HTTP_X_WEBHOOK_TIMESTAMP'=>$timestamp,'HTTP_X_WEBHOOK_SIGNATURE'=>'invalid'];
  $this->call('POST',route('api.snippe.webhook'),[],[],[],$server,(string)$raw)->assertUnauthorized();$this->assertSame('paid',$settlement->fresh()->status);
  $server['HTTP_X_WEBHOOK_SIGNATURE']=hash_hmac('sha256',$timestamp.'.'.$raw,'whsec_payout');$this->call('POST',route('api.snippe.webhook'),[],[],[],$server,(string)$raw)->assertOk();$this->assertSame('reversed',$settlement->fresh()->status);
 }
 public function test_bluetick_callback_rejects_mismatched_amount(): void {
  config(['services.snippe.webhook_secret'=>null]);$event=$this->event();$guest=$this->guest($event);$payment=$guest->participationPayments()->create(['reference'=>'OBP-MISMATCH','callback_token'=>str_repeat('z',64),'msisdn'=>'255754000000','network'=>3,'amount'=>10000,'currency'=>'TZS','status'=>'pending','provider_payment_reference'=>'snippe-mismatch']);
  $this->postJson(route('api.payments.callback',$payment->callback_token),['type'=>'payment.completed','data'=>['reference'=>'snippe-mismatch','status'=>'completed','amount'=>['value'=>1,'currency'=>'TZS'],'customer'=>['phone'=>'+255754000000']]])->assertStatus(422);
  $this->assertSame('pending',$payment->fresh()->status);
 }
 public function test_snippe_webhook_signature_and_replay_window_are_enforced(): void {
  config(['services.snippe.webhook_secret'=>'whsec_test']);$event=$this->event();$event->update(['participation_payment_timing'=>'pay_now','participation_price'=>10000,'participation_currency'=>'TZS']);$guest=$this->guest($event);$guest->update(['rsvp_status'=>'accepted','attending_count'=>1]);$payment=$guest->participationPayments()->create(['reference'=>'OBP-SIGNED','callback_token'=>str_repeat('s',64),'msisdn'=>'255754000000','network'=>3,'amount'=>10000,'currency'=>'TZS','status'=>'pending','provider_payment_reference'=>'snippe-signed']);
  $payload=['id'=>'evt-signed','type'=>'payment.completed','api_version'=>'2026-01-25','data'=>['reference'=>'snippe-signed','status'=>'completed','amount'=>['value'=>10000,'currency'=>'TZS'],'customer'=>['phone'=>'+255754000000']]];$raw=json_encode($payload);$timestamp=(string)time();$signature=hash_hmac('sha256',$timestamp.'.'.$raw,'whsec_test');
  $this->call('POST',route('api.payments.callback',$payment->callback_token),[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_WEBHOOK_TIMESTAMP'=>$timestamp,'HTTP_X_WEBHOOK_SIGNATURE'=>'invalid'],(string)$raw)->assertUnauthorized();
  $staleTimestamp=(string)(time()-301);$staleSignature=hash_hmac('sha256',$staleTimestamp.'.'.$raw,'whsec_test');$this->call('POST',route('api.payments.callback',$payment->callback_token),[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_WEBHOOK_TIMESTAMP'=>$staleTimestamp,'HTTP_X_WEBHOOK_SIGNATURE'=>$staleSignature],(string)$raw)->assertUnauthorized();
  $this->call('POST',route('api.payments.callback',$payment->callback_token),[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_WEBHOOK_TIMESTAMP'=>$timestamp,'HTTP_X_WEBHOOK_SIGNATURE'=>$signature],(string)$raw)->assertOk();
  $this->assertSame('paid',$payment->fresh()->status);
 }
 public function test_paid_event_has_a_dedicated_payment_workspace_and_can_withdraw_available_collections(): void {
  config(['services.snippe.key'=>'snp_test_key','services.snippe.webhook_secret'=>null,'services.snippe.webhook_base_url'=>'https://okesheni.example']);Http::fake(['api.snippe.sh/v1/payouts/send'=>Http::response(['status'=>'success','code'=>201,'data'=>['reference'=>'snippe-payout-1','status'=>'pending','amount'=>['value'=>15000,'currency'=>'TZS']]],201)]);
  $event=$this->event();$this->verifyForPayments($event);$event->update(['participation_payment_timing'=>'pay_now','participation_collection_method'=>'bluetick_pay','participation_price'=>10000,'participation_currency'=>'TZS','payout_payment_type'=>'MixxByYas','payout_number'=>'0656414320']);$guest=$this->guest($event);$guest->update(['rsvp_status'=>'accepted','attending_count'=>2,'participation_payment_status'=>'paid']);
  $payment=$guest->participationPayments()->create(['reference'=>'OBP-COLLECTED','callback_token'=>str_repeat('c',32),'msisdn'=>'255656414320','network'=>1,'amount'=>20000,'currency'=>'TZS','status'=>'paid','paid_at'=>now()]);$organiser=User::findOrFail($event->user_id);
  $failedGuest=$event->invitees()->create(['name'=>'Failed Payer','email'=>'failed-payer@example.com','identity_key'=>hash('sha256','failed-payer@example.com'),'token'=>str_repeat('f',64),'max_guests'=>1]);
  $failedGuest->participationPayments()->create(['reference'=>'OBP-FAILED','callback_token'=>str_repeat('d',32),'msisdn'=>'255754000000','network'=>3,'amount'=>10000,'currency'=>'TZS','status'=>'request_failed','failed_at'=>now()]);
  $this->actingAs($organiser)->get(route('events.show',$event))->assertOk()->assertSee('Payment snapshot')->assertSee('Open payments')->assertSee('TZS 20,000.00')->assertDontSee('OBP-COLLECTED')->assertDontSee('Withdraw to selected account');
  $this->get(route('events.payments',$event))->assertOk()->assertViewHas('workspaceTab','payments')->assertSee('event-workspace-payments',false)->assertSee('Collections and settlement')->assertSee('OBP-COLLECTED')->assertSee('Withdraw to selected account')->assertSee('Send to another account')->assertSee('name="use_other_account"',false)->assertSee('name="payment_q"',false)->assertSee('name="payment_status"',false);
  $this->get(route('events.payments',$event).'?payment_status=paid')->assertOk()->assertSee('OBP-COLLECTED')->assertDontSee('OBP-FAILED');
  $this->get(route('events.payments',$event).'?payment_status=failed')->assertOk()->assertSee('OBP-FAILED')->assertDontSee('OBP-COLLECTED');
  $this->get(route('events.payments',$event).'?payment_q=Failed+Payer')->assertOk()->assertSee('OBP-FAILED')->assertDontSee('OBP-COLLECTED');
  $this->actingAs($organiser)->post(route('events.settlements.store',$event),['amount'=>15000])->assertRedirect()->assertSessionHas('success');
  $settlement=\App\Models\EventSettlement::where('event_id',$event->id)->firstOrFail();$this->assertSame('pending',$settlement->status);$this->assertSame(15000.0,(float)$settlement->amount);
  Http::assertSent(fn($request)=>$request->url()==='https://api.snippe.sh/v1/payouts/send'&&$request->hasHeader('Authorization','Bearer snp_test_key')&&$request['channel']==='mobile'&&$request['recipient_phone']==='255656414320'&&$request['webhook_url']==='https://okesheni.example/api/snippe/webhook');
  $this->actingAs($organiser)->post(route('events.settlements.store',$event),['amount'=>6000])->assertSessionHasErrors('amount');
  $this->postJson(route('api.settlements.callback',$settlement->callback_token),['type'=>'payout.completed','data'=>['reference'=>'snippe-payout-1','external_reference'=>'OUT-123','status'=>'completed','amount'=>['value'=>15000,'currency'=>'TZS']]])->assertOk();
  $this->assertSame('paid',$settlement->fresh()->status);
 }
 public function test_organiser_can_withdraw_to_a_one_time_alternate_settlement_account(): void {
  config(['services.snippe.key'=>'snp_test_key','services.snippe.webhook_base_url'=>null]);Http::fake(['api.snippe.sh/v1/payouts/send'=>Http::response(['status'=>'success','code'=>201,'data'=>['reference'=>'snippe-alternate-payout','status'=>'pending','amount'=>['value'=>10000,'currency'=>'TZS']]],201)]);
  $event=$this->event();$this->verifyForPayments($event);$event->update(['participation_payment_timing'=>'pay_now','participation_collection_method'=>'bluetick_pay','participation_price'=>10000,'participation_currency'=>'TZS','payout_payment_type'=>'MixxByYas','payout_number'=>'0656414320']);$guest=$this->guest($event);$guest->update(['rsvp_status'=>'accepted','attending_count'=>2,'participation_payment_status'=>'paid']);
  $guest->participationPayments()->create(['reference'=>'OBP-ALTERNATE','callback_token'=>str_repeat('x',32),'msisdn'=>'255656414320','network'=>1,'amount'=>20000,'currency'=>'TZS','status'=>'paid','paid_at'=>now()]);$organiser=User::findOrFail($event->user_id);
  $this->actingAs($organiser)->post(route('events.settlements.store',$event),['amount'=>10000,'use_other_account'=>1])->assertSessionHasErrors(['account_name','payment_type','payment_number']);
  $this->post(route('events.settlements.store',$event),['amount'=>10000,'use_other_account'=>1,'account_name'=>'Alternate Treasurer','payment_type'=>'AirtelMoney','payment_number'=>'invalid'])->assertSessionHasErrors('payment_number');
  $this->post(route('events.settlements.store',$event),['amount'=>10000,'use_other_account'=>1,'account_name'=>'Alternate Treasurer','payment_type'=>'AirtelMoney','payment_number'=>'0689 123 456'])->assertRedirect()->assertSessionHas('success');
  $settlement=$event->settlements()->firstOrFail();$this->assertSame('Alternate Treasurer',$settlement->account_name);$this->assertSame('AirtelMoney',$settlement->payment_type);$this->assertSame('255689123456',$settlement->payment_number);$this->assertSame('MixxByYas',$event->fresh()->payout_payment_type);$this->assertSame('0656414320',$event->payout_number);
  Http::assertSent(fn($request)=>$request->url()==='https://api.snippe.sh/v1/payouts/send'&&$request['channel']==='mobile'&&$request['recipient_name']==='Alternate Treasurer'&&$request['recipient_phone']==='255689123456');
  $this->get(route('events.payments',$event))->assertOk()->assertSee('Alternate Treasurer')->assertSee('AirtelMoney')->assertSee('••••3456');
 }
 public function test_manual_payment_requests_can_be_filtered_by_status_and_reference(): void {
  $event=$this->event();$event->update(['participation_payment_timing'=>'pay_now','participation_collection_method'=>'manual','participation_price'=>5000,'participation_currency'=>'TZS']);$organiser=User::findOrFail($event->user_id);
  $pending=$this->guest($event);$pending->update(['name'=>'Pending Manual','rsvp_status'=>'accepted','attending_count'=>1,'participation_payment_status'=>'payment_reported','participation_payment_reference'=>'MAN-PENDING','responded_at'=>now()]);
  $paid=$event->invitees()->create(['name'=>'Paid Manual','email'=>'paid-manual@example.com','identity_key'=>hash('sha256','paid-manual@example.com'),'token'=>str_repeat('m',64),'max_guests'=>1,'rsvp_status'=>'accepted','attending_count'=>1,'participation_payment_status'=>'paid','participation_payment_reference'=>'MAN-PAID','responded_at'=>now()]);
  $this->actingAs($organiser)->get(route('events.payments',$event).'?payment_status=pending')->assertOk()->assertSee('MAN-PENDING')->assertDontSee('MAN-PAID');
  $this->get(route('events.payments',$event).'?payment_q=MAN-PAID')->assertOk()->assertSee('Paid Manual')->assertDontSee('Pending Manual');
 }
 public function test_free_event_overview_preserves_existing_page_without_payment_operations(): void {
  $event=$this->event();$organiser=User::findOrFail($event->user_id);
  $this->actingAs($organiser)->get(route('events.show',$event))->assertOk()->assertSee('Keep your event moving')->assertDontSee('Collections and settlement');
  $this->get(route('events.payments',$event))->assertNotFound();
 }
 public function test_configurable_rsvp_fields_and_organizer_approval_control_qr_access(): void {
  $event=$this->event();$event->update(['visibility'=>'link','rsvp_form_preset'=>'professional','rsvp_confirmation_mode'=>'organizer_approval','rsvp_field_config'=>['email'=>['enabled'=>true,'required'=>true],'phone'=>['enabled'=>false,'required'=>false],'dietary'=>['enabled'=>false,'required'=>false],'organization'=>['enabled'=>true,'required'=>true],'job_title'=>['enabled'=>true,'required'=>false],'accessibility'=>['enabled'=>false,'required'=>false],'notes'=>['enabled'=>false,'required'=>false]]]);
  $this->get($event->registrationUrl())->assertOk()->assertSee('Organization')->assertSee('Job title')->assertDontSee('Dietary requirements')->assertDontSee('registration-phone');
  $this->post($event->registrationUrl(),['name'=>'Professional Guest','email'=>'pro@example.com','rsvp_status'=>'accepted','attending_count'=>1])->assertSessionHasErrors('responses.organization');
  $this->post($event->registrationUrl(),['name'=>'Professional Guest','email'=>'pro@example.com','rsvp_status'=>'accepted','attending_count'=>1,'responses'=>['organization'=>'Bluetick','job_title'=>'Director']])->assertRedirect();
  $guest=$event->invitees()->where('email','pro@example.com')->firstOrFail();$this->assertSame('awaiting_approval',$guest->confirmation_status);$this->assertSame('Bluetick',$guest->rsvp_answers['organization']);
  $this->get(route('rsvp.pass.show',$guest->token))->assertForbidden();$organiser=User::findOrFail($event->user_id);
  $this->actingAs($organiser)->put(route('events.rsvp-confirmations.update',[$event,$guest]),['confirmation_status'=>'confirmed'])->assertRedirect()->assertSessionHas('success');
  $this->assertTrue($guest->fresh()->accessPassEligible());$this->get(route('rsvp.pass.show',$guest->token))->assertOk();
 }
 public function test_whatsapp_number_can_be_required_and_is_shown_in_the_participant_list(): void {
  $event=$this->event();$event->update(['visibility'=>'link','rsvp_field_config'=>['email'=>['enabled'=>true,'required'=>true],'phone'=>['enabled'=>false,'required'=>false],'whatsapp'=>['enabled'=>true,'required'=>true],'dietary'=>['enabled'=>false,'required'=>false]]]);
  $this->get($event->registrationUrl())->assertOk()->assertSee('WhatsApp number')->assertSee('registration-whatsapp');
  $payload=['name'=>'WhatsApp Guest','email'=>'whatsapp@example.com','rsvp_status'=>'accepted','attending_count'=>1];
  $this->post($event->registrationUrl(),$payload)->assertSessionHasErrors('responses.whatsapp');
  $this->post($event->registrationUrl(),array_merge($payload,['responses'=>['whatsapp'=>'+255 784 311 111']]))->assertRedirect();
  $guest=$event->invitees()->where('email','whatsapp@example.com')->firstOrFail();$this->assertSame('+255784311111',$guest->rsvp_answers['whatsapp']);
  $organiser=User::findOrFail($event->user_id);$this->actingAs($organiser)->get(route('events.participants',$event))->assertOk()->assertSee('WhatsApp: +255784311111');
 }
 public function test_confirmed_guest_can_view_and_download_a_qr_access_pass(): void {
  $event=$this->event();$guest=$this->guest($event);
  $this->post($guest->rsvpUrl(),['rsvp_status'=>'accepted','attending_count'=>2])->assertRedirect();
  $guest->refresh();$this->assertNotNull($guest->access_pass_issued_at);
  $this->get(route('rsvp.pass.show',$guest->token))->assertOk()->assertSee('Verified access pass')->assertSee('admissions allowed');
  $this->get(route('rsvp.pass.qr',$guest->token))->assertOk()->assertHeader('content-type','image/svg+xml')->assertSee('<svg',false)->assertSee('#56283f',false);
  $this->get(route('rsvp.pass.qr',[$guest->token,'download'=>1]))->assertHeader('content-disposition','attachment; filename="okesheni-pass-'.$event->public_code.'.svg"');
 }
 public function test_pay_now_access_pass_is_locked_until_payment_is_verified(): void {
  $event=$this->event();$event->update(['participation_payment_timing'=>'pay_now','participation_collection_method'=>'manual','participation_price'=>10000]);$guest=$this->guest($event);
  $this->post($guest->rsvpUrl(),['rsvp_status'=>'accepted','attending_count'=>1,'participation_payment_reference'=>'PAY-1','checkout_acknowledged'=>1])->assertRedirect();
  $this->get(route('rsvp.pass.show',$guest->token))->assertForbidden();
  $organiser=User::findOrFail($event->user_id);$this->actingAs($organiser)->put(route('events.participation-payments.update',[$event,$guest]),['status'=>'paid'])->assertRedirect();
  $this->get(route('rsvp.pass.show',$guest->token))->assertOk();
 }
 public function test_scanner_enforces_invitation_allowance_and_records_partial_admissions(): void {
  $event=$this->event();$guest=$this->guest($event);$guest->update(['rsvp_status'=>'accepted','attending_count'=>2,'participation_payment_status'=>'not_required','access_pass_issued_at'=>now()]);$organiser=User::findOrFail($event->user_id);
  $this->actingAs($organiser)->get(route('events.scanner',$event))->assertOk()->assertSee('Scan access passes');
  $this->actingAs($organiser)->get(route('check-in.show',$guest->access_token))->assertOk()->assertSee('2')->assertSee($guest->name);
  $this->actingAs($organiser)->post(route('check-in.store',$guest->access_token),['admitted_count'=>1])->assertRedirect()->assertSessionHas('success');
  $this->assertDatabaseHas('event_check_ins',['invitee_id'=>$guest->id,'operator_id'=>$organiser->id,'admitted_count'=>1,'result'=>'admitted']);
  $this->assertSame(1,$guest->fresh()->remainingAdmissions());
  $this->actingAs($organiser)->post(route('check-in.store',$guest->access_token),['admitted_count'=>2])->assertSessionHasErrors('admitted_count');
  $this->assertSame(1,$guest->fresh()->attended_count);
  $this->actingAs($organiser)->post(route('check-in.store',$guest->access_token),['admitted_count'=>1])->assertSessionHasNoErrors();
  $this->assertSame(0,$guest->fresh()->remainingAdmissions());
  $this->actingAs($organiser)->post(route('check-in.store',$guest->access_token),['admitted_count'=>1])->assertSessionHasErrors('admitted_count');
 }
 public function test_pay_at_event_check_in_requires_collection_confirmation_and_regeneration_revokes_old_pass(): void {
  $event=$this->event();$event->update(['participation_payment_timing'=>'pay_at_event','participation_price'=>5000]);$guest=$this->guest($event);$guest->update(['rsvp_status'=>'accepted','attending_count'=>1,'participation_payment_status'=>'due_at_event']);$organiser=User::findOrFail($event->user_id);$oldToken=$guest->access_token;
  $this->actingAs($organiser)->post(route('check-in.store',$oldToken),['admitted_count'=>1])->assertSessionHasErrors('collect_at_event');
  $this->actingAs($organiser)->post(route('check-in.store',$oldToken),['admitted_count'=>1,'collect_at_event'=>1])->assertSessionHasNoErrors();
  $this->assertSame('paid',$guest->fresh()->participation_payment_status);
  $this->actingAs($organiser)->put(route('events.access-pass.regenerate',[$event,$guest]))->assertRedirect()->assertSessionHas('success');
  $guest->refresh();$this->assertNotSame($oldToken,$guest->access_token);
  $this->actingAs($organiser)->get(route('check-in.show',$oldToken))->assertNotFound();
  $this->actingAs($organiser)->get(route('check-in.show',$guest->access_token))->assertOk();
 }
}
