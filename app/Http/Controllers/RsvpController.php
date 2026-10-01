<?php
namespace App\Http\Controllers;
use App\Models\Invitee;
use Illuminate\Http\Request;
class RsvpController {
 public function show(string $token){$guest=Invitee::where('token',$token)->with('event')->firstOrFail();return view('rsvp',['guest'=>$guest,'event'=>$guest->event,'closed'=>$guest->event->rsvp_deadline->endOfDay()->isPast()]);}
 public function update(Request $r,string $token){$g=Invitee::where('token',$token)->with('event')->firstOrFail();abort_if($g->event->rsvp_deadline->endOfDay()->isPast(),410,'RSVP is closed. Please contact the organiser.');$v=$r->validate(['rsvp_status'=>'required|in:accepted,declined','attending_count'=>'required_if:rsvp_status,accepted|nullable|integer|min:1|max:'.$g->max_guests,'dietary'=>'nullable|string|max:1000']);$v['attending_count']=$v['rsvp_status']==='accepted'?($v['attending_count']??1):0;$v['responded_at']=now();$g->update($v);return back()->with('success','Thank you. Your response has been saved.');}
}
