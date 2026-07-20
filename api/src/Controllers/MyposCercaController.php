<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\Auth;
use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Services\MyposCercaService;
use PDOException;
use Throwable;

final class MyposCercaController
{
    private MyposCercaService $service;
    public function __construct(?MyposCercaService $service=null){$this->service=$service??new MyposCercaService();}

    public function getProfile(array $p):void{$this->run(fn()=>$this->service->profile((int)($_GET['empresa_id']??0),(int)($p['sucursal_id']??0)));}
    public function putProfile(array $p):void{$this->run(fn()=>$this->service->saveProfile((int)Auth::id(),(int)(Request::json()['empresa_id']??0),(int)($p['sucursal_id']??0),Request::json()));}
    public function products():void{$this->run(fn()=>$this->service->products((int)($_GET['empresa_id']??0),isset($_GET['sucursal_id'])?(int)$_GET['sucursal_id']:null));}
    public function putProducts():void{$this->run(fn()=>$this->service->saveProducts((int)Auth::id(),(int)(Request::json()['empresa_id']??0),Request::json()));}
    public function quotes():void{$this->run(fn()=>$this->service->quotes((int)($_GET['empresa_id']??0)));}
    public function reservations():void{$this->run(fn()=>$this->service->reservations((int)($_GET['empresa_id']??0)));}
    public function reservationState(array $p):void{$body=Request::json();$this->run(fn()=>$this->service->changeReservationState((int)Auth::id(),(int)($body['empresa_id']??0),(int)($p['id']??0),(string)($body['estado']??'')));}
    public function convertReservation(array $p):void{$body=Request::json();$this->run(fn()=>$this->service->convertReservationToSale((int)Auth::id(),(int)($body['empresa_id']??0),(int)($p['id']??0),$body));}
    public function metrics():void{$this->run(fn()=>$this->service->metrics((int)($_GET['empresa_id']??0),(int)($_GET['dias']??30)));}

    public function search():void{$this->run(fn()=>$this->service->search($_GET,$this->sessionHash()));}
    public function publicStore(array $p):void{$this->run(fn()=>$this->service->store((string)($p['slug']??''),$this->sessionHash()));}
    public function publicProduct(array $p):void{$this->run(fn()=>$this->service->product((string)($p['slug']??''),$this->sessionHash()));}
    public function createQuote():void{$this->run(fn()=>$this->service->createQuote(Request::json(),$this->sessionHash()),201);}
    public function createReservation():void{$this->run(fn()=>$this->service->createReservation(Request::json(),$this->sessionHash()),201);}
    public function reservationStatus(array $p):void{$this->run(fn()=>$this->service->reservationStatus((string)($p['token']??'')));}

    private function sessionHash():string
    {
        $session=(string)($_SERVER['HTTP_X_PUBLIC_SESSION']??'');
        if(!preg_match('/^[A-Za-z0-9_-]{16,100}$/',$session))$session=(string)($_SERVER['REMOTE_ADDR']??'unknown').'|'.substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,200);
        return hash('sha256',$session);
    }
    private function run(callable $fn,int $status=200):void{try{Response::success($fn(),null,$status);}catch(HttpException$e){Response::error($e->getMessage(),$e->errors(),$e->statusCode());}catch(PDOException$e){if((string)$e->getCode()==='23000'){Response::error('La solicitud ya fue procesada. Usa el enlace generado originalmente.',null,409);return;}error_log('[MyposCerca] '.$e->getMessage());Response::error('No se pudo completar la operacion',null,500);}catch(Throwable$e){error_log('[MyposCerca] '.$e->getMessage());Response::error('No se pudo completar la operacion',null,500);}}
}
