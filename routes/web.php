<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventRegistrationController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\RsvpController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'landing')->name('home');
Route::get('/guest-template', fn () => response()->download(
    base_path('samples/guest-import.xlsx'),
    'okesheni-guest-import-template.xlsx',
    ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
))->name('guest-template');
Route::get('/join/{token}', [EventRegistrationController::class, 'show'])->middleware('throttle:60,1')->name('events.registration.show');
Route::post('/join/{token}', [EventRegistrationController::class, 'store'])->middleware('throttle:10,1')->name('events.registration.store');

Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.form', ['register' => false])->name('login');
    Route::view('/register', 'auth.form', ['register' => true]);
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/dashboard', [EventController::class, 'index']);
    Route::view('/events/create', 'events.create');
    Route::post('/events', [EventController::class, 'store']);
    Route::get('/events/{event}', [EventController::class, 'show'])->name('events.show');
    Route::get('/events/{event}/design', [EventController::class, 'design'])->name('events.design');
    Route::put('/events/{event}/design', [EventController::class, 'updateDesign'])->name('events.design.update');
    Route::get('/events/{event}/design/card', [EventController::class, 'downloadCard'])->name('events.design.card');
    Route::get('/events/{event}/guests/create', [GuestController::class, 'create'])->name('events.guests.create');
    Route::post('/events/{event}/guests', [GuestController::class, 'store'])->name('events.guests.store');
    Route::get('/events/{event}/guests/{guest}/edit', [GuestController::class, 'edit'])->name('events.guests.edit');
    Route::put('/events/{event}/guests/{guest}', [GuestController::class, 'update'])->name('events.guests.update');
    Route::delete('/events/{event}/guests/{guest}', [GuestController::class, 'destroy'])->name('events.guests.destroy');
    Route::post('/events/{event}/import', [EventController::class, 'import'])->middleware('throttle:10,1')->name('events.import');
    Route::post('/events/{event}/send', [EventController::class, 'send'])->middleware('throttle:5,1')->name('events.send');
    Route::get('/events/{event}/export', [EventController::class, 'export'])->name('events.export');
});

Route::get('/rsvp/{token}', [RsvpController::class, 'show'])->middleware('throttle:60,1')->name('rsvp.show');
Route::post('/rsvp/{token}', [RsvpController::class, 'update'])->middleware('throttle:15,1');
