<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use App\Models\{User,Event,Invitee,Delivery};
use App\Services\GuestImporter;
use App\Services\InvitationTemplate;
use App\Jobs\SendInvitation;
use App\Mail\InvitationMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
class InvitationTest extends TestCase {
 use RefreshDatabase;
 private function event(): Event {$u=User::create(['name'=>'Host','email'=>uniqid().'@example.com','password'=>'passwordlong']);return Event::create(['user_id'=>$u->id,'title'=>'Gala','host'=>'HTAF','description'=>'Welcome','starts_at'=>now()->addDays(30),'rsvp_deadline'=>now()->addDays(20),'venue'=>'Mwanza']);}
 private function guest(Event $e): Invitee {return $e->invitees()->create(['name'=>'Guest','email'=>'guest@example.com','identity_key'=>hash('sha256','guest@example.com'),'token'=>str_repeat('a',64),'max_guests'=>2]);}
 public function test_organiser_cannot_access_another_event(): void {$e=$this->event();$other=User::create(['name'=>'Other','email'=>'other@example.com','password'=>'passwordlong']);$this->actingAs($other)->get('/events/'.$e->id)->assertForbidden();$this->actingAs($other)->post('/events/'.$e->id.'/send',['channels'=>['email'],'consent'=>1])->assertForbidden();}
 public function test_rsvp_limits_and_decline(): void {$g=$this->guest($this->event());$url='/rsvp/'.$g->token;$this->post($url,['rsvp_status'=>'accepted','attending_count'=>3])->assertSessionHasErrors('attending_count');$this->post($url,['rsvp_status'=>'accepted','attending_count'=>2])->assertRedirect();$this->assertDatabaseHas('invitees',['id'=>$g->id,'attending_count'=>2]);$this->post($url,['rsvp_status'=>'declined'])->assertRedirect();$this->assertDatabaseHas('invitees',['id'=>$g->id,'rsvp_status'=>'declined','attending_count'=>0]);}
 public function test_expired_links_reject_changes(): void {$e=$this->event();$e->update(['rsvp_deadline'=>now()->subDay()]);$g=$this->guest($e);$this->post('/rsvp/'.$g->token,['rsvp_status'=>'accepted','attending_count'=>1])->assertStatus(410);$this->get('/rsvp/unknown')->assertNotFound();}
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
 public function test_organiser_can_add_edit_and_delete_a_guest(): void {
  $event=$this->event();
  $organiser=User::findOrFail($event->user_id);
  $this->actingAs($organiser)->post(route('events.guests.store',$event),['name'=>'Jane Guest','email'=>'JANE@example.com','phone'=>'','max_guests'=>3])->assertRedirect(route('events.show',$event));
  $guest=$event->invitees()->where('email','jane@example.com')->firstOrFail();
  $this->actingAs($organiser)->get(route('events.guests.edit',[$event,$guest]))->assertOk()->assertSee('Jane Guest')->assertSee('jane@example.com');
  $this->actingAs($organiser)->put(route('events.guests.update',[$event,$guest]),['name'=>'Jane Updated','email'=>'jane@example.com','phone'=>'+255 784 311 111','max_guests'=>2])->assertRedirect(route('events.show',$event));
  $this->assertDatabaseHas('invitees',['id'=>$guest->id,'name'=>'Jane Updated','phone'=>'+255784311111','max_guests'=>2]);
  $this->actingAs($organiser)->delete(route('events.guests.destroy',[$event,$guest]))->assertRedirect(route('events.show',$event));
  $this->assertDatabaseMissing('invitees',['id'=>$guest->id]);
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
  $this->get(route('guest-template'))->assertOk()->assertDownload('occasio-guest-import-template.xlsx');
 }
 public function test_organiser_can_save_branding_card_and_sms_template(): void {
  Storage::fake('local');
  $event=$this->event();$organiser=User::findOrFail($event->user_id);
  $logo=UploadedFile::fake()->image('logo.png',120,60);
  $card=UploadedFile::fake()->create('gala-card.pdf',100,'application/pdf');
  $template='Hi {guest_name}, join {event_title} at {venue}. RSVP: {rsvp_url}';
  $this->actingAs($organiser)->put(route('events.design.update',$event),['primary_color'=>'#123456','logo'=>$logo,'invitation_card'=>$card,'sms_template'=>$template])->assertRedirect(route('events.design',$event));
  $event->refresh();
  $this->assertSame('#123456',$event->primary_color);
  $this->assertSame($template,$event->sms_template);
  Storage::disk('local')->assertExists($event->logo_path);
  Storage::disk('local')->assertExists($event->invitation_card_path);
  $guest=$this->guest($event);
  $this->assertStringContainsString('Hi Guest, join Gala at Mwanza.',InvitationTemplate::sms($guest,$event));
  $mail=new InvitationMail($guest);
  $attachments=$mail->attachments();
  $this->assertCount(1,$attachments);
  $this->assertSame('gala-card.pdf',$attachments[0]->as);
  $this->assertSame('application/pdf',$attachments[0]->mime);
 }
 public function test_other_organiser_cannot_change_invitation_design(): void {
  $event=$this->event();$other=$this->event();$otherOrganiser=User::findOrFail($other->user_id);
  $this->actingAs($otherOrganiser)->put(route('events.design.update',$event),['primary_color'=>'#123456','sms_template'=>'Hello {guest_name}'])->assertForbidden();
 }
 public function test_selected_guest_can_receive_and_resend_invitation(): void {
  Queue::fake();
  $event=$this->event();$organiser=User::findOrFail($event->user_id);$selected=$this->guest($event);
  $unselected=$event->invitees()->create(['name'=>'Other','email'=>'other-guest@example.com','identity_key'=>hash('sha256','other-guest@example.com'),'token'=>str_repeat('b',64),'max_guests'=>1]);
  $delivery=Delivery::create(['invitee_id'=>$selected->id,'channel'=>'email','status'=>'submitted','provider_id'=>'old-id']);
  $this->actingAs($organiser)->post('/events/'.$event->id.'/send',['guest_ids'=>[$selected->id],'channels'=>['email'],'consent'=>1])->assertRedirect()->assertSessionHas('success');
  $this->assertDatabaseHas('deliveries',['id'=>$delivery->id,'status'=>'queued','provider_id'=>null]);
  $this->assertDatabaseMissing('deliveries',['invitee_id'=>$unselected->id]);
  Queue::assertPushed(SendInvitation::class,fn(SendInvitation $job)=>$job->deliveryId===$delivery->id);
 }
 public function test_send_requires_a_selected_guest(): void {
  $event=$this->event();$organiser=User::findOrFail($event->user_id);
  $this->actingAs($organiser)->post('/events/'.$event->id.'/send',['channels'=>['email'],'consent'=>1])->assertSessionHasErrors('guest_ids');
 }
 public function test_custom_sms_always_includes_confirmation_link(): void {
  $event=$this->event();$event->update(['sms_template'=>'Welcome {guest_name} to {event_title}.']);$guest=$this->guest($event);
  $this->assertStringContainsString($guest->rsvpUrl(),InvitationTemplate::sms($guest,$event));
 }
}
