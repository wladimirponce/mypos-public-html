<?php

declare(strict_types=1);

namespace Mypos\Middleware;

use Mypos\Core\Response;
use Mypos\Support\AppConfig;
use Mypos\Support\SafeLogger;

final class RateLimitMiddleware
{
    public function handle(): void
    {
        if (!AppConfig::rateLimitEnabled()) {
            return;
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $isLogin = $path === '/api/v1/auth/login';
        $max = $isLogin ? AppConfig::rateLimitLoginMaxRequests() : AppConfig::rateLimitMaxRequests();
        $window = $isLogin ? AppConfig::rateLimitLoginWindowSeconds() : AppConfig::rateLimitWindowSeconds();
        $key = $this->key($path, $isLogin);
        $file = $this->filePath($key);
        $now = time();
        $data = $this->read($file);

        if (($data['reset_at'] ?? 0) <= $now) {
            $data = ['count' => 0, 'reset_at' => $now + $window];
        }

        $data['count'] = (int) ($data['count'] ?? 0) + 1;
        $this->write($file, $data);
        $this->cleanup(dirname($file), $now);

        if ($data['count'] <= $max) {
            return;
        }

        SafeLogger::warning('Rate limit exceeded', [
            'path' => $path,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'login' => $isLogin,
        ]);

        Response::error(
            'Demasiadas solicitudes. Intenta nuevamente más tarde.',
            ['rate_limit' => 'exceeded'],
            429
        );
    }

    private function key(string $path, bool $isLogin): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $subject = $authorization !== '' && !$isLogin ? hash('sha256', $authorization) : $ip;

        return hash('sha256', $subject . '|' . $path . '|' . ($isLogin ? 'login' : 'global'));
    }

    private function filePath(string $key): string
    {
        $dir = AppConfig::storagePath() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'rate_limit';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir . DIRECTORY_SEPARATOR . $key . '.json';
    }

    private function read(string $file): array
    {
        if (!is_file($file)) {
            return ['count' => 0, 'reset_at' => 0];
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : ['count' => 0, 'reset_at' => 0];
    }

    private function write(string $file, array $data): void
    {
        file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function cleanup(string $dir, int $now): void
    {
        if (random_int(1, 100) !== 1) {
            return;
        }

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $data = $this->read($file);
            if (($data['reset_at'] ?? 0) < $now) {
                @unlink($file);
            }
        }
    }
}
