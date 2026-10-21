<?php
namespace App\Http\Controllers;
use App\Models\Invitee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
class RsvpController {
 public function show(string $token){$guest=Invitee::where('token',$token)->with(['event','pledge'])->firstOrFail();return view('rsvp',['guest'=>$guest,'event'=>$guest->event,'closed'=>$guest->event->responsesClosed()]);}
 public function card(Request $request,string $token){$guest=Invitee::where('token',$token)->with('event')->firstOrFail();$event=$guest->event;$path=$request->boolean('preview')&&$event->invitation_card_preview_path?$event->invitation_card_preview_path:$event->invitation_card_path;abort_unless($path&&Storage::disk('local')->exists($path),404);return Storage::disk('local')->response($path,$request->boolean('preview')?'invitation-preview.png':$event->invitation_card_name,['Cache-Control'=>'private, max-age=3600']);}
 public function update(Request $r,string $token){
  $g=Invitee::where('token',$token)->with('event')->firstOrFail();$e=$g->event;
  abort_if($e->responsesClosed(),410,'Responses are closed because this event has started. Please contact the organiser.');
  $r->merge(['response_type'=>$r->input('response_type','participation')]);
  $type=$r->validate(['response_type'=>'required|in:participation,pledge'])['response_type'];
  if($type==='participation'){
   abort_unless($e->participation_enabled,404);
   $v=$r->validate(['rsvp_status'=>'required|in:accepted,declined','attending_count'=>'required_if:rsvp_status,accepted|nullable|integer|min:1|max:'.$g->max_guests,'dietary'=>'nullable|string|max:1000']);
   $v['attending_count']=$v['rsvp_status']==='accepted'?($v['attending_count']??1):0;$v['responded_at']=now();$g->update($v);
   return back()->with('success','Thank you. Your participation response has been saved.');
  }
  abort_unless($e->pledge_enabled,404);
  abort_if(in_array($g->pledge?->status, ['paid', 'overpaid'], true),422,'A fully paid pledge cannot be changed. Please contact the organiser.');
  $v=$r->validate(['amount'=>'required|numeric|min:1|max:999999999999.99','payment_method'=>'nullable|string|max:100','payment_reference'=>'nullable|string|max:150','message'=>'nullable|string|max:1000']);
  $status=!empty($v['payment_reference'])?'payment_reported':'pledged';
  $g->pledge()->updateOrCreate([],array_merge($v,['status'=>$status,'pledged_at'=>now(),'confirmed_at'=>null]));
  return back()->with('success','Thank you. Your pledge has been recorded. Please use the payment details shown below.');
 }
}
