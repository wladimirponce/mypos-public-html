<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Repositories\InventarioFisicoRepository;
use Mypos\Repositories\ProductoRepository;
use PDO;
use Throwable;

final class InventarioFisicoService
{
    private PDO $pdo;
    private InventarioFisicoRepository $repository;
    private ProductoRepository $productoRepository;
    private StockService $stockService;

    public function __construct()
    {
        $this->pdo = Database::connection();
        $this->repository         = new InventarioFisicoRepository($this->pdo);
        $this->productoRepository = new ProductoRepository($this->pdo);
        $this->stockService       = new StockService();
    }

    public function listar(int $userId, array $params): array
    {
        $empresaId = $this->requireEmpresaId($params);
        $this->tenant($userId, $empresaId);

        $rows = $this->repository->list($empresaId, 30);

        return ['inventarios' => array_map(static fn (array $r): array => [
            'id'             => (int) $r['id'],
            'nombre'         => $r['nombre'],
            'estado'         => $r['estado'],
            'sucursal_id'    => (int) $r['sucursal_id'],
            'sucursal_nombre' => $r['sucursal_nombre'] ?? null,
            'total_items'    => (int) $r['total_items'],
            'items_contados' => (int) $r['items_contados'],
            'items_con_dif'  => (int) $r['items_con_dif'],
            'notas'          => $r['notas'],
            'created_at'     => $r['created_at'],
            'applied_at'     => $r['applied_at'],
        ], $rows)];
    }

    public function crear(int $userId, array $body): array
    {
        $empresaId  = $this->requireEmpresaId($body);
        $sucursalId = $this->requirePositiveInt($body, 'sucursal_id', 'sucursal_id es obligatorio');
        $nombre     = trim((string) ($body['nombre'] ?? ''));
        if ($nombre === '') {
            throw new HttpException('El nombre del inventario es obligatorio', 422);
        }
        $this->tenant($userId, $empresaId);

        $id = $this->repository->create([
            'empresa_id'  => $empresaId,
            'sucursal_id' => $sucursalId,
            'nombre'      => $nombre,
            'usuario_id'  => $userId,
        ]);

        $total = $this->repository->populateItems($id, $empresaId, $sucursalId);

        return ['id' => $id, 'total_items' => $total];
    }

    public function get(int $userId, int $id, array $params): array
    {
        $empresaId = $this->requireEmpresaId($params);
        $this->tenant($userId, $empresaId);

        $inv = $this->findOrFail($empresaId, $id);
        $items = $this->repository->getItems($id, $empresaId);

        return [
            'id'             => (int) $inv['id'],
            'nombre'         => $inv['nombre'],
            'estado'         => $inv['estado'],
            'sucursal_id'    => (int) $inv['sucursal_id'],
            'total_items'    => (int) $inv['total_items'],
            'items_contados' => (int) $inv['items_contados'],
            'items_con_dif'  => (int) $inv['items_con_dif'],
            'notas'          => $inv['notas'],
            'created_at'     => $inv['created_at'],
            'applied_at'     => $inv['applied_at'],
            'items'          => array_map(static fn (array $it): array => [
                'id'                   => (int) $it['id'],
                'producto_id'          => (int) $it['producto_id'],
                'nombre_producto'      => $it['nombre_producto'],
                'codigo_producto'      => $it['codigo_producto'],
                'cantidad_sistema'     => (float) $it['cantidad_sistema'],
                'cantidad_contada'     => $it['cantidad_contada'] !== null ? (float) $it['cantidad_contada'] : null,
                'ajuste_movimiento_id' => $it['ajuste_movimiento_id'] !== null ? (int) $it['ajuste_movimiento_id'] : null,
            ], $items),
        ];
    }

