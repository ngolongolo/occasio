<?php
namespace App\Jobs;
use App\Mail\InvitationMail;
use App\Models\Delivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\{Http,Mail};
class SendInvitation implements ShouldQueue {
 use Queueable;
 public int $tries=1; public int $timeout=60;
 public function __construct(public int $deliveryId){}
 public function handle(): void {
 $d=Delivery::with('invitee.event')->findOrFail($this->deliveryId);if($d->status!=='queued')return;
 $d->update(['status'=>'processing']);$g=$d->invitee;$e=$g->event;
 if(config('services.delivery_mode')!=='live'){$d->update(['status'=>'previewed']);return;}
 try {
 if($d->channel==='email'){
 if(config('mail.default')!=='smtp')throw new \RuntimeException('Configure SMTP before live email sending.');
 Mail::to($g->email,$g->name)->send(new InvitationMail($g));$d->update(['status'=>'submitted','error'=>null]);return;
 }
 $c=config('services.twilio');foreach(['sid','token'] as $key)if(empty($c[$key]))throw new \RuntimeException('Messaging credentials are not configured.');
 if($d->channel==='whatsapp'){
 if(empty($c['whatsapp_from'])||empty($c['content_sid']))throw new \RuntimeException('WhatsApp sender and approved content template are required.');
 $payload=['To'=>'whatsapp:'.$g->phone,'From'=>'whatsapp:'.$c['whatsapp_from'],'ContentSid'=>$c['content_sid'],'ContentVariables'=>json_encode(['1'=>$g->name,'2'=>$e->title,'3'=>$e->starts_at->format('d M Y H:i'),'4'=>$g->rsvpUrl()])];
 }else{
 if(empty($c['sms_from']))throw new \RuntimeException('SMS sender is not configured.');
 $payload=['To'=>$g->phone,'From'=>$c['sms_from'],'Body'=>"Hello {$g->name}, {$e->host} invites you to {$e->title} on ".$e->starts_at->format('d M Y H:i').". RSVP: ".$g->rsvpUrl()];
 }
 $response=Http::withBasicAuth($c['sid'],$c['token'])->asForm()->timeout(25)->post('https://api.twilio.com/2010-04-01/Accounts/'.$c['sid'].'/Messages.json',$payload)->throw();
 $d->update(['status'=>'submitted','provider_id'=>$response->json('sid')]);
 }catch(\Throwable $x){$d->update(['status'=>'failed','error'=>'Provider submission failed. Check credentials, sender, template and provider logs before retrying.']);report($x);}
 }
}
