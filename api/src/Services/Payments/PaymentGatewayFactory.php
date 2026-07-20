<?php

declare(strict_types=1);

namespace Mypos\Services\Payments;

use Mypos\Contracts\PaymentGatewayInterface;
use Mypos\Core\HttpException;
use Mypos\Support\Crypto;

final class PaymentGatewayFactory
{
    public static function fromConfig(array $config):PaymentGatewayInterface
    {
        $provider=strtoupper((string)($config['proveedor']??''));
        if($provider==='FLOW'){
            $apiKey=trim((string)($config['credencial_publica']??''));$secret=self::decrypt($config['secreto_cifrado']??null);
            if($apiKey===''||$secret==='')throw new HttpException('Credenciales Flow incompletas',422);
            return new FlowPaymentGateway($apiKey,$secret,(string)($config['ambiente']??'sandbox'),isset($config['merchant_id'])?(string)$config['merchant_id']:null);
        }
        if($provider==='MERCADOPAGO'){
            $token=self::decrypt($config['access_token_cifrado']??null);if($token==='')throw new HttpException('Mercado Pago no esta conectado',422);
            return new MercadoPagoCheckoutGateway($token);
        }
        throw new HttpException('Pasarela no soportada',422);
    }
    private static function decrypt(mixed $v):string{return is_string($v)&&$v!==''?Crypto::decrypt($v):'';}
}