    public function guardarConteos(int $userId, int $id, array $body): array
    {
        $empresaId = $this->requireEmpresaId($body);
        $this->tenant($userId, $empresaId);

        $inv = $this->findOrFail($empresaId, $id);
        if ($inv['estado'] === 'APLICADO') {
            throw new HttpException('El inventario ya fue aplicado y no puede modificarse', 409);
        }

        $items = $body['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw new HttpException('Se requiere al menos un item con cantidad_contada', 422);
        }

        $saved = $this->repository->saveConteoLote($id, $empresaId, $items);

        return ['saved' => $saved];
    }

    public function aplicar(int $userId, int $id, array $params): array
    {
        $empresaId = $this->requireEmpresaId($params);
        $this->tenant($userId, $empresaId);

        $inv = $this->findOrFail($empresaId, $id);
        if ($inv['estado'] === 'APLICADO') {
            throw new HttpException('El inventario ya fue aplicado', 409);
        }

        $items = $this->repository->getItemsConDiferencia($id, $empresaId);
        $ajustados = 0;

        foreach ($items as $item) {
            $diferencia = round((float) $item['cantidad_contada'] - (float) $item['cantidad_sistema'], 3);
            if (abs($diferencia) < 0.001) {
                continue;
            }

            $resultado = $this->stockService->ajustarStock([
                'empresa_id'     => $empresaId,
                'sucursal_id'    => (int) $inv['sucursal_id'],
                'producto_id'    => (int) $item['producto_id'],
                'tipo'           => 'AJUSTE',
                'cantidad'       => $diferencia,
                'referencia_tipo' => 'INVENTARIO_FISICO',
                'referencia_id'  => $id,
                'usuario_id'     => $userId,
                'observacion'    => 'Inventario físico: ' . $inv['nombre'],
            ]);

            $this->repository->setAjusteMovimiento((int) $item['id'], (int) $resultado['movimiento_id']);
            $ajustados++;
        }

        $this->repository->marcarAplicado($empresaId, $id);

        return [
            'inventario_id' => $id,
            'estado'        => 'APLICADO',
            'ajustados'     => $ajustados,
        ];
    }

    // ─── Barrido con lector ───

    /**
     * Obtiene o crea la sesión de barrido ABIERTA de la sucursal (regla
     * "mismo día = misma sesión": mientras siga abierta, se continúa).
     */
    public function abrirSesionBarrido(int $userId, array $body): array
    {
        $empresaId  = $this->requireEmpresaId($body);
        $sucursalId = $this->requirePositiveInt($body, 'sucursal_id', 'sucursal_id es obligatorio');
        $this->tenant($userId, $empresaId);

        $sesion = $this->repository->findSesionAbierta($empresaId, $sucursalId);
        if ($sesion === null) {
            $nombre = trim((string) ($body['nombre'] ?? ''));
            if ($nombre === '') {
                $nombre = 'Barrido ' . date('d-m-Y H:i');
            }
            $id = $this->repository->createBarrido([
                'empresa_id'  => $empresaId,
                'sucursal_id' => $sucursalId,
                'nombre'      => $nombre,
                'usuario_id'  => $userId,
            ]);
            $sesion = $this->findOrFail($empresaId, $id);
        }

        return $this->barridoPayload($empresaId, $sesion);
    }

    public function getBarrido(int $userId, int $id, array $params): array
    {
        $empresaId = $this->requireEmpresaId($params);
        $this->tenant($userId, $empresaId);

        $sesion = $this->findOrFail($empresaId, $id);

        return $this->barridoPayload($empresaId, $sesion);
    }

