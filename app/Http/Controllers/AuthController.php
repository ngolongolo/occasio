<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TanzanianPhoneNumber;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthController
{
    public function register(Request $request)
    {
        // Keep cached/legacy forms working while the interface moves to one
        // email-or-mobile field.
        if (! $request->filled('identifier')) {
            if ($request->filled('email')) {
                $request->merge(['identifier' => $request->input('email')]);
            } elseif ($request->filled('phone')) {
                $request->merge(['identifier' => $request->input('phone')]);
            }
        }
        if (is_string($request->input('identifier'))) {
            $request->merge(['identifier' => trim($request->input('identifier'))]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'identifier' => [
                'bail',
                'required',
                'string',
                'max:180',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $identifier = trim((string) $value);

                    if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
                        if (User::query()->whereRaw('LOWER(email) = ?', [strtolower($identifier)])->exists()) {
                            $fail('An account already uses this email address.');
                        }

                        return;
                    }

                    $phone = TanzanianPhoneNumber::normalize($identifier);
                    if ($phone === null) {
                        $fail('Enter a valid email address or Tanzanian mobile number.');
                    } elseif (User::query()->where('phone', $phone)->exists()) {
                        $fail('An account already uses this mobile number.');
                    }
                },
            ],
            'password' => ['required', 'string', 'confirmed', 'min:8'],
            'event_type' => ['nullable', 'in:event,hangout'],
        ], [
            'identifier.required' => 'Enter an email address or mobile number.',
        ]);

        $eventType = $validated['event_type'] ?? null;
        $identifier = trim($validated['identifier']);
        $contact = filter_var($identifier, FILTER_VALIDATE_EMAIL)
            ? ['email' => strtolower($identifier), 'phone' => null]
            : ['email' => null, 'phone' => TanzanianPhoneNumber::normalize($identifier)];

        Auth::login(User::create([
            'name' => $validated['name'],
            ...$contact,
            'password' => $validated['password'],
        ]));
        $request->session()->regenerate();

        return redirect($eventType ? '/events/create?event_type='.$eventType : '/dashboard');
    }

    public function login(Request $request)
    {
        // Continue accepting the former `email` field while clients move to
        // the single email-or-mobile `identifier` field.
        if (! $request->filled('identifier') && $request->filled('email')) {
            $request->merge(['identifier' => $request->input('email')]);
        }

        $validated = $request->validate([
            'identifier' => [
                'bail',
                'required',
                'string',
                'max:180',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $identifier = trim((string) $value);

                    if (! filter_var($identifier, FILTER_VALIDATE_EMAIL)
                        && TanzanianPhoneNumber::normalize($identifier) === null) {
                        $fail('Enter a valid email address or Tanzanian mobile number.');
                    }
                },
            ],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
            'event_type' => ['nullable', 'in:event,hangout'],
        ]);

        $identifier = trim($validated['identifier']);

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $email = strtolower($identifier);
            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        } else {
            $phone = TanzanianPhoneNumber::normalize($identifier);
            $user = User::query()->where('phone', $phone)->first();
        }

        // Always enter SessionGuard's timebox, including for an unknown
        // identifier, so account existence cannot be inferred from timing.
        $authenticated = Auth::attempt([
            'id' => $user?->getAuthIdentifier() ?? 0,
            'password' => $validated['password'],
        ], $request->boolean('remember'));

        if (! $authenticated) {
            return back()
                ->withErrors(['identifier' => 'Email, mobile number, or password is incorrect.'])
                ->onlyInput('identifier', 'email', 'event_type');
        }

        $request->session()->regenerate();
        $home = Auth::user()->isSuperAdmin()
            ? route('admin.index')
            : (isset($validated['event_type']) ? '/events/create?event_type='.$validated['event_type'] : '/dashboard');

        return redirect()->intended($home);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    public function forgotPassword()
    {
        return view('auth.forgot-password');
    }

    public function emailResetLink(Request $request)
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }
        $validated = $request->validate(['email' => ['bail', 'required', 'string', 'email', 'max:180']]);
        $validated['email'] = $this->storedEmailOrInput($validated['email']);
        $status = Password::sendResetLink($validated);

        if ($status === Password::RESET_THROTTLED) {
            return back()->withErrors(['email' => 'Please wait before requesting another reset link.'])->onlyInput('email');
        }

        return back()->with('success', 'If an account matches that email, a password reset link has been sent.');
    }

    public function resetPassword(Request $request, string $token)
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function updatePassword(Request $request)
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }
        $validated = $request->validate([
            'token' => 'required',
            'email' => ['bail', 'required', 'string', 'email'],
            'password' => ['required', 'string', 'confirmed', 'min:8'],
        ]);
        $validated['email'] = $this->storedEmailOrInput($validated['email']);

        $status = Password::reset($validated, function (User $user, string $password): void {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => 'This password reset link is invalid or has expired.'])->withInput($request->only('email'));
        }

        return redirect()->route('login')->with('success', 'Your password has been reset. You can now sign in.');
    }

    private function storedEmailOrInput(string $email): string
    {
        return User::query()
            ->whereRaw('LOWER(email) = ?', [strtolower($email)])
            ->value('email') ?? strtolower($email);
    }
}
