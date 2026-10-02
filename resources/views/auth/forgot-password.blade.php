@extends('layout')
@section('title', 'Reset password · Okesheni')
@section('content')
<div class="form panel">
    <img class="auth-brand" src="/assets/logo/logo_vertical.png" alt="Okesheni">
    <span class="eyebrow">Account recovery</span>
    <h1>Reset your password.</h1>
    <p>Enter your organiser email and we’ll send you a secure reset link.</p>
    @include('partials.alerts')
    <form method="post" action="{{ route('password.email') }}">
        @csrf
        <label for="reset-email">Email address</label>
        <input id="reset-email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
        <button type="submit" style="width:100%;margin-top:25px">Send reset link</button>
    </form>
    <p class="muted"><a href="{{ route('login') }}">Back to sign in</a></p>
</div>
@endsection
