<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\InteligenciaRepository;

final class InteligenciaService
{
    private InteligenciaRepository $repository;

    public function __construct(?InteligenciaRepository $repository = null)
    {
        $this->repository = $repository ?? new InteligenciaRepository(Database::connection());
    }

    public function feedback(int $usuarioId, array $data): array
    {
        $empresaId = (int) ($data['empresa_id'] ?? 0);
        $referenciaId = (int) ($data['referencia_id'] ?? 0);
        $origen = strtoupper(trim((string) ($data['origen'] ?? '')));
        $valor = strtoupper(trim((string) ($data['valor'] ?? '')));
        if ($empresaId<=0 || $referenciaId<=0 || !in_array($origen,['ALERTA','RECOMENDACION_COMPRA'],true) || !in_array($valor,['UTIL','NO_UTIL','RESUELTA','ACEPTADA','MODIFICADA'],true)) {
            throw new HttpException('Feedback de inteligencia invalido', 422);
        }
        if ($origen === 'ALERTA' && !$this->repository->alertaPerteneceAEmpresa($empresaId, $referenciaId)) {
            throw new HttpException('La alerta no pertenece a la empresa activa', 404);
        }
        if ($origen === 'RECOMENDACION_COMPRA' && !$this->repository->compraPerteneceAEmpresa($empresaId, $referenciaId)) {
            throw new HttpException('La compra no pertenece a la empresa activa', 404);
        }
        $id = $this->repository->registrarFeedback($empresaId,$usuarioId,$origen,$referenciaId,$valor,isset($data['comentario'])?mb_substr(trim((string)$data['comentario']),0,500):null);
        return ['id'=>$id,'registrado'=>true];
    }

    public function alertas(array $filters): array
    {
        $empresaId=(int)($filters['empresa_id']??0); if($empresaId<=0) throw new HttpException('empresa_id obligatorio',422);
        return ['alertas'=>$this->repository->alertas($empresaId,max(1,min(100,(int)($filters['limit']??50))))];
    }

    public function impacto(array $filters): array
    {
        $empresaId=(int)($filters['empresa_id']??0); if($empresaId<=0) throw new HttpException('empresa_id obligatorio',422);
        $dias=max(1,min(365,(int)($filters['dias']??30)));
        return ['dias'=>$dias]+$this->repository->impacto($empresaId,$dias);
    }
}
