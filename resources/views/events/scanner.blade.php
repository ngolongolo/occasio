@extends('layout')
@section('title',$event->title.' · Entry scanner')
@section('content')
<main class="scanner-page">
    <header class="scanner-header"><div><a href="{{ route('events.show',$event) }}">← Event workspace</a><span class="eyebrow">Entrance control</span><h1>Scan access passes</h1><p>{{ $event->title }} · {{ $event->starts_at->format('d M Y, g:i A') }}</p></div><span class="scanner-live"><i></i> Live check-in</span></header>
    @include('partials.alerts')
    <div class="scanner-layout">
        <section class="scanner-panel">
            <div class="camera-frame"><video id="scanner-video" playsinline muted></video><div class="camera-target" aria-hidden="true"></div><div id="camera-message">Tap below to start the camera</div></div>
            <button id="start-scanner" type="button">Start camera scanner</button>
            <p class="muted">Camera scanning works on supported browsers over HTTPS. You can always paste a pass link below.</p>
            <form id="manual-scan-form" class="manual-scan-form"><label for="manual-scan">Pass link or access token</label><div><input id="manual-scan" autocomplete="off" placeholder="Paste the QR result"><button type="submit">Check pass</button></div><small id="scan-error" class="form-error" aria-live="polite"></small></form>
        </section>
        <aside class="recent-check-ins"><span class="eyebrow">Latest admissions</span><h2>Recent check-ins</h2>@forelse($recent as $checkIn)<article><div><strong>{{ $checkIn->invitee->name }}</strong><small>{{ $checkIn->scanned_at->format('g:i:s A') }}</small></div><span>+{{ $checkIn->admitted_count }}</span></article>@empty<p class="muted">No one has been admitted yet.</p>@endforelse</aside>
    </div>
</main>
<script>
(function(){
 const video=document.getElementById('scanner-video'),start=document.getElementById('start-scanner'),message=document.getElementById('camera-message'),manual=document.getElementById('manual-scan'),form=document.getElementById('manual-scan-form'),error=document.getElementById('scan-error'); let stream=null,running=false;
 function openResult(value){value=(value||'').trim();let token=value;try{const url=new URL(value,location.origin);if(url.origin!==location.origin||!url.pathname.startsWith('/check-in/'))throw new Error();token=url.pathname.split('/').filter(Boolean)[1]||'';}catch(e){if(value.includes('/')||value.length<20){error.textContent='That is not a valid Okesheni access pass.';return;}}if(!/^[A-Za-z0-9]{40,100}$/.test(token)){error.textContent='That is not a valid Okesheni access pass.';return;}location.assign('/check-in/'+encodeURIComponent(token));}
 form.addEventListener('submit',function(e){e.preventDefault();error.textContent='';openResult(manual.value);});
 start.addEventListener('click',async function(){if(!('BarcodeDetector' in window)||!navigator.mediaDevices?.getUserMedia){message.textContent='Camera QR scanning is not supported here. Paste the pass link below.';return;}try{stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}}});video.srcObject=stream;await video.play();running=true;start.hidden=true;message.textContent='Point the camera at the guest QR code';const detector=new BarcodeDetector({formats:['qr_code']});async function scan(){if(!running)return;try{const codes=await detector.detect(video);if(codes.length){running=false;stream.getTracks().forEach(track=>track.stop());openResult(codes[0].rawValue);return;}}catch(e){}requestAnimationFrame(scan);}scan();}catch(e){message.textContent='Camera access was unavailable. Paste the pass link below.';}});
 window.addEventListener('pagehide',function(){if(stream)stream.getTracks().forEach(track=>track.stop());});
}());
</script>
@endsection
