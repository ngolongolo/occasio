<div style="font-family:Georgia,serif;background:{{ $e->background_color ?: '#fcf9f4' }};padding:40px;color:{{ $e->text_color ?: '#56283f' }};max-width:550px;margin:auto;text-align:center;border:1px solid {{ $e->primary_color ?: '#56283f' }}">
    @if($logoPath)
        <img src="{{ $message->embed($logoPath) }}" alt="{{ $e->host }} logo" style="display:block;max-height:90px;max-width:220px;margin:0 auto 20px">
    @endif
    <p>{{ $e->host }}</p>
    <h1>{{ $e->title }}</h1>
    <p>Dear {{ $g->name }},</p>
    @if($e->invitation_card_path)
        @if($cardPreviewUrl)
            <a href="{{ $g->cardUrl() }}" style="display:block;margin:24px 0"><img src="{{ $cardPreviewUrl }}" alt="{{ $e->title }} invitation card" style="display:block;height:auto;max-width:100%;margin:auto;border:0"></a>
        @else
            <p style="margin:24px 0;padding:24px;border:1px solid {{ $e->primary_color ?: '#56283f' }}"><a href="{{ $g->cardUrl() }}" style="color:{{ $e->text_color ?: '#56283f' }};font-weight:bold">Open your invitation card</a></p>
        @endif
    @else
        <p style="white-space:pre-line">{{ $e->description }}</p>
        <p><strong>{{ $e->starts_at->format('d M Y · g:i A') }} EAT</strong><br>{{ $e->venue }}</p>
        @if($e->dress_code)<p>{{ $e->dress_code }}</p>@endif
        <p>Please respond by {{ $e->rsvp_deadline->format('d M Y') }}.</p>
    @endif
    <a href="{{ $g->rsvpUrl() }}" style="display:inline-block;background:{{ $e->primary_color ?: '#56283f' }};color:white;padding:14px 25px;text-decoration:none">{{ $e->participation_enabled && $e->pledge_enabled ? 'Respond or make a pledge' : ($e->pledge_enabled ? 'Make a pledge' : 'Confirm your attendance') }}</a>
    <p style="font-size:12px">If the button does not open, copy this private response link:<br><a href="{{ $g->rsvpUrl() }}" style="color:{{ $e->text_color ?: '#56283f' }}">{{ $g->rsvpUrl() }}</a></p>
    @if($e->invitation_card_path)<p style="font-size:12px">The original invitation card is also attached to this email.</p>@endif
    @if($e->contact)<p>{{ $e->contact }}</p>@endif
    <p style="font-size:12px">This invitation link is personal to you.</p>
</div>
