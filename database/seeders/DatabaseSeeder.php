<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) return;
        $email = env('DEMO_EMAIL');
        $password = env('DEMO_PASSWORD');
        if (! $email || ! $password) return;
        $user = User::firstOrCreate(['email' => $email], ['name' => 'Demo Organiser', 'password' => $password]);
        Event::firstOrCreate(['user_id' => $user->id, 'title' => 'Annual Leadership Forum'], [
            'host' => 'Okesheni Demo Company',
            'description' => 'A private leadership forum bringing executives and stakeholders together for strategic conversations and networking.',
            'starts_at' => '2026-11-07 17:30:00',
            'rsvp_deadline' => '2026-10-15',
            'venue' => 'Conference Centre, Dar es Salaam',
            'dress_code' => 'Business formal',
            'contact' => '+255 700 000 000',
        ]);
    }
}
