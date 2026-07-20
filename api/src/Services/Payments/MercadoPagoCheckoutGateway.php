<?php

declare(strict_types=1);

namespace Mypos\Services\Payments;

use Mypos\Contracts\PaymentGatewayInterface;
use Mypos\Core\HttpException;
use Mypos\Support\GatewayHttpClient;

final class MercadoPagoCheckoutGateway implements PaymentGatewayInterface
{
    private const BASE='https://api.mercadopago.com';
    public function __construct(private readonly string $accessToken,private readonly ?GatewayHttpClient $http=null){}
    public function validateCredentials():array{try{$r=$this->request('GET','/users/me');if($r['status']===200)return['ok'=>true,'account_id'=>isset($r['body']['id'])?(string)$r['body']['id']:null,'message'=>'Cuenta Mercado Pago conectada'];return['ok'=>false,'account_id'=>null,'message'=>'Mercado Pago rechazo el token'];}catch(HttpException$e){return['ok'=>false,'account_id'=>null,'message'=>$e->getMessage()];}}
    public function createPayment(array $p):array{$payload=['items'=>[['id'=>(string)$p['reference'],'title'=>(string)$p['description'],'currency_id'=>'CLP','quantity'=>1,'unit_price'=>(int)$p['amount']]],'external_reference'=>(string)$p['reference'],'notification_url'=>(string)$p['notification_url'],'back_urls'=>['success'=>(string)$p['return_url'],'pending'=>(string)$p['return_url'],'failure'=>(string)$p['return_url']],'auto_return'=>'approved','expires'=>true,'expiration_date_to'=>(new \DateTimeImmutable('+'.(int)($p['timeout_seconds']??900).' seconds'))->format(DATE_ATOM),'payer'=>['email'=>(string)$p['payer_email']]];if(isset($p['marketplace_fee'])&&(int)$p['marketplace_fee']>0)$payload['marketplace_fee']=(int)$p['marketplace_fee'];$r=$this->request('POST','/checkout/preferences',$payload,(string)$p['idempotency_key']);$this->ok($r,'No se pudo crear Checkout Pro');$id=(string)($r['body']['id']??'');$url=(string)($r['body']['init_point']??'');if($id===''||$url==='')throw new HttpException('Mercado Pago no entrego URL de checkout',502);return['provider_id'=>$id,'checkout_url'=>$url,'status'=>'PENDIENTE','raw'=>$r['body']];}
    public function getPayment(string $providerId):array{$r=$this->request('GET','/v1/payments/'.rawurlencode($providerId));$this->ok($r,'No se pudo consultar el pago Mercado Pago');$b=$r['body'];$status=match((string)($b['status']??'')){'approved'=>'APROBADO','rejected'=>'RECHAZADO','cancelled'=>'CANCELADO','refunded'=>'REEMBOLSADO',default=>'PENDIENTE'};return['provider_id'=>(string)($b['id']??$providerId),'reference'=>(string)($b['external_reference']??''),'status'=>$status,'amount'=>(int)round((float)($b['transaction_amount']??0)),'fee'=>isset($b['fee_details'])?(int)round(array_sum(array_map(fn($x)=>(float)($x['amount']??0),(array)$b['fee_details']))):null,'net_amount'=>isset($b['transaction_details']['net_received_amount'])?(int)round((float)$b['transaction_details']['net_received_amount']):null,'expected_settlement_at'=>$b['money_release_date']??null,'settlement_status'=>!empty($b['money_release_date'])?'PROGRAMADA':'NO_VERIFICABLE','raw'=>$b];}
    public function refund(string $providerId,?int $amount=null):array{$body=$amount!==null?['amount'=>$amount]:[];$r=$this->request('POST','/v1/payments/'.rawurlencode($providerId).'/refunds',$body,bin2hex(random_bytes(16)));$this->ok($r,'No se pudo reembolsar en Mercado Pago');return['status'=>'REEMBOLSADO','provider_refund_id'=>$r['body']['id']??null,'raw'=>$r['body']];}
    private function request(string $method,string $path,?array $body=null,?string $idempotency=null):array{$headers=['Authorization: Bearer '.$this->accessToken,'Accept: application/json','Content-Type: application/json'];if($idempotency)$headers[]='X-Idempotency-Key: '.$idempotency;return($this->http??new GatewayHttpClient())->request($method,self::BASE.$path,$headers,$body===null?null:json_encode($body,JSON_UNESCAPED_UNICODE));}
    private function ok(array $r,string $message):void{if($r['status']<200||$r['status']>=300)throw new HttpException($message,502);}
}
