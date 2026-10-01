<?php
namespace App\Http\Controllers;
use App\Models\{Event,Delivery};
use App\Jobs\SendInvitation;
use App\Services\GuestImporter;
use Illuminate\Http\Request;
class EventController {
 private function own(Event $event){abort_unless($event->user_id===auth()->id(),403);}
 public function index(){return view('events.index',['events'=>auth()->user()->events()->withCount('invitees')->latest()->get()]);}
 public function store(Request $r){$v=$r->validate(['title'=>'required|string|max:180','host'=>'required|string|max:180','description'=>'required|string|max:5000','starts_at'=>'required|date|after:now','rsvp_deadline'=>'required|date|before_or_equal:starts_at','venue'=>'required|string|max:255','dress_code'=>'nullable|string|max:180','contact'=>'nullable|string|max:100']);$e=auth()->user()->events()->create($v);return redirect()->route('events.show',$e);}
 public function show(Event $event){$this->own($event);return view('events.show',['event'=>$event,'invitees'=>$event->invitees()->with('deliveries')->orderBy('name')->paginate(50),'stats'=>['total'=>$event->invitees()->count(),'accepted'=>$event->invitees()->where('rsvp_status','accepted')->count(),'declined'=>$event->invitees()->where('rsvp_status','declined')->count(),'seats'=>$event->invitees()->sum('attending_count')]]);}
 public function import(Request $r,Event $event,GuestImporter $importer){$this->own($event);$r->validate(['file'=>'required|file|mimes:xlsx,csv,txt|max:5120']);$report=$importer->import($r->file('file'),$event);return back()->with('success',"Imported {$report['imported']} guests; skipped {$report['duplicates']} duplicates.")->with('import_errors',$report['errors']);}
 public function send(Request $r,Event $event){$this->own($event);$v=$r->validate(['channels'=>'required|array|min:1','channels.*'=>'required|in:email,sms,whatsapp','consent'=>'accepted']);abort_if($event->rsvp_deadline->endOfDay()->isPast(),422,'RSVP deadline has passed.');$count=0;
 foreach($event->invitees()->cursor() as $guest)foreach(array_unique($v['channels']) as $channel){if(($channel==='email'&&!$guest->email)||($channel!=='email'&&!$guest->phone))continue;$d=Delivery::firstOrCreate(['invitee_id'=>$guest->id,'channel'=>$channel]);if($d->wasRecentlyCreated || in_array($d->status,['failed','previewed'])){if($d->wasRecentlyCreated || Delivery::whereKey($d->id)->whereIn('status',['failed','previewed'])->update(['status'=>'queued','error'=>null])){SendInvitation::dispatch($d->id);$count++;}}}
 return back()->with('success',"Queued {$count} invitations. Existing accepted submissions are not resent.");}
 public function export(Event $event){$this->own($event);return response()->streamDownload(function()use($event){$f=fopen('php://output','w');fputcsv($f,['name','email','phone','rsvp','attending_count','dietary']);foreach($event->invitees()->cursor() as $g){$row=[$g->name,$g->email,$g->phone,$g->rsvp_status,$g->attending_count,$g->dietary];fputcsv($f,array_map(fn($x)=>preg_match('/^[=+@\-]/',(string)$x)?"'".$x:$x,$row));}fclose($f);},'guest-responses.csv',['Content-Type'=>'text/csv']);}
}
