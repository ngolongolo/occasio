<?php
namespace App\Jobs;
use App\Mail\InvitationMail;
use App\Models\Delivery;
use App\Services\InvitationTemplate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\{Http,Mail};
use Illuminate\Http\Client\{ConnectionException, RequestException};
use Illuminate\Support\Facades\Log;
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
 if($d->channel==='whatsapp'){
 $c=config('services.twilio');foreach(['sid','token'] as $key)if(empty($c[$key]))throw new \RuntimeException('WhatsApp credentials are not configured.');
 if(empty($c['whatsapp_from'])||empty($c['content_sid']))throw new \RuntimeException('WhatsApp sender and approved content template are required.');
 $payload=['To'=>'whatsapp:'.$g->phone,'From'=>'whatsapp:'.$c['whatsapp_from'],'ContentSid'=>$c['content_sid'],'ContentVariables'=>json_encode(['1'=>$g->name,'2'=>$e->title,'3'=>$e->starts_at->format('d M Y H:i'),'4'=>$g->rsvpUrl()])];
 $response=Http::withBasicAuth($c['sid'],$c['token'])->asForm()->timeout(25)->post('https://api.twilio.com/2010-04-01/Accounts/'.$c['sid'].'/Messages.json',$payload)->throw();
 $providerId=$response->json('sid');
 }else{
 $c=config('services.sprint_sms');foreach(['url','api_id','api_password','sender_id'] as $key)if(empty($c[$key]))throw new \RuntimeException('Sprint SMS credentials and sender are not configured.');
 $response=Http::timeout(25)->get($c['url'],[
 'api_id'=>$c['api_id'],'api_password'=>$c['api_password'],'sms_type'=>'P','encoding'=>'T',
 'sender_id'=>$c['sender_id'],'phonenumber'=>$g->phone,'textmessage'=>InvitationTemplate::sms($g,$e),
 ])->throw();
 $providerId=$response->json('message_id')??$response->json('id')??$response->json('request_id');
 }
 $d->update(['status'=>'submitted','provider_id'=>$providerId]);
 }catch(RequestException $x){
  $status=$x->response->status();
  $temporary=$status===429||$status>=500;
  $d->update(['status'=>'failed','error'=>$temporary?"Messaging provider temporarily unavailable (HTTP {$status}). Retry this delivery later.":"Messaging provider rejected the request (HTTP {$status}). Check the channel configuration before retrying."]);
  Log::warning('Invitation provider request failed.',['delivery_id'=>$d->id,'channel'=>$d->channel,'http_status'=>$status,'temporary'=>$temporary]);
 }catch(ConnectionException $x){
  $d->update(['status'=>'failed','error'=>'Could not connect to the messaging provider. Retry this delivery later.']);
  Log::warning('Invitation provider connection failed.',['delivery_id'=>$d->id,'channel'=>$d->channel]);
 }catch(\Throwable $x){$d->update(['status'=>'failed','error'=>'Provider submission failed. Check credentials, sender, template and provider logs before retrying.']);report($x);}
 }
}
