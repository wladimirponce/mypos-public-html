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

## Troubleshooting

- **Error "Impresora no encontrada"**: Verificar nombre de impresora en Panel de Control
- **Error de conexión**: Asegurar que el servicio esté corriendo
