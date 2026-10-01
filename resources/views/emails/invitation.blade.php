<div style="font-family:Georgia,serif;background:#fcf9f4;padding:40px;color:{{ $e->primary_color ?: '#56283f' }};max-width:550px;margin:auto;text-align:center;border:1px solid {{ $e->primary_color ?: '#56283f' }}">
    @if($logoPath)
        <img src="{{ $message->embed($logoPath) }}" alt="{{ $e->host }} logo" style="display:block;max-height:90px;max-width:220px;margin:0 auto 20px">
    @endif
    <p>{{ $e->host }}</p>
    <h1>{{ $e->title }}</h1>
    <p>Dear {{ $g->name }},</p>
    <p style="white-space:pre-line">{{ $e->description }}</p>
    <p><strong>{{ $e->starts_at->format('d M Y · g:i A') }} EAT</strong><br>{{ $e->venue }}</p>
    @if($e->dress_code)<p>{{ $e->dress_code }}</p>@endif
    <p>Please respond by {{ $e->rsvp_deadline->format('d M Y') }}.</p>
    <a href="{{ $g->rsvpUrl() }}" style="display:inline-block;background:{{ $e->primary_color ?: '#56283f' }};color:white;padding:14px 25px;text-decoration:none">Confirm your attendance</a>
    <p style="font-size:12px">If the button does not open, copy this confirmation link:<br><a href="{{ $g->rsvpUrl() }}">{{ $g->rsvpUrl() }}</a></p>
    @if($e->invitation_card_path)<p style="font-size:12px">Your designed invitation card is attached to this email.</p>@endif
    @if($e->contact)<p>{{ $e->contact }}</p>@endif
    <p style="font-size:12px">This invitation link is personal to you.</p>
</div>
