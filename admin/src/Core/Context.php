<?php
declare(strict_types=1);

namespace App\Core;

use App\Repositories\EmpresaRepository;
use Exception;

/**
 * Gestiona el contexto dinámico de la empresa y el ambiente (Cert/Prod).
 */
class Context
{
    private array $empresa;
    private string $ambiente;
    private string $basePath;

    public function __construct($idOrKey)
    {
        $repo = new EmpresaRepository();

        // Resolver a variable local primero: $empresa es de tipo array y asignarle
        // null (cuando getById/getByApiKey no encuentran) provocaría un TypeError
        // fatal antes del chequeo. Validamos y lanzamos la excepción controlada.
        $empresa = (is_numeric($idOrKey) && (int)$idOrKey > 0)
            ? $repo->getById((int)$idOrKey)
            : $repo->getByApiKey((string)$idOrKey);

        if (!$empresa || !is_array($empresa)) {
            throw new Exception("Acceso denegado: Empresa inválida o inactiva (id/clave: " . (string)$idOrKey . ").");
        }
        $this->empresa = $empresa;

        $this->ambiente = $this->empresa['ambiente_default'];
        $this->basePath = dirname(__DIR__, 2);
        
        $this->ensureDirectories();
    }

    public function getEmpresaId(): int { return (int)$this->empresa['id']; }
    public function getEmpresa(): array { return $this->empresa; }
    public function getAmbiente(): string { return strtoupper($this->ambiente); }
    public function getRut(): string { return $this->empresa['rut']; }
    
    /**
     * Retorna la ruta al certificado PFX de la empresa.
     */
    public function getCertPath(): string 
    { 
        $dir = "{$this->basePath}/cert/{$this->getRut()}";
        $conf = $dir . '/cert.conf';
        if (is_file($conf)) {
            $data = json_decode((string)file_get_contents($conf), true);
            $file = is_array($data) ? (string)($data['pfx_file'] ?? '') : '';
            if ($file !== '') {
                $path = preg_match('#^(/|[A-Za-z]:[\\\\/])#', $file) ? $file : ($dir . '/' . $file);
                if (is_file($path)) {
                    return $path;
                }
            }
        }
        return $dir . "/firma.pfx"; 
    }
    
    /**
     * Retorna la ruta al CAF específico de la empresa y tipo.
     */
    public function getCafPath(int $tipo): string 
    { 
        return "{$this->basePath}/caf/{$this->getRut()}/caf_{$tipo}.xml"; 
    }

    /**
     * Retorna la ruta temporal de la empresa.
     */
    public function getTmpPath(): string
    {
        return "{$this->basePath}/tmp/{$this->getRut()}/";
    }

    /**
     * Retorna la ruta persistente del set de certificación SII de la empresa.
     * (No vive en tmp/ porque el deploy regenera tmp/).
     */
    public function getSetPath(): string
    {
        return "{$this->basePath}/cert_sets/{$this->getRut()}/set.json";
    }

    /**
     * Asegura que los directorios específicos de la empresa existan.
     */
    private function ensureDirectories(): void
    {
        $dirs = [
            dirname($this->getCertPath()),
            dirname($this->getCafPath(0)), // Solo el directorio base de CAFs
            dirname($this->getSetPath()),
            $this->getTmpPath()
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    }
}
