<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\Auth;
use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Services\OnlinePaymentService;
use PDOException;
use Mypos\Support\Env;
use Throwable;

final class OnlinePaymentController
{
    private OnlinePaymentService $service;
    public function __construct(?OnlinePaymentService $service=null){$this->service=$service??new OnlinePaymentService();}
    public function configs():void{$this->run(fn()=>$this->service->configs((int)($_GET['empresa_id']??0)));}
    public function saveConfig(array $p):void{$body=Request::json();$this->run(fn()=>$this->service->saveConfig((int)Auth::id(),(int)($body['empresa_id']??0),(string)($p['proveedor']??''),$body));}
    public function validate(array $p):void{$body=Request::json();$this->run(fn()=>$this->service->validate((int)($body['empresa_id']??0),(string)($p['proveedor']??'')));}
    public function verification(array $p):void{$body=Request::json();$this->run(fn()=>$this->service->createVerificationPayment((int)($body['empresa_id']??0),(string)($p['proveedor']??''),$body),201);}
    public function refundReservation(array $p):void{$body=Request::json();$this->run(fn()=>$this->service->refundReservation((int)Auth::id(),(int)($body['empresa_id']??0),(int)($p['id']??0)));}
    public function mercadoPagoOauthStart():void{$body=Request::json();$this->run(fn()=>$this->service->mercadoPagoOauthStart((int)Auth::id(),(int)($body['empresa_id']??0)));}
    public function mercadoPagoOauthCallback():void{$this->run(fn()=>$this->service->mercadoPagoOauthComplete((string)($_GET['state']??''),(string)($_GET['code']??'')));}
    public function available(array $p):void{$this->run(fn()=>$this->service->availableForReservation((string)($p['token']??'')));}
    public function createReservationPayment(array $p):void{$this->run(fn()=>$this->service->createReservationPayment((string)($p['token']??''),Request::json()),201);}
    public function callback(array $p):void{$payload=array_merge($_GET,$_POST,Request::json());$this->run(fn()=>$this->service->handleCallback((string)($p['proveedor']??''),$payload,$this->headers()));}
    public function paymentReturn(array $p):void{$token=(string)($p['token']??'');if(!preg_match('/^[a-f0-9]{48}$/',$token)){Response::error('Retorno de pago invalido',null,422);return;}$front=rtrim((string)Env::get('FRONTEND_URL','https://www.mypos.cl'),'/');header('Location: '.$front.'/cerca/reserva/'.$token,true,302);}
    /** @return array<string,string> */
    private function headers():array{$out=[];foreach($_SERVER as$k=>$v)if(str_starts_with((string)$k,'HTTP_'))$out[strtolower(str_replace('_','-',substr((string)$k,5)))]=(string)$v;return$out;}
    private function run(callable $fn,int $status=200):void{try{Response::success($fn(),null,$status);}catch(HttpException$e){Response::error($e->getMessage(),$e->errors(),$e->statusCode());}catch(PDOException$e){if((string)$e->getCode()==='23000'){Response::error('Este pago ya fue iniciado. Revisa el intento anterior antes de repetirlo.',null,409);return;}error_log('[OnlinePayment] '.$e->getMessage());Response::error('No se pudo procesar el pago',null,500);}catch(Throwable$e){error_log('[OnlinePayment] '.$e->getMessage());Response::error('No se pudo procesar el pago',null,500);}}
}
