<?php
namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
class AuthController {
 public function register(Request $r){$v=$r->validate(['name'=>'required|string|max:120','email'=>'required|email|max:180|unique:users','password'=>'required|confirmed|min:10']);Auth::login(User::create($v));$r->session()->regenerate();return redirect('/dashboard');}
 public function login(Request $r){$v=$r->validate(['email'=>'required|email','password'=>'required']);if(!Auth::attempt($v))return back()->withErrors(['email'=>'Email or password is incorrect.'])->onlyInput('email');$r->session()->regenerate();return redirect()->intended('/dashboard');}
 public function logout(Request $r){Auth::logout();$r->session()->invalidate();$r->session()->regenerateToken();return redirect('/');}
}
