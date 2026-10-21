<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $e->title }}</title></head>
<body style="margin:0;padding:0;background:#f3eee8;color:#30252a;font-family:Arial,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f3eee8;"><tr><td align="center" style="padding:32px 14px;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:620px;background:{{ $e->background_color ?: '#fcf9f4' }};border:1px solid #e4d9d1;border-radius:18px;overflow:hidden;box-shadow:0 12px 30px rgba(48,37,42,.08);">
<tr><td style="height:7px;background:{{ $e->primary_color ?: '#56283f' }};font-size:0;line-height:0;">&nbsp;</td></tr>
<tr><td align="center" style="padding:30px 34px 18px;">
    @if($logoPath)<img src="{{ $message->embed($logoPath) }}" alt="{{ $e->host }}" style="display:block;max-height:72px;max-width:190px;width:auto;height:auto;margin:0 auto 20px;border:0;">@endif
    <p style="margin:0 0 8px;color:{{ $e->primary_color ?: '#56283f' }};font-size:11px;font-weight:bold;letter-spacing:2px;text-transform:uppercase;">{{ $e->host }} invites you</p>
    <h1 style="margin:0;color:{{ $e->text_color ?: '#30252a' }};font-family:Georgia,serif;font-size:36px;line-height:1.16;font-weight:normal;">{{ $e->title }}</h1>
    <p style="margin:13px 0 0;color:#75686c;font-size:15px;line-height:1.6;">Dear {{ $g->name }}, your personal invitation is below.</p>
</td></tr>
@if($cardPreviewPath)
<tr><td style="padding:8px 24px 22px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#fff;border:1px solid #ddcbb9;border-radius:12px;"><tr><td align="center" style="padding:10px;"><a href="{{ $g->cardUrl() }}" style="display:block;text-decoration:none;"><img src="{{ $message->embed($cardPreviewPath) }}" alt="{{ $e->title }} invitation" width="550" style="display:block;width:100%;max-width:550px;height:auto;margin:0 auto;border:0;border-radius:7px;"></a></td></tr></table></td></tr>
@elseif($e->invitation_card_path)
<tr><td align="center" style="padding:8px 34px 24px;"><a href="{{ $g->cardUrl() }}" style="display:block;padding:22px;border:1px solid #ddcbb9;border-radius:10px;color:{{ $e->primary_color ?: '#56283f' }};font-weight:bold;text-decoration:none;">Open your designed invitation card →</a></td></tr>
@else
<tr><td style="padding:10px 38px 22px;"><p style="margin:0;color:#62565a;font-family:Georgia,serif;font-size:17px;line-height:1.7;text-align:center;white-space:pre-line;">{{ $e->description }}</p></td></tr>
@endif
<tr><td style="padding:0 34px 25px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#fff;border:1px solid #e8dfd8;border-radius:10px;"><tr>
    <td width="50%" style="padding:17px 18px;border-right:1px solid #e8dfd8;"><span style="display:block;color:#9a878d;font-size:9px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;">Date &amp; time</span><strong style="display:block;margin-top:5px;color:{{ $e->text_color ?: '#30252a' }};font-size:13px;line-height:1.45;">{{ $e->starts_at->format('d M Y') }}<br>{{ $e->starts_at->format('g:i A') }} EAT</strong></td>
    <td width="50%" style="padding:17px 18px;"><span style="display:block;color:#9a878d;font-size:9px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;">Venue</span><strong style="display:block;margin-top:5px;color:{{ $e->text_color ?: '#30252a' }};font-size:13px;line-height:1.45;">{{ $e->venue }}</strong></td>
</tr></table>@if($e->dress_code)<p style="margin:13px 0 0;color:#75686c;font-size:12px;text-align:center;">Dress code: {{ $e->dress_code }}</p>@endif</td></tr>
<tr><td align="center" style="padding:2px 34px 34px;"><p style="margin:0 0 17px;color:#75686c;font-size:13px;">Please respond by <strong>{{ $e->rsvp_deadline->format('d M Y') }}</strong>.</p><a href="{{ $g->rsvpUrl() }}" style="display:inline-block;background:{{ $e->primary_color ?: '#56283f' }};border-radius:8px;color:#fff;font-size:14px;font-weight:bold;padding:15px 25px;text-decoration:none;">{{ $e->participation_enabled && $e->pledge_enabled ? 'Respond or make a pledge' : ($e->pledge_enabled ? 'Make a pledge' : 'Confirm your attendance') }}</a></td></tr>
<tr><td style="border-top:1px solid #e8dfd8;padding:20px 34px 25px;text-align:center;">
    @if($e->contact)<p style="margin:0 0 9px;color:#75686c;font-size:12px;">Questions? {{ $e->contact }}</p>@endif
    <p style="margin:0;color:#9a8d91;font-size:10px;line-height:1.55;">This invitation and response link are personal to you.<br>If the button does not work, use <a href="{{ $g->rsvpUrl() }}" style="color:{{ $e->primary_color ?: '#56283f' }};word-break:break-all;">your private response link</a>.</p>
</td></tr>
</table></td></tr></table>
</body></html>
