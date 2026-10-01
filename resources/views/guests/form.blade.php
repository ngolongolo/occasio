@extends('layout')
@section('title', ($guest->exists ? 'Edit guest' : 'Add guest').' · Okesheni')
@section('content')
<main class="page">
    <div class="page-head">
        <div><span class="eyebrow">{{ $event->title }}</span><h1>{{ $guest->exists ? 'Edit guest' : 'Add a guest' }}</h1><p>{{ $guest->exists ? 'Update this guest’s invitation details.' : 'Add someone to your guest list and send their invitation when you are ready.' }}</p></div>
        <a class="btn secondary" href="{{ route('events.show', $event) }}">← Guest list</a>
    </div>
    @include('partials.alerts')
    <form class="panel guest-form" method="post" action="{{ $guest->exists ? route('events.guests.update', [$event, $guest]) : route('events.guests.store', $event) }}">
        @csrf
        @if($guest->exists) @method('put') @endif
        <label for="name">Guest name</label><input id="name" name="name" value="{{ old('name', $guest->name) }}" maxlength="150" required autofocus>
        <div class="form-grid"><div><label for="email">Email address</label><input id="email" type="email" name="email" value="{{ old('email', $guest->email) }}" maxlength="180" placeholder="guest@example.com"></div><div><label for="phone">Phone number</label><input id="phone" type="tel" name="phone" value="{{ old('phone', $guest->phone) }}" placeholder="+255784311111"></div></div>
        <p class="muted">Provide an email address, a phone number, or both. Phone numbers must include the country code.</p>
        <label for="max_guests">Maximum seats</label><input id="max_guests" type="number" name="max_guests" value="{{ old('max_guests', $guest->max_guests ?: 1) }}" min="1" max="10" required>
        <div class="form-actions"><a class="btn secondary" href="{{ route('events.show', $event) }}">Cancel</a><button type="submit">{{ $guest->exists ? 'Save changes' : 'Add guest' }}</button></div>
    </form>
</main>
@endsection
