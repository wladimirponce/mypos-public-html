<?php

declare(strict_types=1);

namespace Mypos\Services;

use DateTimeImmutable;
use Mypos\Core\HttpException;
use Mypos\Repositories\AuthSessionRepository;
use Mypos\Support\AppConfig;
use PDO;
use Throwable;

final class AuthSessionService
{
    private const COOKIE_NAME = 'mypos_refresh';

    public function __construct(private readonly PDO $connection, private readonly AuthSessionRepository $repository)
    {
    }

    public static function enabled(): bool
    {
        $raw = $_ENV['AUTH_REFRESH_ENABLED'] ?? getenv('AUTH_REFRESH_ENABLED') ?: '0';
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    public static function assertTrustedOrigin(): void
    {
        $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($origin !== '' && !in_array($origin, AppConfig::corsAllowedOrigins(), true)) {
            throw new HttpException('Origen no autorizado', 403);
        }
    }

    public function start(int $userId): string
    {
        $token = $this->newToken();
        $this->repository->create($this->sessionData($userId, $this->uuidV4(), $token));
        return $token;
    }

    /** @return array{user_id:int,token:string} */
    public function rotate(string $token): array
    {
        if ($token === '') {
            throw new HttpException('Sesion no disponible', 401);
        }
        $hash = hash('sha256', $token);
        try {
            $this->connection->beginTransaction();
            $session = $this->repository->findByHashForUpdate($hash);
            if ($session === null) {
                throw new HttpException('Sesion no valida', 401);
            }
            if ($session['revoked_at'] !== null || $session['replaced_by_hash'] !== null) {
                $this->repository->revokeFamily((string) $session['family_id'], 'replay_detected');
                $this->connection->commit();
                throw new HttpException('Sesion revocada por seguridad', 401);
            }
            if (strtotime((string) $session['expires_at']) <= time()) {
                $this->repository->revokeByHash($hash, 'expired');
                $this->connection->commit();
                throw new HttpException('Sesion expirada', 401);
            }

            $replacement = $this->newToken();
            $replacementHash = hash('sha256', $replacement);
            $this->repository->create($this->sessionData(
                (int) $session['usuario_id'],
                (string) $session['family_id'],
                $replacement
            ));
            $this->repository->markRotated((int) $session['id'], $replacementHash);
            $this->connection->commit();
            return ['user_id' => (int) $session['usuario_id'], 'token' => $replacement];
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    public function revoke(string $token): void
    {
        if ($token !== '') {
            $this->repository->revokeByHash(hash('sha256', $token), 'logout');
        }
    }

    public static function cookieToken(): string
    {
        return is_string($_COOKIE[self::COOKIE_NAME] ?? null) ? (string) $_COOKIE[self::COOKIE_NAME] : '';
    }

    public static function setCookie(string $token): void
    {
        setcookie(self::COOKIE_NAME, $token, self::cookieOptions(time() + self::ttlSeconds()));
    }

    public static function clearCookie(): void
    {
        setcookie(self::COOKIE_NAME, '', self::cookieOptions(time() - 3600));
    }

    /** @return array<string,mixed> */
    private function sessionData(int $userId, string $familyId, string $token): array
    {
        return [
            'usuario_id' => $userId,
            'family_id' => $familyId,
            'refresh_token_hash' => hash('sha256', $token),
            'ip_hash' => $this->fingerprint('REMOTE_ADDR'),
            'user_agent_hash' => $this->fingerprint('HTTP_USER_AGENT'),
            'expires_at' => (new DateTimeImmutable())->modify('+' . self::ttlSeconds() . ' seconds')->format('Y-m-d H:i:s'),
        ];
    }

    private static function cookieOptions(int $expires): array
    {
        $configured = $_ENV['COOKIE_SECURE'] ?? getenv('COOKIE_SECURE');
        $secure = $configured === false || $configured === ''
            ? (($_SERVER['HTTPS'] ?? 'off') !== 'off')
            : filter_var($configured, FILTER_VALIDATE_BOOLEAN);
        return ['expires' => $expires, 'path' => '/api/v1/auth', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict'];
    }

    private static function ttlSeconds(): int
    {
        $days = (int) ($_ENV['REFRESH_TOKEN_TTL_DAYS'] ?? getenv('REFRESH_TOKEN_TTL_DAYS') ?: 30);
        return max(1, min($days, 90)) * 86400;
    }

    private function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function fingerprint(string $key): ?string
    {
        $value = trim((string) ($_SERVER[$key] ?? ''));
        return $value === '' ? null : hash('sha256', $value);
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