    /**
     * Registra una lectura del lector. Resuelve el producto por código exacto
     * (codigo/sku/código de barras) y acumula. Rechaza códigos no reconocidos y
     * productos que no controlan stock.
     */
    public function escanear(int $userId, int $id, array $body): array
    {
        $empresaId = $this->requireEmpresaId($body);
        $this->tenant($userId, $empresaId);

        $sesion = $this->findOrFail($empresaId, $id);
        if ($sesion['tipo'] !== 'BARRIDO' || $sesion['estado'] !== 'ABIERTA') {
            throw new HttpException('La sesión de barrido no está abierta', 409);
        }

        $codigo = trim((string) ($body['codigo'] ?? ''));
        if ($codigo === '') {
            throw new HttpException('Código vacío', 422);
        }
        $cantidad = isset($body['cantidad']) ? (float) $body['cantidad'] : 1.0;
        if ($cantidad <= 0) {
            throw new HttpException('La cantidad debe ser mayor a 0', 422);
        }

        $producto = $this->productoRepository->findByCodigoExacto($empresaId, $codigo);
        if ($producto === null) {
            throw new HttpException('Código no reconocido: ' . $codigo, 404);
        }
        if ((int) $producto['controla_stock'] !== 1) {
            throw new HttpException($producto['nombre'] . ' no controla stock; no participa en inventario', 422);
        }

        $productoId = (int) $producto['id'];

        // Un producto ya consolidado en esta sesión no se vuelve a contar: aplicar un
        // nuevo delta absoluto sobre el stock ya ajustado borraría ventas posteriores.
        // Para re-inventariarlo, se cierra la sesión y se abre una nueva.
        if ($this->repository->estaBloqueado($id, $productoId)) {
            throw new HttpException(
                $producto['nombre'] . ' ya fue consolidado en este barrido. Cierra la sesión para volver a inventariarlo.',
                409
            );
        }

        $sucursalId = (int) $sesion['sucursal_id'];
        $stockActual = $this->repository->stockActualSucursal($empresaId, $sucursalId, $productoId);

        // Snapshot del sistema al INICIAR el conteo del producto (primera lectura del
        // lote pendiente). La consolidación usa delta = contado - snapshot para que una
        // venta durante el barrido no se pierda. Las lecturas siguientes van sin snapshot.
        $esPrimeraLectura = $this->repository->acumuladoNoConsolidado($id, $productoId) <= 0.0;
        $snapshot = $esPrimeraLectura ? $stockActual : null;

        $this->repository->insertScan($id, $empresaId, $productoId, $cantidad, $snapshot, $userId);
        $this->repository->refreshBarridoHeader($id, $empresaId);

        return [
            'producto_id'   => $productoId,
            'nombre'        => $producto['nombre'],
            'codigo'        => $producto['codigo'] ?? $producto['sku'] ?? $codigo,
            'acumulado'     => $this->repository->acumuladoNoConsolidado($id, $productoId),
            'stock_sistema' => $stockActual,
            'bloqueado'     => false,
        ];
    }

