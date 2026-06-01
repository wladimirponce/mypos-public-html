<?php

declare(strict_types=1);

namespace Mypos\Services;

use DateTimeImmutable;
use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\DocumentoIaRepository;
use Mypos\Repositories\UploadRepository;

final class UploadService
{
    private const PRODUCT_MIMES = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];
    private const DOCUMENT_MIMES = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'application/pdf' => ['pdf'],
    ];
    private const MAX_IMAGE_BYTES = 5242880;
    private const MAX_DOCUMENT_BYTES = 15728640;

    private UploadRepository $repository;
    private DocumentoIaRepository $documentosIa;

    public function __construct(?UploadRepository $repository = null, ?DocumentoIaRepository $documentosIa = null)
    {
        $connection = Database::connection();
        $this->repository = $repository ?? new UploadRepository($connection);
        $this->documentosIa = $documentosIa ?? new DocumentoIaRepository($connection);
    }

    public function subirProducto(int $userId, array $post, array $file): array
    {
        error_log("UploadService: subirProducto - post: " . print_r($post, true) . " - file keys: " . print_r(array_keys($file), true));
        $empresaId = $this->positiveInt($post, 'empresa_id');
        $productoId = isset($post['producto_id']) && (int) $post['producto_id'] > 0 ? (int) $post['producto_id'] : null;
        $this->requireEmpresa($empresaId);

        if ($productoId !== null && !$this->repository->productoExists($empresaId, $productoId)) {
            throw new HttpException('Producto no encontrado', 422);
        }

        $stored = $this->storeFile($empresaId, $userId, 'productos', $file, self::PRODUCT_MIMES, self::MAX_IMAGE_BYTES);
        $archivoId = $this->repository->insert($this->metadata($stored, [
            'empresa_id' => $empresaId,
            'sucursal_id' => null,
            'usuario_id' => $userId,
            'modulo' => 'PRODUCTOS',
            'entidad' => 'productos',
            'entidad_id' => $productoId,
            'estado' => 'ACTIVO',
            'metadata_json' => $this->jsonOrNull(['producto_id' => $productoId]),
        ]));

        $imagenId = null;
        if ($productoId !== null) {
            $imagenId = $this->repository->createProductImage($empresaId, $productoId, $stored['ruta_relativa']);
        }

        $this->audit($empresaId, null, $userId, 'upload.crear', 'archivos_subidos', $archivoId, [
            'modulo' => 'PRODUCTOS',
            'producto_id' => $productoId,
            'mime_type' => $stored['mime_type'],
            'size_bytes' => $stored['size_bytes'],
        ]);

        return [
            'archivo_id' => $archivoId,
            'producto_imagen_id' => $imagenId,
            'ruta_relativa' => $stored['ruta_relativa'],
            'mime_type' => $stored['mime_type'],
            'size_bytes' => $stored['size_bytes'],
        ];
    }

    public function subirDocumentoIa(int $userId, array $post, array $file): array
    {
        $empresaId = $this->positiveInt($post, 'empresa_id');
        $sucursalId = $this->positiveInt($post, 'sucursal_id');
        $this->requireEmpresa($empresaId);
        if (!$this->repository->sucursalExists($empresaId, $sucursalId)) {
            throw new HttpException('Sucursal no encontrada', 422);
        }

        $type = strtoupper(trim((string) ($post['tipo_documento_detectado'] ?? '')));
        if ($type !== '' && !in_array($type, ['FACTURA_COMPRA', 'GUIA_DESPACHO_COMPRA', 'BOLETA_COMPRA'], true)) {
            throw new HttpException('Tipo de documento invalido', 422);
        }

        $stored = $this->storeFile($empresaId, $userId, 'documentos_ia', $file, self::DOCUMENT_MIMES, self::MAX_DOCUMENT_BYTES);
        $archivoId = $this->repository->insert($this->metadata($stored, [
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'usuario_id' => $userId,
            'modulo' => 'DOCUMENTOS_IA',
            'entidad' => 'documentos_ia',
            'entidad_id' => null,
            'estado' => 'ACTIVO',
            'metadata_json' => $this->jsonOrNull(['tipo_documento_detectado' => $type ?: null]),
        ]));

        $documentId = $this->documentosIa->create([
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'usuario_id' => $userId,
            'archivo_subido_id' => $archivoId,
            'tipo_documento' => $type ?: null,
            'tipo_documento_detectado' => $type ?: null,
            'archivo_ruta' => $stored['ruta_relativa'],
            'archivo_url' => $stored['ruta_relativa'],
            'estado' => 'SUBIDO',
        ]);

        $this->audit($empresaId, $sucursalId, $userId, 'upload.crear', 'archivos_subidos', $archivoId, [
            'modulo' => 'DOCUMENTOS_IA',
            'documento_ia_id' => $documentId,
            'mime_type' => $stored['mime_type'],
            'size_bytes' => $stored['size_bytes'],
        ]);

        return [
            'archivo_id' => $archivoId,
            'documento_ia_id' => $documentId,
            'estado' => 'SUBIDO',
        ];
    }

    public function subirLogo(int $userId, array $post, array $file): array
    {
        $empresaId = $this->positiveInt($post, 'empresa_id');
        $this->requireEmpresa($empresaId);
        $stored = $this->storeFile($empresaId, $userId, 'logos', $file, self::PRODUCT_MIMES, self::MAX_IMAGE_BYTES);
        $archivoId = $this->repository->insert($this->metadata($stored, [
            'empresa_id' => $empresaId,
            'sucursal_id' => null,
            'usuario_id' => $userId,
            'modulo' => 'CONFIGURACION',
            'entidad' => 'empresa_configuracion',
            'entidad_id' => $empresaId,
            'estado' => 'ACTIVO',
            'metadata_json' => null,
        ]));
        $this->repository->updateLogo($empresaId, $stored['ruta_relativa']);
        $this->audit($empresaId, null, $userId, 'upload.crear', 'archivos_subidos', $archivoId, [
            'modulo' => 'CONFIGURACION',
            'mime_type' => $stored['mime_type'],
            'size_bytes' => $stored['size_bytes'],
        ]);

        return [
            'archivo_id' => $archivoId,
            'logo_url' => $stored['ruta_relativa'],
            'ruta_relativa' => $stored['ruta_relativa'],
            'mime_type' => $stored['mime_type'],
            'size_bytes' => $stored['size_bytes'],
        ];
    }

    public function subirCertificadoSii(int $userId, array $post, array $file): array
    {
        $empresaId = $this->positiveInt($post, 'empresa_id');
        $this->requireEmpresa($empresaId);
        
        $password = (string) ($post['password'] ?? '');
        if ($password === '') {
            throw new HttpException('La contraseña del certificado es obligatoria', 422);
        }
        
        $this->validateUpload($file);
        $tmpName = (string) $file['tmp_name'];
        $pfxContent = file_get_contents($tmpName);
        if (!is_string($pfxContent)) {
            throw new HttpException('No fue posible leer el certificado digital.', 422);
        }
        $certificate = $this->readOrNormalizePkcs12($pfxContent, $password, $empresaId);
        $fileToStore = $file;
        if ($certificate['normalized_content'] !== null) {
            $normalizedTmp = tempnam(sys_get_temp_dir(), 'mypos_pfx_normalized_');
            if ($normalizedTmp === false || file_put_contents($normalizedTmp, $certificate['normalized_content']) === false) {
                throw new HttpException('No fue posible preparar el certificado digital para guardarlo.', 500);
            }

            $fileToStore['tmp_name'] = $normalizedTmp;
            $fileToStore['size'] = filesize($normalizedTmp) ?: strlen($certificate['normalized_content']);
        }
        
        // Asumiendo que es válido, lo subimos
        $stored = $this->storeFile($empresaId, $userId, 'certificados', $fileToStore, [
            'application/x-pkcs12' => ['pfx', 'p12'],
            'application/octet-stream' => ['pfx', 'p12'],
        ], self::MAX_DOCUMENT_BYTES);
        
        $archivoId = $this->repository->insert($this->metadata($stored, [
            'empresa_id' => $empresaId,
            'sucursal_id' => null,
            'usuario_id' => $userId,
            'modulo' => 'CONFIGURACION',
            'entidad' => 'certificado_sii',
            'entidad_id' => $empresaId,
            'estado' => 'ACTIVO',
            'metadata_json' => $this->jsonOrNull([
                'password_certificado' => $password,
                'certificado_valido' => true,
                'normalizado_legacy' => $certificate['normalized_content'] !== null,
            ]),
        ]));
        
        $this->audit($empresaId, null, $userId, 'upload.crear', 'archivos_subidos', $archivoId, [
            'modulo' => 'CONFIGURACION',
            'mime_type' => $stored['mime_type'],
            'size_bytes' => $stored['size_bytes'],
        ]);

        return [
            'archivo_id' => $archivoId,
            'ruta_relativa' => $stored['ruta_relativa'],
            'valido' => true,
            'normalizado_legacy' => $certificate['normalized_content'] !== null,
        ];
    }

    public function verificarVigenciaCertificadoSii(int $empresaId): array
    {
        $this->requireEmpresa($empresaId);
        $file = $this->repository->findActiveCertificate($empresaId);
        
        if ($file === null) {
            return [
                'registrado' => false,
                'mensaje' => 'No hay ningún certificado digital registrado para esta empresa.',
            ];
        }

        $absolutePath = $this->absolutePath((string) $file['ruta_relativa']);
        if (!is_file($absolutePath)) {
            return [
                'registrado' => true,
                'valido' => false,
                'mensaje' => 'El archivo físico del certificado digital no se encuentra en el servidor.',
                'nombre_original' => (string) $file['nombre_original'],
                'created_at' => (string) $file['created_at'],
            ];
        }

        $metadata = json_decode((string) ($file['metadata_json'] ?? '{}'), true);
        $password = (string) ($metadata['password_certificado'] ?? '');

        $pfxContent = file_get_contents($absolutePath);
        try {
            $certs = $this->readPkcs12($pfxContent, $password, $empresaId);
        } catch (HttpException $exception) {
            return [
                'registrado' => true,
                'valido' => false,
                'mensaje' => $exception->getMessage(),
                'nombre_original' => (string) $file['nombre_original'],
                'created_at' => (string) $file['created_at'],
            ];
        }

        $certData = openssl_x509_parse($certs['cert']);
        if ($certData === false) {
            return [
                'registrado' => true,
                'valido' => false,
                'mensaje' => 'Error al analizar el certificado X509.',
                'nombre_original' => (string) $file['nombre_original'],
                'created_at' => (string) $file['created_at'],
            ];
        }

        $validFrom = (int) ($certData['validFrom_time_t'] ?? 0);
        $validTo = (int) ($certData['validTo_time_t'] ?? 0);
        $subject = $certData['subject'] ?? [];
        $cn = (string) ($subject['CN'] ?? 'Desconocido');
        
        $vencido = time() > $validTo;
        $diasRestantes = (int) floor(($validTo - time()) / 86400);

        return [
            'registrado' => true,
            'valido' => true,
            'nombre_original' => (string) $file['nombre_original'],
            'titular' => $cn,
            'valido_desde' => date('Y-m-d H:i:s', $validFrom),
            'valido_hasta' => date('Y-m-d H:i:s', $validTo),
            'vencido' => $vencido,
            'dias_restantes' => $diasRestantes,
            'created_at' => (string) $file['created_at'],
        ];
    }

    public function metadataArchivo(int $empresaId, int $id): array
    {
        $file = $this->requireFile($empresaId, $id);
        unset($file['hash_sha256']);

        return $file;
    }

    public function archivoDescargable(int $empresaId, int $id): array
    {
        $file = $this->requireFile($empresaId, $id);
        if (($file['estado'] ?? '') !== 'ACTIVO') {
            throw new HttpException('Archivo no disponible', 422);
        }

        $absolutePath = $this->absolutePath((string) $file['ruta_relativa']);
        if (!is_file($absolutePath)) {
            throw new HttpException('Archivo fisico no encontrado', 404);
        }

        return [
            'absolute_path' => $absolutePath,
            'mime_type' => (string) $file['mime_type'],
            'nombre_original' => (string) $file['nombre_original'],
            'size_bytes' => (int) $file['size_bytes'],
        ];
    }

    public function eliminar(int $userId, int $empresaId, int $id): array
    {
        $file = $this->requireFile($empresaId, $id);
        if (!$this->repository->softDelete($empresaId, $id)) {
            throw new HttpException('Archivo no disponible', 422);
        }

        $this->audit($empresaId, $file['sucursal_id'] !== null ? (int) $file['sucursal_id'] : null, $userId, 'upload.eliminar', 'archivos_subidos', $id, [
            'modulo' => $file['modulo'] ?? null,
            'entidad' => $file['entidad'] ?? null,
        ]);

        return ['archivo_id' => $id, 'estado' => 'ELIMINADO'];
    }

    public function absolutePath(string $rutaRelativa): string
    {
        $relative = str_replace('\\', '/', ltrim($rutaRelativa, '/\\'));
        if (str_contains($relative, '..')) {
            throw new HttpException('Ruta de archivo invalida', 422);
        }

        $base = $this->storageBase();
        $path = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $baseReal = realpath($base);
        $dirReal = realpath(dirname($path));

        if ($baseReal === false || $dirReal === false || !str_starts_with($dirReal, $baseReal)) {
            throw new HttpException('Ruta de archivo invalida', 422);
        }

        return $path;
    }

    private function storeFile(int $empresaId, int $userId, string $folder, array $file, array $allowed, int $maxBytes): array
    {
        $this->validateUpload($file);
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            error_log("UploadService: storeFile failed - size is " . $size . ", max is " . $maxBytes);
            throw new HttpException('El archivo excede el tamano permitido', 422);
        }

        $original = (string) ($file['name'] ?? 'archivo');
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $mime = $this->detectMime((string) $file['tmp_name']);

        if (!isset($allowed[$mime]) || !in_array($extension, $allowed[$mime], true)) {
            throw new HttpException('Tipo de archivo no permitido', 422);
        }

        $date = new DateTimeImmutable();
        $name = bin2hex(random_bytes(16)) . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
        $relativeDir = sprintf('uploads/%s/empresa_%d/%s/%s', $folder, $empresaId, $date->format('Y'), $date->format('m'));
        $absoluteDir = $this->storageBase() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);

        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
            throw new HttpException('No fue posible crear directorio de almacenamiento', 500);
        }

        $target = $absoluteDir . DIRECTORY_SEPARATOR . $name;
        $tmpName = (string) $file['tmp_name'];
        $hash = hash_file('sha256', $tmpName);

        if (!move_uploaded_file($tmpName, $target)) {
            if (!rename($tmpName, $target)) {
                throw new HttpException('No fue posible guardar el archivo', 500);
            }
        }

        return [
            'nombre_original' => basename($original),
            'nombre_storage' => $name,
            'ruta_relativa' => $relativeDir . '/' . $name,
            'mime_type' => $mime,
            'extension' => $extension === 'jpeg' ? 'jpg' : $extension,
            'size_bytes' => $size,
            'hash_sha256' => $hash,
            'usuario_id' => $userId,
        ];
    }

    private function metadata(array $stored, array $extra): array
    {
        return array_merge($extra, [
            'nombre_original' => $stored['nombre_original'],
            'nombre_storage' => $stored['nombre_storage'],
            'ruta_relativa' => $stored['ruta_relativa'],
            'mime_type' => $stored['mime_type'],
            'extension' => $stored['extension'],
            'size_bytes' => $stored['size_bytes'],
            'hash_sha256' => $stored['hash_sha256'],
        ]);
    }

    private function validateUpload(array $file): void
    {
        if ($file === [] || !isset($file['tmp_name'])) {
            error_log("UploadService: validateUpload failed - empty file or no tmp_name. Keys: " . print_r(array_keys($file), true));
            throw new HttpException('archivo obligatorio', 422);
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            error_log("UploadService: validateUpload failed - file error code: " . $error);
            throw new HttpException('Archivo invalido o no recibido', 422);
        }
    }

    private function detectMime(string $tmpName): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpName);
        if (!is_string($mime) || $mime === '') {
            throw new HttpException('No fue posible detectar tipo de archivo', 422);
        }

        return $mime;
    }

    private function requireFile(int $empresaId, int $id): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }

        $file = $this->repository->find($empresaId, $id);
        if ($file === null) {
            throw new HttpException('Archivo no encontrado', 404);
        }

        return $file;
    }

    private function requireEmpresa(int $empresaId): void
    {
        if ($empresaId <= 0 || !$this->repository->empresaExists($empresaId)) {
            throw new HttpException('Empresa no encontrada', 422);
        }
    }

    private function positiveInt(array $data, string $field): int
    {
        $value = (int) ($data[$field] ?? 0);
        if ($value <= 0) {
            throw new HttpException('Error de validacion', 422, [$field => ["El campo {$field} es obligatorio"]]);
        }

        return $value;
    }

    /**
     * PFX antiguos del SII suelen venir con RC2/3DES. En OpenSSL 3 eso exige
     * provider legacy; sin el provider, el error real es "unsupported", no clave mala.
     */
    private function readOrNormalizePkcs12(string $pfxContent, string $password, int $empresaId): array
    {
        try {
            return [
                'certs' => $this->readPkcs12($pfxContent, $password, $empresaId),
                'normalized_content' => null,
            ];
        } catch (HttpException $exception) {
            if (!$this->isLegacyPkcs12Exception($exception)) {
                throw $exception;
            }
        }

        $normalizedContent = $this->normalizeLegacyPkcs12($pfxContent, $password, $empresaId);

        return [
            'certs' => $this->readPkcs12($normalizedContent, $password, $empresaId),
            'normalized_content' => $normalizedContent,
        ];
    }

    private function readPkcs12(string $pfxContent, string $password, int $empresaId): array
    {
        $this->configureOpenSslLegacyProvider();
        $this->drainOpenSslErrors();

        $certs = [];
        if (openssl_pkcs12_read($pfxContent, $certs, $password)) {
            return $certs;
        }

        $errors = $this->drainOpenSslErrors();
        $errorMsg = implode('; ', $errors);
        error_log("UploadService: openssl_pkcs12_read failed for company {$empresaId}. Errors: " . $errorMsg);

        if ($this->isOpenSslUnsupportedAlgorithm($errors)) {
            throw new HttpException(
                'El certificado usa cifrado legacy del SII (RC2/3DES). MyPOS intentara convertirlo automaticamente.',
                422
            );
        }

        throw new HttpException('Contraseña incorrecta o certificado digital inválido.', 422);
    }

    private function normalizeLegacyPkcs12(string $pfxContent, string $password, int $empresaId): string
    {
        $openssl = $this->opensslBinary();
        if ($openssl === null) {
            throw new HttpException(
                'El certificado usa un formato antiguo del SII y este servidor no tiene disponible la herramienta de conversion automatica.',
                422
            );
        }

        $workDir = $this->createTempDirectory();
        $inputPath = $workDir . DIRECTORY_SEPARATOR . 'original.pfx';
        $pemPath = $workDir . DIRECTORY_SEPARATOR . 'certificate.pem';
        $outputPath = $workDir . DIRECTORY_SEPARATOR . 'modern.pfx';

        try {
            if (file_put_contents($inputPath, $pfxContent) === false) {
                throw new HttpException('No fue posible preparar el certificado digital para convertirlo.', 500);
            }

            $env = ['MYPOS_PFX_PASSWORD' => $password];
            $modulesPath = $this->opensslModulesPath($openssl);
            if ($modulesPath !== null) {
                $env['OPENSSL_MODULES'] = $modulesPath;
            }

            $this->runOpenSsl([
                $openssl,
                'pkcs12',
                '-legacy',
                '-in',
                $inputPath,
                '-nodes',
                '-out',
                $pemPath,
                '-passin',
                'env:MYPOS_PFX_PASSWORD',
            ], $env, $workDir, $empresaId);

            $this->runOpenSsl([
                $openssl,
                'pkcs12',
                '-export',
                '-in',
                $pemPath,
                '-out',
                $outputPath,
                '-passout',
                'env:MYPOS_PFX_PASSWORD',
                '-certpbe',
                'AES-256-CBC',
                '-keypbe',
                'AES-256-CBC',
                '-macalg',
                'sha256',
            ], $env, $workDir, $empresaId);

            $normalized = file_get_contents($outputPath);
            if (!is_string($normalized) || $normalized === '') {
                throw new HttpException('No fue posible convertir el certificado digital.', 500);
            }

            return $normalized;
        } finally {
            $this->deleteTempDirectory($workDir);
        }
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     */
    private function runOpenSsl(array $command, array $env, string $workDir, int $empresaId): void
    {
        if (!function_exists('proc_open')) {
            throw new HttpException(
                'El certificado usa un formato antiguo del SII y este servidor no permite ejecutar la conversion automatica.',
                422
            );
        }

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, $workDir, array_merge($_ENV, $_SERVER, $env));
        if (!is_resource($process)) {
            throw new HttpException('No fue posible iniciar la conversion automatica del certificado digital.', 500);
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $error = trim((string) $stderr . "\n" . (string) $stdout);
            error_log("UploadService: OpenSSL legacy conversion failed for company {$empresaId}. Exit {$exitCode}. Output: " . $error);

            if (stripos($error, 'mac verify failure') !== false || stripos($error, 'invalid password') !== false) {
                throw new HttpException('ContraseÃ±a incorrecta o certificado digital invÃ¡lido.', 422);
            }

            if (stripos($error, 'unable to load provider legacy') !== false || stripos($error, 'legacy') !== false) {
                throw new HttpException(
                    'El certificado usa un formato antiguo del SII y el servidor no tiene disponible el componente legacy necesario para convertirlo automaticamente.',
                    422
                );
            }

            throw new HttpException('No fue posible convertir automaticamente el certificado digital.', 422);
        }
    }

    private function opensslBinary(): ?string
    {
        $configured = trim((string) ($_ENV['OPENSSL_BIN'] ?? getenv('OPENSSL_BIN') ?: ''));
        if ($configured !== '' && is_file($configured) && is_executable($configured)) {
            return $configured;
        }

        foreach (['/usr/bin/openssl', '/usr/local/bin/openssl', 'openssl'] as $candidate) {
            if ($candidate === 'openssl' || (is_file($candidate) && is_executable($candidate))) {
                return $candidate;
            }
        }

        return null;
    }

    private function opensslModulesPath(string $openssl): ?string
    {
        $configured = trim((string) ($_ENV['OPENSSL_MODULES'] ?? getenv('OPENSSL_MODULES') ?: ''));
        if ($configured !== '' && is_dir($configured)) {
            return $configured;
        }

        $binaryDir = dirname($openssl);
        if (is_file($binaryDir . DIRECTORY_SEPARATOR . 'legacy.dll')) {
            return $binaryDir;
        }

        return null;
    }

    private function createTempDirectory(): string
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mypos_pfx_' . bin2hex(random_bytes(8));
        if (!mkdir($base, 0700, true) && !is_dir($base)) {
            throw new HttpException('No fue posible crear directorio temporal para el certificado.', 500);
        }

        return $base;
    }

    private function deleteTempDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (glob($path . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($path);
    }

    private function isLegacyPkcs12Exception(HttpException $exception): bool
    {
        return str_contains($exception->getMessage(), 'formato antiguo')
            || str_contains($exception->getMessage(), 'cifrado legacy')
            || str_contains($exception->getMessage(), 'RC2/3DES');
    }

    private function configureOpenSslLegacyProvider(): void
    {
        $configPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'openssl-legacy.cnf';
        if (is_file($configPath)) {
            putenv('OPENSSL_CONF=' . $configPath);
            $_ENV['OPENSSL_CONF'] = $configPath;
            $_SERVER['OPENSSL_CONF'] = $configPath;
        }
    }

    /**
     * @return list<string>
     */
    private function drainOpenSslErrors(): array
    {
        $errors = [];
        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        return $errors;
    }

    /**
     * @param list<string> $errors
     */
    private function isOpenSslUnsupportedAlgorithm(array $errors): bool
    {
        $joined = strtolower(implode(' ', $errors));
        return str_contains($joined, 'unsupported')
            || str_contains($joined, 'rc2')
            || str_contains($joined, 'inner_evp_generic_fetch');
    }

    private function storageBase(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage';
    }

    private function jsonOrNull(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
    }

    private function audit(int $empresaId, ?int $sucursalId, int $userId, string $action, string $entity, int $entityId, array $metadata): void
    {
        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'usuario_id' => $userId,
            'modulo' => 'uploads',
            'accion' => $action,
            'entidad' => $entity,
            'entidad_id' => $entityId,
            'descripcion' => 'Operacion de upload',
            'metadata' => $metadata,
        ]);
    }
}
