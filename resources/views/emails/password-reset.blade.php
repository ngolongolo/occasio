<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reset your Okesheni password</title></head>
<body style="margin:0;padding:0;background:#f5f0e9;color:#30252a;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f5f0e9;"><tr><td align="center" style="padding:36px 16px;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;background:#ffffff;border:1px solid #e2d6cd;border-radius:18px;overflow:hidden;box-shadow:0 14px 40px rgba(86,40,63,.10);">
        <tr><td style="background:#56283f;padding:28px 34px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>
                <td><span style="display:inline-block;background:#ffffff;border-radius:8px;padding:7px 10px;"><img src="{{ url('/assets/logo/logo_main.png') }}" width="138" alt="Okesheni" style="display:block;max-width:138px;height:auto;border:0;"></span></td>
                <td align="right" style="color:#e8c486;font-size:11px;font-weight:bold;letter-spacing:1.4px;text-transform:uppercase;">Account security</td>
            </tr></table>
        </td></tr>
        <tr><td style="padding:38px 40px 18px;">
            <div style="display:inline-block;background:#f5ebdf;color:#8d672d;border-radius:999px;padding:7px 11px;font-size:10px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;">Password reset</div>
            <h1 style="margin:18px 0 12px;color:#56283f;font-family:Georgia,'Times New Roman',serif;font-size:34px;line-height:1.15;font-weight:500;">Create a new password</h1>
            <p style="margin:0;color:#675b60;font-size:15px;line-height:1.7;">Hello {{ $user->name ?: 'there' }}, we received a request to reset the password for your Okesheni organiser account.</p>
        </td></tr>
        <tr><td align="center" style="padding:14px 40px 28px;">
            <a href="{{ $resetUrl }}" style="display:inline-block;background:#56283f;border:1px solid #56283f;border-radius:9px;color:#ffffff;font-size:14px;font-weight:bold;padding:14px 25px;text-decoration:none;">Reset my password →</a>
        </td></tr>
        <tr><td style="padding:0 40px 34px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#faf6f0;border:1px solid #eadfd5;border-radius:10px;"><tr><td style="padding:17px 19px;color:#675b60;font-size:12px;line-height:1.6;">
                <strong style="color:#56283f;">For your security</strong><br>This link expires in {{ $expires }} minutes and can only be used once. If you did not request a password reset, you can safely ignore this email.
            </td></tr></table>
        </td></tr>
        <tr><td style="border-top:1px solid #eee4dc;padding:23px 40px;color:#877b80;font-size:11px;line-height:1.6;">
            If the button does not work, copy and paste this link into your browser:<br>
            <a href="{{ $resetUrl }}" style="color:#8d672d;word-break:break-all;">{{ $resetUrl }}</a>
        </td></tr>
        <tr><td align="center" style="background:#fbf8f4;padding:18px;color:#9a8c91;font-size:10px;">Okesheni · Thoughtful invitations and event coordination</td></tr>
    </table>
</td></tr></table>
</body>
</html>
