<?php

declare(strict_types=1);

namespace Mypos\Support;

use Mypos\Core\HttpException;

final class GatewayHttpClient
{
    /** @return array{status:int,body:array<string,mixed>,raw:string} */
    public function request(string $method,string $url,array $headers=[],array|string|null $body=null,int $timeout=30):array
    {
        $h=curl_init($url);if($h===false)throw new HttpException('No se pudo iniciar conexion con la pasarela',502);
        $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>10];
        if($body!==null)$opts[CURLOPT_POSTFIELDS]=is_array($body)?http_build_query($body):$body;
        curl_setopt_array($h,$opts);$raw=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_HTTP_CODE);$error=curl_error($h);curl_close($h);
        if($raw===false)throw new HttpException('Error de red al conectar con la pasarela: '.$error,502);
        $decoded=json_decode((string)$raw,true);return['status'=>$status,'body'=>is_array($decoded)?$decoded:[],'raw'=>(string)$raw];
    }
}
