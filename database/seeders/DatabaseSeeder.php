<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\{User,Event};
class DatabaseSeeder extends Seeder {public function run(): void {if(app()->environment('production'))return;$email=env('DEMO_EMAIL');$password=env('DEMO_PASSWORD');if(!$email||!$password)return;$u=User::firstOrCreate(['email'=>$email],['name'=>'HTAF Organiser','password'=>$password]);Event::firstOrCreate(['user_id'=>$u->id,'title'=>'HTAF Gala Dinner 2026'],['host'=>'Heart Team Africa Foundation','description'=>'Join us to celebrate our annual achievements and community impact with a formal dinner, keynote speakers, a silent auction and live musical entertainment.','starts_at'=>'2026-11-07 17:30:00','rsvp_deadline'=>'2026-10-15','venue'=>'Malaika Beach Resort, Mwanza, Tanzania','dress_code'=>'White suit with gold','contact'=>'+255 784 311 111']);}}
