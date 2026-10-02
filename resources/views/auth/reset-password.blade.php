@extends('layout')
@section('title', 'Choose a new password · Okesheni')
@section('content')
<div class="form panel">
    <img class="auth-brand" src="/assets/logo/logo_vertical.png" alt="Okesheni">
    <span class="eyebrow">Secure your account</span>
    <h1>Choose a new password.</h1>
    <p>Use at least 10 characters and avoid reusing an old password.</p>
    @include('partials.alerts')
    <form method="post" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label for="reset-email">Email address</label>
        <input id="reset-email" type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="email">
        <label for="new-password">New password</label>
        <input id="new-password" type="password" name="password" required minlength="10" autocomplete="new-password">
        <label for="confirm-password">Confirm new password</label>
        <input id="confirm-password" type="password" name="password_confirmation" required minlength="10" autocomplete="new-password">
        <button type="submit" style="width:100%;margin-top:25px">Reset password</button>
    </form>
</div>
@endsection
