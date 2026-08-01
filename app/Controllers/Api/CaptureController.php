<?php
declare(strict_types=1);
namespace Catch\Controllers\Api;
use Catch\Http\Request;
use Catch\Http\Response;
use Catch\Repositories\CaptureRepository;
use Catch\Repositories\DeviceRepository;
use Catch\Services\CaptureService;

final class CaptureController
{
    public function __construct(private readonly DeviceRepository $devices,private readonly CaptureRepository $captures,private readonly CaptureService $service){}
    private function user(string $scope='full'): array{$token=Request::bearerToken();$user=$token?$this->devices->userForToken($token,$scope):null;if(!$user)Response::json(['error'=>['code'=>'unauthorized','message'=>'A token with the required permission is needed.']],401);return $user;}
    public function index(): never{$u=$this->user();Response::json(['data'=>$this->captures->list($u['id'],$_GET['status']??'inbox')]);}
    public function create(): never
    {
        $u=$this->user('capture:write');$type=$_SERVER['CONTENT_TYPE']??'';$input=str_contains($type,'application/json')?Request::json():$_POST;
        $input['client_capture_id']=$input['client_capture_id']??($_SERVER['HTTP_IDEMPOTENCY_KEY']??null);
        if(isset($input['metadata'])&&is_string($input['metadata']))$input['metadata']=json_decode($input['metadata'],true)?:[];
        try{$result=$this->service->create($u['id'],$input,$_FILES);$capture=$result['capture'];Response::json(['id'=>$capture['id'],'status'=>$result['created']?'created':'existing','created_at'=>$capture['created_at'],'matched_rules'=>0],$result['created']?201:200);}
        catch(\InvalidArgumentException $e){Response::json(['error'=>['code'=>'validation_failed','message'=>'The request is invalid.','fields'=>json_decode($e->getMessage(),true)]],422);}
        catch(\Throwable $e){Response::json(['error'=>['code'=>'capture_failed','message'=>'The capture could not be stored.']],500);}
    }
    public function show(\Base $f3,array $params): never{$u=$this->user();$c=$this->captures->find((string)$params['id'],$u['id']);if(!$c)Response::json(['error'=>['code'=>'not_found','message'=>'Capture not found.']],404);Response::json(['data'=>$c]);}
    public function archive(\Base $f3,array $params): never{$u=$this->user();if(!$this->captures->setStatus((string)$params['id'],$u['id'],'archived'))Response::json(['error'=>['code'=>'not_found','message'=>'Capture not found.']],404);Response::json(['status'=>'archived']);}
    public function delete(\Base $f3,array $params): never{$u=$this->user();if(!$this->captures->setStatus((string)$params['id'],$u['id'],'deleted'))Response::json(['error'=>['code'=>'not_found','message'=>'Capture not found.']],404);Response::json(['status'=>'deleted']);}
}
