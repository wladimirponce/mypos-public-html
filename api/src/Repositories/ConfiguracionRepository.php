<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class ConfiguracionRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function connection(): PDO
    {
        return $this->connection;
    }

    public function empresaExists(int $empresaId): bool
    {
        $statement = $this->connection->prepare('SELECT 1 FROM empresas WHERE id = :id AND activo = 1 LIMIT 1');
        $statement->execute(['id' => $empresaId]);

        return (bool) $statement->fetchColumn();
    }

    public function sucursalExists(int $empresaId, int $sucursalId): bool
    {
        $statement = $this->connection->prepare('SELECT 1 FROM sucursales WHERE id = :id AND empresa_id = :empresa_id AND activo = 1 LIMIT 1');
        $statement->execute(['id' => $sucursalId, 'empresa_id' => $empresaId]);

        return (bool) $statement->fetchColumn();
    }

    public function metodoPagoActivo(int $methodId): bool
    {
        $statement = $this->connection->prepare('SELECT 1 FROM metodos_pago WHERE id = :id AND activo = 1 LIMIT 1');
        $statement->execute(['id' => $methodId]);

        return (bool) $statement->fetchColumn();
    }

    public function empresaConfig(int $empresaId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT empresa_id, rut_empresa, razon_social, nombre_fantasia, giro,
                    email_contacto, telefono_contacto, direccion, comuna, ciudad,
                    region, logo_url, sitio_web, metadata_json
             FROM empresa_configuracion
             WHERE empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function operacionConfig(int $empresaId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT empresa_id, permitir_stock_negativo, exigir_caja_abierta_para_vender,
                    permitir_venta_sin_cliente, permitir_credito_clientes,
                    exigir_cliente_en_factura, tipo_documento_default, metodo_pago_default_id,
                    alerta_stock_bajo_default, alerta_folios_bajos_default,
                    dias_alerta_vencimiento_caf, ia_documentos_habilitada,
                    documentos_tributarios_habilitados, modo_offline_habilitado
             FROM empresa_configuracion_operativa
             WHERE empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function sucursalConfig(int $empresaId, int $sucursalId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT empresa_id, sucursal_id, direccion, comuna, ciudad, telefono, email,
                    activa, exigir_caja_abierta_para_vender, permitir_stock_negativo,
                    tipo_documento_default, metadata_json
             FROM sucursal_configuracion
             WHERE empresa_id = :empresa_id AND sucursal_id = :sucursal_id
             LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId, 'sucursal_id' => $sucursalId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function upsertEmpresaConfig(array $data): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO empresa_configuracion (
                empresa_id, rut_empresa, razon_social, nombre_fantasia, giro, email_contacto,
                telefono_contacto, direccion, comuna, ciudad, region, logo_url, sitio_web, metadata_json
             ) VALUES (
                :empresa_id, :rut_empresa, :razon_social, :nombre_fantasia, :giro, :email_contacto,
                :telefono_contacto, :direccion, :comuna, :ciudad, :region, :logo_url, :sitio_web, :metadata_json
             )
             ON DUPLICATE KEY UPDATE
                rut_empresa = VALUES(rut_empresa),
                razon_social = VALUES(razon_social),
                nombre_fantasia = VALUES(nombre_fantasia),
                giro = VALUES(giro),
                email_contacto = VALUES(email_contacto),
                telefono_contacto = VALUES(telefono_contacto),
                direccion = VALUES(direccion),
                comuna = VALUES(comuna),
                ciudad = VALUES(ciudad),
                region = VALUES(region),
                logo_url = VALUES(logo_url),
                sitio_web = VALUES(sitio_web),
                metadata_json = VALUES(metadata_json),
                updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute($data);
    }

    public function upsertOperacionConfig(array $data): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO empresa_configuracion_operativa (
                empresa_id, permitir_stock_negativo, exigir_caja_abierta_para_vender,
                permitir_venta_sin_cliente, permitir_credito_clientes, exigir_cliente_en_factura,
                tipo_documento_default, metodo_pago_default_id, alerta_stock_bajo_default,
                alerta_folios_bajos_default, dias_alerta_vencimiento_caf,
                ia_documentos_habilitada, documentos_tributarios_habilitados, modo_offline_habilitado
             ) VALUES (
                :empresa_id, :permitir_stock_negativo, :exigir_caja_abierta_para_vender,
                :permitir_venta_sin_cliente, :permitir_credito_clientes, :exigir_cliente_en_factura,
                :tipo_documento_default, :metodo_pago_default_id, :alerta_stock_bajo_default,
                :alerta_folios_bajos_default, :dias_alerta_vencimiento_caf,
                :ia_documentos_habilitada, :documentos_tributarios_habilitados, :modo_offline_habilitado
             )
             ON DUPLICATE KEY UPDATE
                permitir_stock_negativo = VALUES(permitir_stock_negativo),
                exigir_caja_abierta_para_vender = VALUES(exigir_caja_abierta_para_vender),
                permitir_venta_sin_cliente = VALUES(permitir_venta_sin_cliente),
                permitir_credito_clientes = VALUES(permitir_credito_clientes),
                exigir_cliente_en_factura = VALUES(exigir_cliente_en_factura),
                tipo_documento_default = VALUES(tipo_documento_default),
                metodo_pago_default_id = VALUES(metodo_pago_default_id),
                alerta_stock_bajo_default = VALUES(alerta_stock_bajo_default),
                alerta_folios_bajos_default = VALUES(alerta_folios_bajos_default),
                dias_alerta_vencimiento_caf = VALUES(dias_alerta_vencimiento_caf),
                ia_documentos_habilitada = VALUES(ia_documentos_habilitada),
                documentos_tributarios_habilitados = VALUES(documentos_tributarios_habilitados),
                modo_offline_habilitado = VALUES(modo_offline_habilitado),
                updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute($data);
    }

    public function upsertSucursalConfig(array $data): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO sucursal_configuracion (
                empresa_id, sucursal_id, direccion, comuna, ciudad, telefono, email, activa,
                exigir_caja_abierta_para_vender, permitir_stock_negativo,
                tipo_documento_default, metadata_json
             ) VALUES (
                :empresa_id, :sucursal_id, :direccion, :comuna, :ciudad, :telefono, :email, :activa,
                :exigir_caja_abierta_para_vender, :permitir_stock_negativo,
                :tipo_documento_default, :metadata_json
             )
             ON DUPLICATE KEY UPDATE
                direccion = VALUES(direccion),
                comuna = VALUES(comuna),
                ciudad = VALUES(ciudad),
                telefono = VALUES(telefono),
                email = VALUES(email),
                activa = VALUES(activa),
                exigir_caja_abierta_para_vender = VALUES(exigir_caja_abierta_para_vender),
                permitir_stock_negativo = VALUES(permitir_stock_negativo),
                tipo_documento_default = VALUES(tipo_documento_default),
                metadata_json = VALUES(metadata_json),
                updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute($data);
    }
}

