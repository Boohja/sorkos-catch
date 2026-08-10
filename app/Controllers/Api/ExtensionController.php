<?php

declare(strict_types=1);

namespace Catch\Controllers\Api;

use Catch\Core\Config;
use Catch\Http\Request;
use Catch\Http\Response;
use Catch\Repositories\DeviceRepository;

final class ExtensionController
{
    public function __construct(private readonly DeviceRepository $devices,private readonly Config $config){}

    public function startPairing(): never
    {
        $input=Request::json();$name=trim((string)($input['device_name']??''));$platform=trim((string)($input['platform']??''));$challenge=(string)($input['code_challenge']??'');
        $errors=[];
        if($name===''||mb_strlen($name)>120)$errors['device_name']='Enter a device name of at most 120 characters.';
        if(!in_array($platform,['chrome','firefox','chromium'],true))$errors['platform']='Platform must be chrome, firefox, or chromium.';
        if(!preg_match('/^[A-Za-z0-9_-]{43}$/',$challenge))$errors['code_challenge']='A valid SHA-256 code challenge is required.';
        if($errors)Response::json(['error'=>['code'=>'validation_failed','message'=>'The request is invalid.','fields'=>$errors]],422);
        try{$pairing=$this->devices->createExtensionPairingRequest($name,$platform,$challenge);}catch(\PDOException $error){$this->databaseFailure('start',$error);}catch(\Throwable $error){$this->serverFailure('start',$error);}
        $pairing['pair_url']=rtrim((string)$this->config->get('app.url'),'/').'/pair?'.http_build_query(['request'=>$pairing['request_id']],arg_separator:'&',encoding_type:PHP_QUERY_RFC3986);
        Response::json($pairing,201);
    }

    public function exchangePairing(\Base $f3,array $params): never
    {
        $input=Request::json();
        try{$result=$this->devices->exchangeExtensionPairingRequest((string)$params['request'],(string)($input['code_verifier']??''));}catch(\PDOException $error){$this->databaseFailure('exchange',$error);}catch(\Throwable $error){$this->serverFailure('exchange',$error);}
        if($result['status']==='pending')Response::json(['status'=>'pending'],202);
        if($result['status']==='expired')Response::json(['error'=>['code'=>'pairing_expired','message'=>'The pairing request expired.']],410);
        if($result['status']==='invalid_verifier')Response::json(['error'=>['code'=>'invalid_verifier','message'=>'The pairing proof is invalid.']],401);
        if($result['status']!=='connected')Response::json(['error'=>['code'=>'pairing_not_found','message'=>'The pairing request was not found.']],404);
        Response::json(['status'=>'connected','device_token'=>$result['device_token'],'token_type'=>'Bearer','device'=>['id'=>$result['device_id'],'name'=>$result['device_name']],'capture_endpoint'=>rtrim((string)$this->config->get('app.url'),'/').'/api/v1/captures']);
    }

    public function disconnect(): never
    {
        $token=Request::bearerToken();
        if(!$token||!$this->devices->revokeForToken($token))Response::json(['error'=>['code'=>'unauthorized','message'=>'A valid device token is required.']],401);
        Response::json(['status'=>'disconnected']);
    }

    private function databaseFailure(string $stage,\PDOException $error): never
    {
        $this->logFailure($stage,$error);
        Response::json(['error'=>['code'=>'pairing_unavailable','message'=>'Browser pairing is temporarily unavailable.']],503);
    }

    private function serverFailure(string $stage,\Throwable $error): never
    {
        $this->logFailure($stage,$error);
        Response::json(['error'=>['code'=>'pairing_failed','message'=>'The pairing request could not be completed.']],500);
    }

    private function logFailure(string $stage,\Throwable $error): void
    {
        @error_log(sprintf("[%s] Extension pairing %s failed: %s\n",gmdate(DATE_ATOM),$stage,$error->getMessage()),3,dirname(__DIR__,3).'/storage/logs/extension.log');
    }
}