    /**
     * Consolida las lecturas pendientes: por cada producto aplica un AJUSTE por
     * diferencia (contado − stock actual) en una sola transacción. El delta
     * compone con ventas concurrentes; consolidar de nuevo sin lecturas nuevas
     * no cambia nada (idempotente). Devuelve las líneas para el ticket.
     */
    public function consolidarBarrido(int $userId, int $id, array $params): array
    {
        $empresaId = $this->requireEmpresaId($params);
        $this->tenant($userId, $empresaId);

        $sesion = $this->findOrFail($empresaId, $id);
        if ($sesion['tipo'] !== 'BARRIDO' || $sesion['estado'] !== 'ABIERTA') {
            throw new HttpException('La sesión de barrido no está abierta', 409);
        }
        $sucursalId = (int) $sesion['sucursal_id'];

        $pendientes = $this->repository->scansPendientesAgrupados($id, $empresaId);
        $lineas = [];
        $consolidados = 0;

        $this->pdo->beginTransaction();
        try {
            foreach ($pendientes as $row) {
                $productoId = (int) $row['producto_id'];
                $contado    = round((float) $row['contado'], 3);
                // Snapshot del sistema al momento de contar (relativo, seguro con ventas
                // concurrentes). Fallback al stock actual si faltara el snapshot.
                $snapshot   = $row['snapshot'] !== null
                    ? round((float) $row['snapshot'], 3)
                    : $this->repository->stockActualSucursal($empresaId, $sucursalId, $productoId);
                $stockAntes = $this->repository->stockActualSucursal($empresaId, $sucursalId, $productoId);
                $delta      = round($contado - $snapshot, 3);

                $movimientoId = null;
                if (abs($delta) >= 0.001) {
                    $resultado = $this->stockService->ajustarStock([
                        'empresa_id'      => $empresaId,
                        'sucursal_id'     => $sucursalId,
                        'producto_id'     => $productoId,
                        'tipo'            => 'AJUSTE',
                        'cantidad'        => $delta,
                        'referencia_tipo' => 'INVENTARIO_BARRIDO',
                        'referencia_id'   => $id,
                        'usuario_id'      => $userId,
                        'observacion'     => 'Inventario barrido: ' . $sesion['nombre'],
                    ], $this->pdo);
                    $movimientoId = (int) $resultado['movimiento_id'];
                }

                $this->repository->marcarScansConsolidados($id, $productoId, $movimientoId);

                $lineas[] = [
                    'producto_id' => $productoId,
                    'nombre'      => $this->nombreProducto($empresaId, $productoId),
                    'antes'       => $snapshot,
                    'contado'     => $contado,
                    'ajuste'      => $delta,
                    'despues'     => round($stockAntes + $delta, 3),
                ];
                $consolidados++;
            }

            $this->repository->refreshBarridoHeader($id, $empresaId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'inventario_id' => $id,
            'consolidados'  => $consolidados,
            'lineas'        => $lineas,
        ];
    }

    /**
     * Productos que controlan stock y no fueron inventariados en la sesión.
     * Base para revisarlos y, si el barrido fue completo, llevarlos a 0.
     */
    public function noInventariadosBarrido(int $userId, int $id, array $params): array
    {
        $empresaId = $this->requireEmpresaId($params);
        $this->tenant($userId, $empresaId);

        $sesion = $this->findOrFail($empresaId, $id);
        if ($sesion['tipo'] !== 'BARRIDO') {
            throw new HttpException('La sesión no es de tipo barrido', 422);
        }
        $sucursalId = (int) $sesion['sucursal_id'];
        $rows = $this->repository->noInventariados($id, $empresaId, $sucursalId);

        return [
            'inventario_id' => $id,
            'total'         => count($rows),
            'items'         => array_map(static fn (array $r): array => [
                'producto_id'     => (int) $r['producto_id'],
                'nombre_producto' => $r['nombre_producto'],
                'codigo_producto' => $r['codigo_producto'],
                'stock_sistema'   => (float) $r['stock_sistema'],
            ], $rows),
        ];
    }

    /**
     * Lleva a 0 los productos indicados (no inventariados): aplica un AJUSTE por
     * el negativo de su stock actual y lo registra en la sesión. Solo tiene sentido
     * si el barrido cubrió todo el local (guarda en el frontend). Ignora productos
     * que sí tuvieron lecturas (para no pisar lo inventariado).
     */
    public function llevarACeroBarrido(int $userId, int $id, array $body): array
    {
        $empresaId = $this->requireEmpresaId($body);
        $this->tenant($userId, $empresaId);

        $sesion = $this->findOrFail($empresaId, $id);
        if ($sesion['tipo'] !== 'BARRIDO' || $sesion['estado'] !== 'ABIERTA') {
            throw new HttpException('La sesión de barrido no está abierta', 409);
        }
        $sucursalId = (int) $sesion['sucursal_id'];

        $ids = $body['producto_ids'] ?? [];
        if (!is_array($ids) || $ids === []) {
            throw new HttpException('Se requiere al menos un producto', 422);
        }

        $lineas = [];
        $ajustados = 0;
        $omitidos = 0;

        $this->pdo->beginTransaction();
        try {
            foreach ($ids as $rawId) {
                $productoId = (int) $rawId;
                if ($productoId <= 0) {
                    continue;
                }
                // No pisar un producto que sí fue inventariado en esta sesión.
                if ($this->repository->tieneScans($id, $productoId)) {
                    $omitidos++;
                    continue;
                }

                $antes = $this->repository->stockActualSucursal($empresaId, $sucursalId, $productoId);
                $movimientoId = null;
                if (abs($antes) >= 0.001) {
                    $resultado = $this->stockService->ajustarStock([
                        'empresa_id'      => $empresaId,
                        'sucursal_id'     => $sucursalId,
                        'producto_id'     => $productoId,
                        'tipo'            => 'AJUSTE',
                        'cantidad'        => -1 * $antes,
                        'referencia_tipo' => 'INVENTARIO_BARRIDO',
                        'referencia_id'   => $id,
                        'usuario_id'      => $userId,
                        'observacion'     => 'Barrido: no inventariado, llevado a 0 (' . $sesion['nombre'] . ')',
                    ], $this->pdo);
                    $movimientoId = (int) $resultado['movimiento_id'];
                }

                $this->repository->registrarCeroInventario($id, $empresaId, $productoId, $movimientoId, $userId);
                $lineas[] = [
                    'producto_id' => $productoId,
                    'nombre'      => $this->nombreProducto($empresaId, $productoId),
                    'antes'       => $antes,
                    'contado'     => 0.0,
                    'ajuste'      => round(-1 * $antes, 3),
                    'despues'     => 0.0,
                ];
                $ajustados++;
            }

            $this->repository->refreshBarridoHeader($id, $empresaId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'inventario_id' => $id,
            'ajustados'     => $ajustados,
            'omitidos'      => $omitidos,
            'lineas'        => $lineas,
        ];
    }

    public function cerrarBarrido(int $userId, int $id, array $params): array
    {
        $empresaId = $this->requireEmpresaId($params);
        $this->tenant($userId, $empresaId);

        $sesion = $this->findOrFail($empresaId, $id);
        if ($sesion['tipo'] !== 'BARRIDO') {
            throw new HttpException('La sesión no es de tipo barrido', 422);
        }
        $this->repository->cerrarBarrido($empresaId, $id);

        return ['inventario_id' => $id, 'estado' => 'CERRADA'];
    }

    private function barridoPayload(int $empresaId, array $sesion): array
    {
        $id         = (int) $sesion['id'];
        $sucursalId = (int) $sesion['sucursal_id'];
        $resumen    = $this->repository->resumenBarrido($id, $empresaId);

        return [
            'id'          => $id,
            'nombre'      => $sesion['nombre'],
            'estado'      => $sesion['estado'],
            'sucursal_id' => $sucursalId,
            'created_at'  => $sesion['created_at'],
            'items'       => array_map(function (array $r) use ($empresaId, $sucursalId): array {
                $productoId = (int) $r['producto_id'];
                return [
                    'producto_id'     => $productoId,
                    'nombre_producto' => $r['nombre_producto'],
                    'codigo_producto' => $r['codigo_producto'],
                    'pendiente'       => (float) $r['pendiente'],
                    'consolidado'     => (float) $r['consolidado'],
                    'bloqueado'       => (int) $r['bloqueado'] === 1,
                    'stock_sistema'   => $this->repository->stockActualSucursal($empresaId, $sucursalId, $productoId),
                ];
            }, $resumen),
        ];
    }

    private function nombreProducto(int $empresaId, int $productoId): string
    {
        $prod = $this->productoRepository->find($productoId, $empresaId);
        return $prod['nombre'] ?? ('Producto #' . $productoId);
    }

    // ─── Privados ───

    private function findOrFail(int $empresaId, int $id): array
    {
        $inv = $this->repository->find($empresaId, $id);
        if ($inv === null) {
            throw new HttpException('Inventario no encontrado', 404);
        }
        return $inv;
    }

    private function requireEmpresaId(array $params): int
    {
        $id = (int) ($params['empresa_id'] ?? 0);
        if ($id <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }
        return $id;
    }

    private function requirePositiveInt(array $params, string $field, string $message): int
    {
        $v = (int) ($params[$field] ?? 0);
        if ($v <= 0) {
            throw new HttpException($message, 422);
        }
        return $v;
    }

    private function tenant(int $userId, int $empresaId): void
    {
        (new TenantMiddleware())->handle($userId, $empresaId);
    }
}
