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
        
        if (is_numeric($idOrKey) && (int)$idOrKey > 0) {
            $this->empresa = $repo->getById((int)$idOrKey);
        } else {
            $this->empresa = $repo->getByApiKey((string)$idOrKey);
        }
        
        if (!$this->empresa) {
            throw new Exception("Acceso denegado: Empresa inválida o inactiva.");
        }

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
        return "{$this->basePath}/cert/{$this->getRut()}/firma.pfx"; 
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
     * Asegura que los directorios específicos de la empresa existan.
     */
    private function ensureDirectories(): void
    {
        $dirs = [
            dirname($this->getCertPath()),
            dirname($this->getCafPath(0)), // Solo el directorio base de CAFs
            $this->getTmpPath()
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    }
}
