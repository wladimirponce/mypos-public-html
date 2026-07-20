<?php

declare(strict_types=1);

namespace Mypos\Contracts;

interface PaymentGatewayInterface
{
    /** @return array{ok:bool,account_id:?string,message:string} */
    public function validateCredentials(): array;
    /** @param array<string,mixed> $payment @return array<string,mixed> */
    public function createPayment(array $payment): array;
    /** @return array<string,mixed> */
    public function getPayment(string $providerId): array;
    /** @return array<string,mixed> */
    public function refund(string $providerId, ?int $amount = null): array;
}
