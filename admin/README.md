# admin — Centro de Administración DTE / SII (MyPOS)

Dashboard web de administración y API REST para la emisión de Documentos Tributarios Electrónicos (DTE) ante el SII chileno. Es el **backend central** de todo el ecosistema MyPOS.

- **URL producción:** `https://www.mypos.cl/admin/`
- **Stack:** PHP 8+, MySQL/MariaDB, arquitectura en capas (Core → Services → Repositories)
- **Entrada principal:** `dashboard.php` (admin web) + `api.php` (API REST)

---

## Rol en el ecosistema

`admin` es la fuente de verdad para folios y documentos tributarios. Los demás sistemas consumen su API:

| Consumidor          | Estado         |
|---------------------|----------------|
| `android` (APK)     | ✅ Conectado   |
| `MyPOS` (SaaS web)  | 🔄 Modo simulado — integración DTE encapsulada, pendiente conectar real |
| `POS antiguo`       | ❌ Pendiente — hoy usa módulo SII propio |

---

## Estructura de carpetas

```
admin/
├── dashboard.php           ← Layout maestro del admin web (enruta a módulos)
├── api.php                 ← Endpoint único de la API REST pública
├── autoload.php            ← PSR-4 manual: registra src/ y core clases
├── index.php               ← Redirige a dashboard
│
├── modules/                ← Módulos del dashboard (incluidos por dashboard.php)
│   ├── home.php            ← Resumen general
│   ├── empresas.php        ← Alta y gestión de empresas/clientes
│   ├── sucursales.php      ← Sucursales por empresa
│   ├── cafs.php            ← Pool de CAFs — subida y distribución de folios
│   ├── dispositivos.php    ← Enrolamiento de máquinas POS (token de activación)
│   ├── emision.php         ← Cola de emisión y reenvíos al SII
│   ├── historial.php       ← DTEs emitidos con estado SII
│   ├── libros.php          ← Libro de boletas y guías
│   ├── agente_consultas.php← Consultas IA no resueltas
│   ├── saas.php            ← Suscripción y feature toggles por empresa (SaaS)
│   ├── usuarios.php        ← Usuarios del dashboard
│   ├── consultas.php       ← Consultas manuales al SII
│   ├── certificacion.php   ← Gestión de certificados digitales
│   ├── soporte.php         ← Tickets de soporte
│   ├── config.php          ← Configuración global del sistema
│   └── pos_urgencia.php    ← Emisión POS de emergencia desde el admin
│
├── src/                    ← Código fuente PHP (PSR-4, namespace App\)
│   ├── Core/
│   │   ├── Database.php        ← PDO singleton con pool de conexiones
│   │   ├── Context.php         ← Contexto de la request (empresa activa, usuario)
│   │   └── EnvLoader.php       ← Carga .env
│   ├── Services/
│   │   ├── CafCentralManager.php     ← Distribución de folios desde el pool central
│   │   ├── FoliosAlertaManager.php   ← Alertas de stock bajo/crítico por sucursal
│   │   ├── DteLocalQueueManager.php  ← Cola de DTEs pendientes de enviar al SII
│   │   └── CertificationManager.php  ← Gestión de certificados .pfx
│   └── Repositories/
│       ├── BaseRepository.php
│       ├── EmpresaRepository.php
│       ├── SaaSRepository.php        ← Suscripciones y feature toggles
│       ├── SoporteRepository.php
│       └── UsuarioRepository.php
│
├── docs/                   ← Documentación de la API REST pública
│   ├── README.md           ← Índice y resumen de la API
│   ├── 01-autenticacion.md
│   ├── 02-emision.md
│   ├── 03-consulta-estado.md
│   ├── 04-informes.md
│   ├── 05-modelos-datos.md
│   ├── 06-codigos-estado.md
│   └── 07-ejemplos.md
│
├── migrations/             ← Scripts SQL de migración numerados
├── config/                 ← Configuración de conexiones y ambiente
├── caf/                    ← Archivos CAF XML del SII (pool central)
├── cert/                   ← Certificados digitales .pfx
└── tools/                  ← Utilidades de administración y deploy
```

---

## Tablas clave de base de datos

