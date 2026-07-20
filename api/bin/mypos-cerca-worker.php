<?php

declare(strict_types=1);

use Mypos\Services\MyposCercaService;
use Mypos\Services\OnlinePaymentService;

require dirname(__DIR__) . '/vendor/autoload.php';

$limit=max(1,min(500,(int)($argv[1]??100)));
$expired=(new MyposCercaService())->expireReservations($limit);
$payments=(new OnlinePaymentService())->reconcile(min($limit,100));
echo json_encode(['reservas'=>$expired,'pagos'=>$payments],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
