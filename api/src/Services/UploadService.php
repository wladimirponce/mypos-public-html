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
        
        $certs = [];
        if (!openssl_pkcs12_read($pfxContent, $certs, $password)) {
            throw new HttpException('Contraseña incorrecta o archivo de certificado inválido', 422);
        }
        
        // Asumiendo que es válido, lo subimos
        $stored = $this->storeFile($empresaId, $userId, 'certificados', $file, [
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
            'metadata_json' => $this->jsonOrNull(['password_certificado' => $password, 'certificado_valido' => true]),
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
            throw new HttpException('archivo obligatorio', 422);
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
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
