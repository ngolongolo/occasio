<?php

namespace App\Http\Controllers;

use App\Models\Invitee;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\Request;

class AccessPassController
{
    public function show(string $token)
    {
        $guest = $this->eligibleGuest($token);
        return view('events.access-pass', ['guest' => $guest, 'event' => $guest->event]);
    }

    public function qr(Request $request, string $token)
    {
        $guest = $this->eligibleGuest($token);
        $result = Builder::create()->writer(new SvgWriter())->data(route('check-in.show', $guest->access_token))
            ->errorCorrectionLevel(ErrorCorrectionLevel::High)->size(420)->margin(16)
            ->foregroundColor(new Color(86, 40, 63))->backgroundColor(new Color(255, 255, 255))->build();
        $headers = ['Content-Type' => $result->getMimeType(), 'Cache-Control' => 'private, no-store, max-age=0'];
        if ($request->boolean('download')) $headers['Content-Disposition'] = 'attachment; filename="okesheni-pass-'.$guest->event->public_code.'.svg"';
        return response($result->getString(), 200, $headers);
    }

    private function eligibleGuest(string $token): Invitee
    {
        $guest = Invitee::where('token', $token)->with('event')->firstOrFail();
        abort_unless($guest->accessPassEligible(), 403, 'Your access pass will be available after attendance and required payment are confirmed.');
        if (! $guest->access_pass_issued_at) $guest->update(['access_pass_issued_at' => now()]);
        return $guest;
    }
}
