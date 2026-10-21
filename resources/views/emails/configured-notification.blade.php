<div style="font-family:Georgia,serif;background:{{ $event->background_color ?: '#fcf9f4' }};padding:40px;color:{{ $event->text_color ?: '#56283f' }};max-width:550px;margin:auto;border:1px solid {{ $event->primary_color ?: '#56283f' }}">
    <p style="font-size:12px;letter-spacing:2px;text-transform:uppercase">{{ $event->host }}</p>
    <h1 style="font-weight:500">{{ $event->title }}</h1>
    <div style="white-space:pre-line;line-height:1.7">{{ $body }}</div>
    @if($event->contact)<p style="font-size:12px;margin-top:30px">{{ $event->contact }}</p>@endif
</div>
