<?php
namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
class AuthController {
 public function register(Request $r){$v=$r->validate(['name'=>'required|string|max:120','email'=>'required|email|max:180|unique:users','password'=>'required|confirmed|min:10']);Auth::login(User::create($v));$r->session()->regenerate();return redirect('/dashboard');}
 public function login(Request $r){$v=$r->validate(['email'=>'required|email','password'=>'required']);if(!Auth::attempt($v))return back()->withErrors(['email'=>'Email or password is incorrect.'])->onlyInput('email');$r->session()->regenerate();return redirect()->intended('/dashboard');}
 public function logout(Request $r){Auth::logout();$r->session()->invalidate();$r->session()->regenerateToken();return redirect('/');}
 public function forgotPassword(){return view('auth.forgot-password');}
 public function emailResetLink(Request $r){
  $v=$r->validate(['email'=>'required|email|max:180']);
  $status=Password::sendResetLink($v);
  if($status===Password::RESET_THROTTLED)return back()->withErrors(['email'=>'Please wait before requesting another reset link.'])->onlyInput('email');
  return back()->with('success','If an account matches that email, a password reset link has been sent.');
 }
 public function resetPassword(Request $r,string $token){return view('auth.reset-password',['token'=>$token,'email'=>$r->query('email')]);}
 public function updatePassword(Request $r){
  $v=$r->validate(['token'=>'required','email'=>'required|email','password'=>'required|confirmed|min:10']);
  $status=Password::reset($v,function(User $user,string $password){
   $user->forceFill(['password'=>$password,'remember_token'=>Str::random(60)])->save();
   event(new PasswordReset($user));
  });
  if($status!==Password::PASSWORD_RESET)return back()->withErrors(['email'=>'This password reset link is invalid or has expired.'])->withInput($r->only('email'));
  return redirect()->route('login')->with('success','Your password has been reset. You can now sign in.');
 }
}