| Tabla              | Descripción                                           |
|--------------------|-------------------------------------------------------|
| `sii_empresa`      | Empresas/clientes registrados en el sistema           |
| `sii_sucursal`     | Sucursales por empresa                                |
| `cafs`             | Pool de CAFs con rango de folios y estado             |
| `caf_consumos`     | Auditoría de cada folio consumido                     |
| `sii_dte`          | DTEs emitidos y su estado con el SII                  |
| `sii_api_key`      | Credenciales de acceso por empresa                    |
| `sii_dispositivo`  | Dispositivos POS enrolados (token de activación)      |
| `saas_suscripcion` | Plan y estado de pago de cada empresa SaaS            |
| `saas_features`    | Feature toggles por empresa                           |

---

## API REST

Ver `docs/README.md` para la referencia completa. Resumen del flujo:

```
POST api.php?action=generate  → XML DTE firmado + folio asignado
POST api.php?action=send      → envía al SII → TrackID
GET  api.php?action=validate  → estado en el SII (por TrackID o folio)
GET  api.php?action=history   → documentos emitidos por empresa
```

Autenticación: header `X-API-KEY` con la clave de la empresa.

**Tipos de documento soportados:**

| Código | Tipo                        | Canal SII |
|--------|-----------------------------|-----------|
| 39     | Boleta electrónica afecta   | REST      |
| 41     | Boleta electrónica exenta   | REST      |
| 33     | Factura electrónica afecta  | SOAP      |
| 34     | Factura electrónica exenta  | SOAP      |
| 52     | Guía de despacho            | SOAP      |
| 56     | Nota de débito              | SOAP      |
| 61     | Nota de crédito             | SOAP      |

---

## Módulo SaaS

Las empresas con historial operativo no se eliminan físicamente desde el dashboard. La acción de Empresas las desactiva o reactiva conservando CAF, folios, documentos, usuarios y auditoría.

El listado oculta las empresas inactivas por defecto y permite mostrarlas temporalmente con el botón **Ver inactivas**.

El tablero **Clientes MyPOS** aplica el mismo filtro: solo muestra clientes activos por defecto y permite consultar los inactivos de forma opcional.

En `Clientes MyPOS`, la columna **Monto mensual** edita el valor recurrente real guardado en `empresas_suscripcion.precio_especial_clp`. Los próximos cobros Flow usan ese monto en lugar del precio de catálogo del plan.

El módulo `modules/saas.php` permite administrar la suscripción y las funcionalidades habilitadas por empresa. Usa `SaaSRepository` para gestionar:

- **Plan:** básico, estándar, premium
- **Estado de pago:** activo, moroso, suspendido
- **Feature toggles:** habilitar/deshabilitar funciones por empresa (ej. `dte_real`, `guias`, `multisucursal`)
- **Cuota mensual** y **día de corte**

---

## Módulo de Enrolamiento de Dispositivos

`modules/dispositivos.php` implementa el flujo de enrolamiento para terminales POS:

1. El admin genera un **token de activación** (6 letras, válido 24 h).
2. El operador ingresa el token en el APK (`EnrollmentActivity`).
3. El APK llama a `api.php?action=activar_dispositivo` con el token.
4. `admin` valida el token, registra el dispositivo en `sii_dispositivo` y devuelve la API key definitiva.
5. El admin puede ver todos los dispositivos enrolados y revocarlos desde el dashboard.

---

## Crons en producción

| Script             | Frecuencia | Función                                      |
|--------------------|------------|----------------------------------------------|
| `dte_cola_cron.php`| cada 5 min | Procesa la cola de DTEs pendientes de envío  |
| `poller_cron.php`  | cada 10 min| Consulta estado de envíos en el SII          |
| `rcof_cron.php`    | diario     | Genera y envía Registro de Consumo de Folios |
| `retry_cron.php`   | cada 15 min| Reintenta DTEs rechazados o con error        |
| `backup_cron.php`  | diario     | Backup de la base de datos                   |

---

## Seguridad del panel

El panel centraliza sesión, CSRF, RBAC y política de contraseñas en `App\Services\AdminSecurity`. Las sesiones expiran, revalidan cuenta/rol contra BD y fallan cerradas si esa validación no es posible. Todo cambio de estado usa POST con token CSRF. Ver `docs/08-seguridad-panel.md`.

## Reglas para agentes IA

- `admin` es la **fuente de verdad** para folios. No emitas folios desde otros sistemas sin pasar por aquí.
- El **pool central** son los CAFs con `sucursal_id = NULL`. `CafCentralManager` los distribuye.
- Los cambios en `modules/` son de UI (dashboard). La lógica va en `src/Services/` y `src/Repositories/`.
- No mezcles credenciales de empresas distintas. Cada request se filtra por `empresa_id` desde el contexto.
- Ver `docs/README.md` antes de modificar o extender la API pública.
