<?php
namespace App\Services;
use App\Models\Event;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Validation\ValidationException;
class GuestImporter {
 public function import($file,Event $event): array {
 $reader=IOFactory::createReaderForFile($file->getRealPath());$reader->setReadDataOnly(true);$sheet=$reader->load($file->getRealPath())->getActiveSheet();
 if($sheet->getHighestDataRow()>5001)throw ValidationException::withMessages(['file'=>'Maximum 5,000 guests per upload.']);
 $rows=$sheet->toArray(null,false,false,false);$headers=array_map(fn($h)=>strtolower(trim((string)$h)),array_shift($rows)??[]);
 foreach(['name','email','phone','max_guests'] as $header)if(!in_array($header,$headers,true))throw ValidationException::withMessages(['file'=>'Required headers: name, email, phone, max_guests.']);
 $report=['imported'=>0,'duplicates'=>0,'errors'=>[]];
 foreach($rows as $i=>$row){if(!array_filter($row,fn($x)=>trim((string)$x)!==''))continue;$data=[];foreach($headers as $j=>$h)$data[$h]=trim((string)($row[$j]??''));$data['email']=strtolower($data['email']??'')?:null;$data['phone']=preg_replace('/[\s()\-]/','',$data['phone']??'')?:null;$data['max_guests']=$data['max_guests']?:1;
 $v=Validator::make($data,['name'=>'required|string|max:150','email'=>'nullable|email|max:180|required_without:phone','phone'=>['nullable','required_without:email','regex:/^\+[1-9]\d{7,14}$/'],'max_guests'=>'required|integer|min:1|max:10']);
 if($v->fails()){$report['errors'][]='Row '.($i+2).': '.implode(' ',$v->errors()->all());continue;}
 $existing=$event->invitees()->where(function($q)use($data){if($data['email'])$q->orWhere('email',$data['email']);if($data['phone'])$q->orWhere('phone',$data['phone']);})->exists();
 if($existing){$report['duplicates']++;continue;}
 $event->invitees()->create(['name'=>$data['name'],'email'=>$data['email'],'phone'=>$data['phone'],'max_guests'=>$data['max_guests'],'identity_key'=>hash('sha256',$data['email']??$data['phone']),'token'=>Str::random(64)]);$report['imported']++;
 }return $report;
 }
}
