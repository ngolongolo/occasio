@extends('layout')
@section('title', 'Choose a new password · Okesheni')
@section('content')
<div class="form panel">
    <img class="auth-brand" src="/assets/logo/logo_vertical.png" alt="Okesheni">
    <span class="eyebrow">Secure your account</span>
    <h1>Choose a new password.</h1>
    <p>Use at least 8 characters and avoid reusing an old password.</p>
    @include('partials.alerts')
    <form method="post" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label for="reset-email">Email address</label>
        <input id="reset-email" type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="email">
        @include('partials.password-input', ['id' => 'new-password', 'name' => 'password', 'label' => 'New password', 'autocomplete' => 'new-password', 'minlength' => 8])
        @include('partials.password-input', ['id' => 'confirm-password', 'name' => 'password_confirmation', 'label' => 'Confirm new password', 'autocomplete' => 'new-password', 'minlength' => 8])
        <button type="submit" style="width:100%;margin-top:25px">Reset password</button>
    </form>
</div>
@endsection
