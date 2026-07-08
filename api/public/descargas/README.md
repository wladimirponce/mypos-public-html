# MyPOS Print Server ESC/POS

Servicio local multiempresa de impresión directa para impresoras ESC/POS.

## Requisitos
- Python 3.x
- Flask, flask-cors, pywin32
- Pillow, python-barcode, pdf417 (necesario para timbre PDF417 DTE escaneable)

## Uso

1. **Instalar en PC de caja:** descarga `instalar_mypos_print_server.bat` desde mypos.cl/Configuración y ejecútalo como Administrador. Crea el acceso directo **"MyPOS Print Server"** en el Escritorio.

2. **Iniciar el servicio:** doble clic en el acceso directo del Escritorio.

3. **Desde terminal (desarrollo):**
   ```
   python mypos_print_server.py
   ```

## Endpoints

| Método | URL | Descripción |
|--------|-----|-------------|
| GET | http://localhost:5555/status | Estado del servicio |
| GET | http://localhost:5555/test | Imprimir ticket de prueba |
| POST | http://localhost:5555/print | Imprimir ticket (enviar JSON) |
| GET | http://localhost:5555/balanza/estado | Diagnostico TCP de balanza DIGI SM-300 |
| GET | http://localhost:5555/balanza/rcth?n=1234 | Detalle de vale SM-300 por numero RCTH |

## Formato JSON para /print

```json
{
  "tipo": "ticket",
  "empresa": "Nombre del Local",
  "razon_social": "Empresa SpA",
  "rut_emisor": "76.000.000-0",
  "giro": "Comercio",
  "direccion": "Dirección de la sucursal",
  "fecha": "2026-06-11 16:00",
  "venta_id": 123,
  "productos": [
    {"nombre": "Producto 1", "cantidad": 2, "subtotal": 5000}
  ],
  "total": 5000,
  "metodo_pago": "EFECTIVO",
  "vuelto": 500,
  "monto_recibido": 5500
}
```

El ticket comercial imprime la leyenda `NO VALIDO COMO DOCUMENTO TRIBUTARIO`.
La identidad de empresa y sucursal debe venir en cada solicitud; el servidor no mantiene una empresa fija.

La impresora debe aparecer instalada en Windows con un nombre termico reconocido, por ejemplo
`EPSON TM-T20II`, `POS`, `THERMAL`, `BIXOLON`, `STAR` o `TICKET`. Tambien puede definirse
explicitamente antes de iniciar el servicio:

```bat
set MYPOS_PRINTER_NAME=Nombre exacto de la impresora
start_mypos_print_server.bat
```

## Balanza DIGI SM-300

El POS detecta un vale de balanza al escanear un EAN-13 con prefijo `2`. El numero
RCTH se toma de los digitos 3 a 6 del codigo y el navegador consulta al servidor
local:

```http
GET http://localhost:5555/balanza/rcth?n=1234
```

Por defecto el bridge intenta conectar con la pesa en `192.168.0.130` y prueba los
puertos `10000,8000,4000,9100`. Se puede ajustar antes de iniciar el servicio:

```bat
set MYPOS_BALANZA_IP=192.168.0.130
set MYPOS_BALANZA_PORTS=10000,8000,4000,9100
```

Si la balanza responde con detalle interpretable, el POS agrega una linea por corte.
Si no responde o el formato no calza, el POS mantiene el respaldo actual: una linea
con el total embebido en el EAN-13.

### Imprimir boleta electronica PDF

```json
{
  "tipo": "boleta_electronica_pdf",
  "pdf_url": "https://dominio.cl/boletas/pdf.php?empresa_id=1&tipo=39&folio=123",
  "printer": "EPSON TM-T20II",
  "copias": 1
}
```

Tambien acepta `pdf_base64` en lugar de `pdf_url`.

## Timbre PDF417 (boleta electronica) — tamano de modulo

El timbre se rasteriza **localmente** con `pdf417gen` (no se usa el PNG del facturador,
que viene con `aspectratio=2` ~345 modulos y queda ilegible al ancho termico). El numero
de columnas se elige por el ancho del papel para que cada modulo mida **>= 0.25 mm**, que
es el minimo que la app del SII puede leer desde papel:

| Formato | `ancho` | Columnas | mm/modulo |
|---------|---------|----------|-----------|
| 80 mm | 80 | 8 | ~0.35 (holgado) |
| 58 mm | 56 | 8 | ~0.25 (maximo fisico para un TED de ~760 B) |

Notas:
- **Minimo 8 columnas.** Con menos, el TED (~760 B, ECC 5) supera 90 filas y `pdf417gen` falla.
- La escala del render se calcula para llenar el ancho objetivo sin distorsion
  (`scale = round(raster_width / modulos)`).
- La N y demas no-ASCII van en byte mode ISO-8859-1 (`0xD1`); sobreviven el PDF417 intactas.
- **Escanear desde papel**, no desde pantalla (la pantalla corrompe el barcode por moire).
- Requiere el paquete `pdf417` (`pdf417gen`) instalado; sin el, cae a un timbre ESC/POS
  nativo que la mayoria de lectores no resuelve.

Para hoja **carta** el barcode lo genera el facturador (`MuestraPdfGenerator`), no el print
server; ver `admin/doc/spec_boletas_oneshot/02_firma_y_ted.md` seccion 5.

## Troubleshooting

- **Error "Impresora no encontrada"**: Verificar nombre de impresora en Panel de Control
- **Error de conexión**: Asegurar que el servicio esté corriendo
