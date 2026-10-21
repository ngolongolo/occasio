<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

Artisan::command('admin:promote {email}', function (string $email) {
    $user = User::where('email', strtolower(trim($email)))->first();
    if (! $user) {
        $this->error('No user was found with that email address.');
        return self::FAILURE;
    }
    $user->forceFill(['role' => 'super_admin'])->save();
    $this->info($user->email.' now has Super Admin access.');
    return self::SUCCESS;
})->purpose('Promote an existing organiser to Super Admin');

Artisan::command('admin:create {email} {--name=Super Admin}', function (string $email) {
    $email = strtolower(trim($email));
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Enter a valid email address.');
        return self::FAILURE;
    }
    $password = $this->secret('Password');
    if (strlen((string) $password) < 8) {
        $this->error('The password must contain at least 8 characters.');
        return self::FAILURE;
    }
    $user = User::firstOrNew(['email' => $email]);
    $user->name = trim((string) $this->option('name')) ?: 'Super Admin';
    $user->password = Hash::make($password);
    $user->role = 'super_admin';
    $user->save();
    $this->info($email.' is ready with Super Admin access.');
    return self::SUCCESS;
})->purpose('Create or update a Super Admin account using a concealed password prompt');
