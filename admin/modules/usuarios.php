<?php
/**
 * Módulo: Usuarios & Roles
 * Permite gestionar los usuarios y la matriz de permisos de la empresa activa
 */
if (!defined('DTE_API_BOOTSTRAP_ONLY')) exit;

use App\Repositories\UsuarioRepository;

$empresaId = $_SESSION['active_empresa_id'] ?? 0;
if (!$empresaId) {
    echo '<div class="d-alert warning">Seleccione una empresa primero.</div>';
    return;
}

$repo = new UsuarioRepository();
$db = \App\Core\Database::getInstance();
$msg = '';

// Procesar formularios
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    if ($_POST['accion'] === 'crear') {
        try {
            $repo->crearUsuario([
                'empresa_id' => $empresaId,
                'sucursal_id' => !empty($_POST['sucursal_id']) ? (int)$_POST['sucursal_id'] : null,
                'nombre' => trim($_POST['nombre']),
                'email' => trim($_POST['email']),
                'password' => trim($_POST['password']),
                'rol' => trim($_POST['rol'])
            ]);
            $msg = '<div class="d-alert success">Usuario creado exitosamente.</div>';
        } catch (Exception $e) {
            $msg = '<div class="d-alert danger">Error: ' . $e->getMessage() . '</div>';
        }
    } elseif ($_POST['accion'] === 'desactivar') {
        $repo->desactivarUsuario((int)$_POST['id']);
        $msg = '<div class="d-alert success">Usuario desactivado.</div>';
    } elseif ($_POST['accion'] === 'guardar_roles') {
        try {
            $roles = ['cajero', 'supervisor', 'admin'];
            foreach ($roles as $r) {
                $anular = isset($_POST['roles'][$r]['puede_anular']) ? 1 : 0;
                $desc = isset($_POST['roles'][$r]['puede_descuento_mayor']) ? 1 : 0;
                $rep = isset($_POST['roles'][$r]['puede_ver_reportes']) ? 1 : 0;
                $conf = isset($_POST['roles'][$r]['puede_configurar_pos']) ? 1 : 0;

                $sql = "INSERT INTO saas_rol_matriz (empresa_id, nombre_rol, puede_anular, puede_descuento_mayor, puede_ver_reportes, puede_configurar_pos) 
                        VALUES (?, ?, ?, ?, ?, ?) 
                        ON DUPLICATE KEY UPDATE 
                        puede_anular=VALUES(puede_anular), puede_descuento_mayor=VALUES(puede_descuento_mayor),
                        puede_ver_reportes=VALUES(puede_ver_reportes), puede_configurar_pos=VALUES(puede_configurar_pos)";
                $stmt = $db->prepare($sql);
                $stmt->execute([$empresaId, $r, $anular, $desc, $rep, $conf]);
            }
            $msg = '<div class="d-alert success">Matriz de roles actualizada.</div>';
        } catch (Exception $e) {
            $msg = '<div class="d-alert danger">Error guardando roles: ' . $e->getMessage() . '</div>';
        }
    }
}

$usuarios = $repo->getUsuariosPorEmpresa($empresaId);

// Obtener matriz de roles
$stmtRoles = $db->prepare("SELECT * FROM saas_rol_matriz WHERE empresa_id = ?");
$stmtRoles->execute([$empresaId]);
$rolesDb = $stmtRoles->fetchAll(PDO::FETCH_ASSOC);
$matriz = [];
foreach ($rolesDb as $r) {
    $matriz[$r['nombre_rol']] = $r;
}
?>

<?= $msg ?>

<!-- SECCIÓN 1: USUARIOS -->
<div class="row mb-4">
    <div class="col-md-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-people"></i> Usuarios de la Empresa</span>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Nombre</th>
                            <th>Email</th>
                            <th>Rol</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($usuarios)): ?>
                            <tr><td colspan="5" class="text-center text-muted">No hay usuarios registrados</td></tr>
                        <?php else: ?>
                            <?php foreach($usuarios as $u): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($u['nombre']) ?></strong></td>
                                <td><?= htmlspecialchars($u['email']) ?></td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($u['rol']) ?></span></td>
                                <td>
                                    <?php if($u['activo']): ?>
                                        <span class="badge bg-success">Activo</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($u['activo']): ?>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('¿Desactivar este usuario?');">
                                        <input type="hidden" name="accion" value="desactivar">
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-person-plus"></i> Nuevo Usuario</div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="accion" value="crear">
                    <div class="mb-3">
                        <label>Nombre Completo</label>
                        <input type="text" name="nombre" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>Email</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>Contraseña</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>Rol Inicial</label>
                        <select name="rol" class="form-select">
                            <option value="cajero">Cajero POS</option>
                            <option value="supervisor">Supervisor</option>
                            <option value="admin">Administrador</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Crear Usuario</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- SECCIÓN 2: MATRIZ DE ROLES -->
<div class="card">
    <div class="card-header"><i class="bi bi-shield-lock"></i> Matriz Avanzada de Roles y Permisos</div>
    <div class="card-body">
        <p class="text-muted small">Define qué funciones específicas puede ejecutar cada rol dentro del sistema POS de esta empresa.</p>
        <form method="post">
            <input type="hidden" name="accion" value="guardar_roles">
            <table class="table table-bordered text-center align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="text-start">Permiso / Función</th>
                        <th>Cajero</th>
                        <th>Supervisor</th>
                        <th>Administrador</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $permisos = [
                        'puede_anular' => 'Anular documentos (Ventas/Boletas)',
                        'puede_descuento_mayor' => 'Aplicar descuentos > 10%',
                        'puede_ver_reportes' => 'Ver reportes X y Z',
                        'puede_configurar_pos' => 'Configuración de Caja (Impresoras, etc)'
                    ];
                    foreach ($permisos as $campo => $label): 
                    ?>
                    <tr>
                        <td class="text-start"><?= $label ?></td>
                        <td>
                            <div class="form-check form-switch d-flex justify-content-center">
                                <input class="form-check-input" type="checkbox" name="roles[cajero][<?= $campo ?>]" <?= !empty($matriz['cajero'][$campo]) ? 'checked' : '' ?>>
                            </div>
                        </td>
                        <td>
                            <div class="form-check form-switch d-flex justify-content-center">
                                <input class="form-check-input" type="checkbox" name="roles[supervisor][<?= $campo ?>]" <?= !empty($matriz['supervisor'][$campo]) ? 'checked' : '' ?>>
                            </div>
                        </td>
                        <td>
                            <div class="form-check form-switch d-flex justify-content-center">
                                <input class="form-check-input" type="checkbox" name="roles[admin][<?= $campo ?>]" <?= (!isset($matriz['admin']) || !empty($matriz['admin'][$campo])) ? 'checked' : '' ?>>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="text-end">
                <button type="submit" class="btn btn-success"><i class="bi bi-save"></i> Guardar Matriz de Roles</button>
            </div>
        </form>
    </div>
</div>
