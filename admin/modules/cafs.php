<?php
/**
 * Folios CAF de la empresa y ambiente activos.
 */
use App\Core\Database;

$pdo = Database::getInstance();
$empresaId = $globalContext->getEmpresaId();
$ambienteCaf = strtolower($globalContext->getAmbiente());
$stmt = $pdo->prepare(
    "SELECT c.tipo_dte, c.folio_desde, c.folio_hasta, c.fecha_autorizacion,
            c.activo, COALESCE(v.disponibles, 0) AS disponibles
       FROM sii_caf c
  LEFT JOIN v_sii_folios_disponibles v ON v.caf_id = c.id
      WHERE c.empresa_id = ? AND c.ambiente_sii = ?
      ORDER BY c.tipo_dte, c.folio_desde"
);
$stmt->execute([$empresaId, $ambienteCaf]);
$cafs = $stmt->fetchAll(PDO::FETCH_ASSOC);
$tipos = [
    33 => 'Factura', 34 => 'Factura Exenta', 39 => 'Boleta',
    41 => 'Boleta Exenta', 52 => 'Guia Despacho', 56 => 'Nota Debito', 61 => 'Nota Credito',
];
?>
<div class="d-card">
    <div class="d-card-header">
        <i class="bi bi-ticket-perforated"></i>
        Folios CAF de <?= htmlspecialchars($razonSocial) ?> / <?= htmlspecialchars($globalContext->getAmbiente()) ?>
    </div>
    <div class="d-card-body" style="padding:0">
        <?php if (!$cafs): ?>
            <div style="padding:32px" class="text-muted">
                No hay CAF registrados para esta empresa y ambiente.
                Carguelos desde Configuracion DTE.
            </div>
        <?php else: ?>
            <table class="d-table">
                <thead>
                    <tr><th>Tipo DTE</th><th>Rango</th><th>Disponibles</th><th>Autorizacion</th><th>Estado</th></tr>
                </thead>
                <tbody>
                <?php foreach ($cafs as $caf): ?>
                    <tr>
                        <td><?= htmlspecialchars($tipos[(int)$caf['tipo_dte']] ?? ('DTE ' . $caf['tipo_dte'])) ?></td>
                        <td><?= number_format((int)$caf['folio_desde'], 0, ',', '.') ?> - <?= number_format((int)$caf['folio_hasta'], 0, ',', '.') ?></td>
                        <td><?= number_format((int)$caf['disponibles'], 0, ',', '.') ?></td>
                        <td><?= htmlspecialchars((string)$caf['fecha_autorizacion']) ?></td>
                        <td><?= (int)$caf['activo'] === 1 ? '<span class="d-badge prod">Activo</span>' : '<span class="d-badge danger">Inactivo</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
