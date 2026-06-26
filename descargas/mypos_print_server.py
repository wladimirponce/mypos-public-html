from __future__ import annotations

import base64
import io
import json
import os
import socket
import tempfile
import time
import urllib.request
from datetime import datetime
from typing import Any

from flask import Flask, jsonify, request
from flask_cors import CORS

try:
    import win32print
except Exception:  # pragma: no cover - pywin32 only exists on Windows clients.
    win32print = None

try:
    import win32api
except Exception:  # pragma: no cover
    win32api = None

try:
    from PIL import Image
except Exception:  # pragma: no cover
    Image = None


VERSION = "1.1.1"
HOST = "127.0.0.1"
PORT = 5555
DEFAULT_WIDTH = 48
DEFAULT_RASTER_WIDTH = 576
PDF417_RASTER_WIDTH = 480
SUMATRA_PDF_PATH = os.environ.get("MYPOS_SUMATRA_PDF", r"C:\MyPOSPrint\SumatraPDF.exe")

ESC = b"\x1b"
GS = b"\x1d"
GS_K = GS + b"(k"

CMD_INIT = ESC + b"@"
CMD_LEFT = ESC + b"a\x00"
CMD_CENTER = ESC + b"a\x01"
CMD_RIGHT = ESC + b"a\x02"
CMD_BOLD_ON = ESC + b"E\x01"
CMD_BOLD_OFF = ESC + b"E\x00"
CMD_DOUBLE_ON = GS + b"!\x11"
CMD_DOUBLE_OFF = GS + b"!\x00"
CMD_FEED_CUT = ESC + b"d\x06" + GS + b"V\x00"
CMD_CODEPAGE_PC858 = ESC + b"t\x13"

app = Flask(__name__)
CORS(
    app,
    allow_private_network=True,
    resources={
        r"/*": {
            "origins": [
                "https://mypos.cl",
                "https://www.mypos.cl",
                "http://localhost:5173",
                "http://127.0.0.1:5173",
            ]
        }
    },
)


def money(value: Any) -> str:
    try:
        return "$" + f"{int(round(float(value or 0))):,}".replace(",", ".")
    except Exception:
        return "$0"


def number(value: Any, default: float = 0) -> float:
    try:
        return float(value)
    except Exception:
        return default


def text(value: Any, max_len: int | None = None) -> str:
    clean = str(value or "").replace("\r", " ").replace("\n", " ").strip()
    return clean[:max_len] if max_len else clean


def enc(value: str) -> bytes:
    return value.encode("cp850", errors="replace")


def line(left: str, right: str = "", width: int = DEFAULT_WIDTH) -> bytes:
    left = text(left)
    right = text(right)
    if not right:
        return enc(left[:width] + "\n")
    available = max(1, width - len(right))
    return enc(left[:available].ljust(available) + right[:width] + "\n")


def separator(char: str = "-") -> bytes:
    return enc(char * DEFAULT_WIDTH + "\n")


def wrap_label(value: str, width: int = 28) -> list[str]:
    value = text(value)
    if len(value) <= width:
        return [value]
    words = value.split()
    rows: list[str] = []
    current = ""
    for word in words:
        candidate = word if not current else current + " " + word
        if len(candidate) <= width:
            current = candidate
        else:
            if current:
                rows.append(current)
            current = word[:width]
    if current:
        rows.append(current)
    return rows or [value[:width]]


def list_printers() -> list[str]:
    if win32print is None:
        return []
    flags = win32print.PRINTER_ENUM_LOCAL | win32print.PRINTER_ENUM_CONNECTIONS
    return [printer[2] for printer in win32print.EnumPrinters(flags)]


def default_printer() -> str:
    explicit = text(os.environ.get("MYPOS_PRINTER_NAME"))
    if explicit:
        return explicit

    printers = list_printers()
    preferred = ("TM-T20", "TM-T88", "TM-M", "POS", "THERMAL", "BIXOLON", "STAR", "TICKET")
    for name in printers:
        upper = name.upper()
        if any(token in upper for token in preferred):
            return name

    return ""


def check_printer_access(printer_name: str | None = None) -> tuple[bool, str]:
    if win32print is None:
        return False, "pywin32 no esta instalado o no se esta ejecutando en Windows."

    printer = text(printer_name) or default_printer()
    if not printer:
        return False, "No se encontro una impresora termica ESC/POS instalada."

    handle = None
    try:
        handle = win32print.OpenPrinter(printer)
        return True, f"Permiso de impresion disponible en {printer}."
    except Exception as exc:
        return False, f"Windows no permite abrir la impresora {printer}: {exc}"
    finally:
        if handle is not None:
            win32print.ClosePrinter(handle)


def print_raw(payload: bytes, printer_name: str | None = None) -> tuple[bool, str]:
    if win32print is None:
        return False, "pywin32 no esta instalado o no se esta ejecutando en Windows."

    printer = text(printer_name) or default_printer()
    if not printer:
        return False, "No se encontro una impresora termica ESC/POS. Instale su driver o configure MYPOS_PRINTER_NAME."

    handle = None
    try:
        handle = win32print.OpenPrinter(printer)
        job = win32print.StartDocPrinter(handle, 1, ("MyPOS ticket", None, "RAW"))
        win32print.StartPagePrinter(handle)
        win32print.WritePrinter(handle, payload)
        win32print.EndPagePrinter(handle)
        win32print.EndDocPrinter(handle)
        return True, f"Impreso en {printer}"
    except Exception as exc:
        return False, str(exc)
    finally:
        if handle is not None:
            try:
                win32print.ClosePrinter(handle)
            except Exception:
                pass


def image_to_escpos_raster(img: Any, max_width: int = DEFAULT_RASTER_WIDTH) -> bytes:
    if Image is None:
        raise RuntimeError("Pillow no esta instalado.")

    if img.mode in ("RGBA", "LA"):
        background = Image.new("RGB", img.size, "white")
        background.paste(img, mask=img.split()[-1])
        img = background

    img = img.convert("1")
    width, height = img.size

    if width > max_width:
        ratio = max_width / width
        img = img.resize((max_width, max(1, int(height * ratio))), Image.NEAREST)
        width, height = img.size

    bytes_per_row = (width + 7) // 8
    data = bytearray()
    data.extend(GS + b"v0\x00")
    data.extend(bytes([bytes_per_row % 256, bytes_per_row // 256]))
    data.extend(bytes([height % 256, height // 256]))

    pixels = img.load()
    for y in range(height):
        for block in range(bytes_per_row):
            byte_value = 0
            for bit in range(8):
                x = block * 8 + bit
                if x < width and pixels[x, y] == 0:
                    byte_value |= 0x80 >> bit
            data.append(byte_value)

    return bytes(data)


def append_pdf417(ticket: bytearray, ted_xml: str) -> None:
    if not ted_xml:
        ticket.extend(CMD_CENTER)
        ticket.extend(enc("*** SIN TIMBRE ELECTRONICO ***\n"))
        return

    try:
        from pdf417 import encode as pdf417_encode
        from pdf417 import render_image as pdf417_render

        ted_safe = ted_xml.encode("iso-8859-1", errors="replace").decode("iso-8859-1")
        codes = pdf417_encode(ted_safe, columns=17, security_level=5, encoding="iso-8859-1")
        img = pdf417_render(codes, scale=1, ratio=5)
        ticket.extend(CMD_CENTER)
        ticket.extend(image_to_escpos_raster(img, max_width=PDF417_RASTER_WIDTH))
        ticket.extend(b"\n")
        return
    except Exception as raster_error:
        print(f"[PDF417] raster fallback: {raster_error}")

    try:
        data_bytes = ted_xml.encode("latin-1", errors="replace")[:1850]
        ticket.extend(GS_K + bytes([3, 0, 48, 68, 3]))
        ticket.extend(GS_K + bytes([3, 0, 48, 70, 3]))
        ticket.extend(GS_K + bytes([4, 0, 48, 69, 48, 0]))
        param_len = 2 + len(data_bytes)
        ticket.extend(GS_K + bytes([param_len % 256, param_len // 256, 48, 80]) + data_bytes)
        ticket.extend(GS_K + bytes([3, 0, 48, 81, 48]))
    except Exception as native_error:
        print(f"[PDF417] native fallback failed: {native_error}")
        ticket.extend(CMD_CENTER)
        ticket.extend(enc("*** TIMBRE NO DISPONIBLE ***\n"))


def barcode_to_escpos(code: str, max_width: int = 400) -> bytes:
    """Genera imagen de código de barras ESC/POS raster. EAN-13 si 12-13 dígitos, Code128 en otro caso."""
    if Image is None:
        return b""
    try:
        import barcode as python_barcode
        from barcode.writer import ImageWriter

        code = text(code)
        if not code:
            return b""

        # Determinar tipo: EAN-13 si el código tiene 12 o 13 dígitos numéricos
        if code.isdigit() and len(code) in (12, 13):
            barcode_type = "ean13"
            code = code[:12]  # python-barcode calcula el dígito verificador
        else:
            barcode_type = "code128"

        writer = ImageWriter()
        buf = io.BytesIO()
        bc = python_barcode.get(barcode_type, code, writer=writer)
        bc.write(buf, options={
            "module_width": 0.35,
            "module_height": 10.0,
            "quiet_zone": 2.0,
            "font_size": 8,
            "text_distance": 2.0,
            "write_text": True,
            "background": "white",
            "foreground": "black",
            "dpi": 200,
        })
        buf.seek(0)
        img = Image.open(buf)
        return image_to_escpos_raster(img, max_width=max_width)
    except Exception as exc:
        print(f"[BARCODE] {exc}")
        return b""


def format_etiqueta_single(item: dict[str, Any], empresa: str, ancho_chars: int = 32) -> bytes:
    """Formatea una etiqueta individual para impresora ESC/POS de 58mm (ancho_chars=32) u 80mm (ancho_chars=48)."""
    label = bytearray()
    label.extend(CMD_INIT)
    label.extend(CMD_CODEPAGE_PC858)

    # Nombre del local (cabecera)
    label.extend(CMD_CENTER)
    label.extend(CMD_BOLD_ON)
    label.extend(enc(text(empresa, ancho_chars) + "\n"))
    label.extend(CMD_BOLD_OFF)

    # Nombre del producto (máx 2 líneas)
    nombre = text(item.get("nombre"), 120)
    rows = wrap_label(nombre, ancho_chars)
    label.extend(CMD_BOLD_ON)
    for row in rows[:2]:
        label.extend(enc(row + "\n"))
    label.extend(CMD_BOLD_OFF)

    # Código interno
    codigo = text(item.get("codigo"), 30)
    if codigo:
        label.extend(CMD_LEFT)
        label.extend(enc(f"COD: {codigo}\n"))

    # Código de barras (EAN o código interno como Code128)
    ean = text(item.get("ean") or item.get("codigo_barra") or item.get("codigo"), 30)
    if ean:
        raster_w = 400 if ancho_chars <= 32 else 576
        bc_bytes = barcode_to_escpos(ean, max_width=raster_w)
        if bc_bytes:
            label.extend(CMD_CENTER)
            label.extend(bc_bytes)
            label.extend(b"\n")

    # Precio en tamaño doble
    precio = number(item.get("precio_venta") or item.get("precio"), 0)
    label.extend(CMD_CENTER)
    label.extend(CMD_BOLD_ON)
    label.extend(CMD_DOUBLE_ON)
    label.extend(enc(money(precio) + "\n"))
    label.extend(CMD_DOUBLE_OFF)
    label.extend(CMD_BOLD_OFF)

    # Fecha de vencimiento (opcional)
    fecha_vto = text(item.get("fecha_vencimiento"), 20)
    if fecha_vto:
        label.extend(CMD_LEFT)
        label.extend(enc(f"Vto: {fecha_vto}\n"))

    return bytes(label)


def format_etiquetas_batch(data: dict[str, Any]) -> bytes:
    """Genera ESC/POS para un lote de etiquetas con corte entre cada una."""
    empresa = text(data.get("empresa") or data.get("nombre_fantasia"), 46) or "MyPOS"
    alto_mm = max(10, min(200, int(number(data.get("alto_mm"), 40))))

    # Cada línea ESC/POS ocupa ~3.5 mm en papel de 203 dpi.
    # Calculamos cuántas líneas extra agregar para llegar al alto deseado.
    # El contenido de una etiqueta típica ocupa ~25-30 mm; el resto es espacio.
    LINEAS_CONTENIDO_EST = 8
    lineas_por_mm = 1 / 3.5
    lineas_extra = max(0, int(alto_mm * lineas_por_mm) - LINEAS_CONTENIDO_EST)
    feed_extra = (ESC + b"d" + bytes([min(lineas_extra, 10)])) if lineas_extra > 0 else b""

    # Ancho: 58 mm → 32 chars, 80 mm → 48 chars
    ancho_chars = 32

    result = bytearray()
    for item in data.get("etiquetas") or []:
        copias = max(1, min(99, int(number(item.get("copias") or item.get("cantidad"), 1))))
        etiqueta = format_etiqueta_single(item, empresa, ancho_chars)
        for _ in range(copias):
            result.extend(etiqueta)
            result.extend(feed_extra)
            result.extend(CMD_FEED_CUT)

    return bytes(result)


def format_ticket(data: dict[str, Any]) -> bytes:
    ticket = bytearray()
    ticket.extend(CMD_INIT)
    ticket.extend(CMD_CODEPAGE_PC858)
    ticket.extend(CMD_CENTER)
    ticket.extend(CMD_BOLD_ON)
    company_name = text(data.get("nombre_fantasia") or data.get("empresa") or data.get("razon_social"), 46)
    ticket.extend(enc((company_name or "Empresa no informada") + "\n"))
    ticket.extend(CMD_BOLD_OFF)
    legal_name = text(data.get("razon_social"), 46)
    if legal_name and legal_name != company_name:
        ticket.extend(enc(legal_name + "\n"))
    for key, label in (("rut_emisor", "RUT"), ("giro", "")):
        value = text(data.get(key), 46)
        if value:
            ticket.extend(enc((f"{label}: " if label else "") + value + "\n"))
    location = ", ".join(filter(None, [
        text(data.get("direccion"), 46),
        text(data.get("comuna"), 24),
        text(data.get("ciudad"), 24),
    ]))
    if location:
        ticket.extend(enc(text(location, 46) + "\n"))
    for key in ("telefono", "sitio_web"):
        value = text(data.get(key), 46)
        if value:
            ticket.extend(enc(value + "\n"))
    if data.get("sucursal"):
        ticket.extend(enc(text(data.get("sucursal"), 42) + "\n"))
    ticket.extend(separator("="))
    ticket.extend(CMD_BOLD_ON)
    ticket.extend(enc("TICKET DE VENTA\n"))
    ticket.extend(enc("NO VALIDO COMO DOCUMENTO TRIBUTARIO\n"))
    ticket.extend(CMD_BOLD_OFF)
    ticket.extend(separator("="))
    ticket.extend(CMD_LEFT)
    if data.get("venta_id"):
        ticket.extend(line("Venta", "#" + text(data.get("venta_id"))))
    ticket.extend(line("Fecha", text(data.get("fecha")) or datetime.now().strftime("%Y-%m-%d %H:%M:%S")))
    ticket.extend(line("Pago", text(data.get("metodo_pago")) or "EFECTIVO"))
    ticket.extend(separator())
    ticket.extend(CMD_BOLD_ON)
    ticket.extend(enc(f"{'Producto':<28}{'Cant':>5}{'Total':>15}\n"))
    ticket.extend(CMD_BOLD_OFF)
    ticket.extend(separator())

    for item in data.get("productos") or []:
        quantity = number(item.get("cantidad"), 1)
        subtotal = item.get("subtotal")
        if subtotal is None:
            subtotal = number(item.get("precio_unitario"), 0) * quantity
        rows = wrap_label(text(item.get("nombre")) or "Producto", 28)
        ticket.extend(enc(f"{rows[0]:<28}{quantity:>5.2f}{money(subtotal):>15}\n"))
        for extra in rows[1:]:
            ticket.extend(enc(extra[:28] + "\n"))

    ticket.extend(separator("="))
    ticket.extend(CMD_RIGHT)
    ticket.extend(CMD_BOLD_ON)
    ticket.extend(CMD_DOUBLE_ON)
    ticket.extend(enc(f"TOTAL {money(data.get('total'))}\n"))
    ticket.extend(CMD_DOUBLE_OFF)
    ticket.extend(CMD_BOLD_OFF)
    ticket.extend(CMD_LEFT)

    received = number(data.get("monto_recibido"), 0)
    change = number(data.get("vuelto"), 0)
    if received > 0:
        ticket.extend(line("Recibido", money(received)))
        ticket.extend(line("Vuelto", money(change)))

    ticket.extend(CMD_CENTER)
    ticket.extend(enc("\nGracias por su compra\n"))
    ticket.extend(CMD_FEED_CUT)
    return bytes(ticket)


def format_boleta_electronica_dte(data: dict[str, Any]) -> bytes:
    ticket = bytearray()
    ticket.extend(CMD_INIT)
    ticket.extend(CMD_CODEPAGE_PC858)
    ticket.extend(CMD_CENTER)
    ticket.extend(CMD_BOLD_ON)
    ticket.extend(enc((text(data.get("razon_social") or data.get("nombre_fantasia"), 46) or "Empresa no informada") + "\n"))
    ticket.extend(CMD_BOLD_OFF)

    for key, label in (("rut_emisor", "RUT"), ("giro", ""), ("direccion", ""), ("comuna", ""), ("ciudad", ""), ("telefono", ""), ("sitio_web", "")):
        value = text(data.get(key), 46)
        if value:
            ticket.extend(enc((f"{label}: " if label else "") + value + "\n"))

    ticket.extend(separator("="))
    ticket.extend(CMD_BOLD_ON)
    ticket.extend(enc("BOLETA ELECTRONICA\n"))
    folio = text(data.get("folio_dte") or data.get("folio"))
    if folio:
        ticket.extend(enc(f"Nro {folio}\n"))
    ticket.extend(CMD_BOLD_OFF)
    ticket.extend(separator("="))
    ticket.extend(CMD_LEFT)
    ticket.extend(line("Fecha", text(data.get("fecha_dte") or data.get("fecha"))))
    ticket.extend(line("Tipo DTE", text(data.get("tipo_dte") or 39)))
    if data.get("track_id"):
        ticket.extend(line("Track ID", text(data.get("track_id"))))

    ticket.extend(format_ticket_body(data))
    append_pdf417(ticket, text(data.get("ted_xml")))
    ticket.extend(CMD_CENTER)
    ticket.extend(CMD_BOLD_ON)
    ticket.extend(enc("Timbre Electronico SII\n"))
    ticket.extend(CMD_BOLD_OFF)
    resol = text(data.get("nro_resol"))
    resol_date = text(data.get("fch_resol"))
    if resol and resol_date:
        ticket.extend(enc(f"Res. {resol} de {resol_date[:4]}\n"))
    ticket.extend(enc("Verifique en www.sii.cl\n"))
    if data.get("verify_url"):
        ticket.extend(enc(text(data.get("verify_url"), 46) + "\n"))
    ticket.extend(CMD_FEED_CUT)
    return bytes(ticket)


def format_ticket_body(data: dict[str, Any]) -> bytes:
    body = bytearray()
    body.extend(separator())
    body.extend(CMD_BOLD_ON)
    body.extend(enc(f"{'Producto':<28}{'Cant':>5}{'Total':>15}\n"))
    body.extend(CMD_BOLD_OFF)
    body.extend(separator())

    for item in data.get("productos") or []:
        quantity = number(item.get("cantidad"), 1)
        subtotal = item.get("subtotal")
        if subtotal is None:
            subtotal = number(item.get("precio") or item.get("precio_unitario"), 0) * quantity
        rows = wrap_label(text(item.get("nombre")) or "Producto", 28)
        body.extend(enc(f"{rows[0]:<28}{quantity:>5.2f}{money(subtotal):>15}\n"))
        for extra in rows[1:]:
            body.extend(enc(extra[:28] + "\n"))

    body.extend(separator("="))
    total = number(data.get("total"), 0)
    tax_rate = number(data.get("tasa_iva"), 19)
    net = number(data.get("neto"), 0)
    tax = number(data.get("iva"), 0)
    if net <= 0 and total > 0:
        tax = round(total - total / (1 + tax_rate / 100))
        net = total - tax
    body.extend(line("Neto", money(net)))
    body.extend(line(f"IVA {int(tax_rate)}%", money(tax)))
    body.extend(CMD_RIGHT)
    body.extend(CMD_BOLD_ON)
    body.extend(CMD_DOUBLE_ON)
    body.extend(enc(f"TOTAL {money(total)}\n"))
    body.extend(CMD_DOUBLE_OFF)
    body.extend(CMD_BOLD_OFF)
    body.extend(CMD_LEFT)
    return bytes(body)


def print_pdf_job(data: dict[str, Any]) -> tuple[bool, str]:
    if win32api is None:
        return False, "win32api no esta disponible para imprimir PDF."
    if not os.path.isfile(SUMATRA_PDF_PATH):
        return False, f"No existe SumatraPDF en {SUMATRA_PDF_PATH}"

    pdf_bytes: bytes
    if data.get("pdf_url"):
        with urllib.request.urlopen(text(data["pdf_url"]), timeout=20) as response:
            pdf_bytes = response.read()
    elif data.get("pdf_base64"):
        pdf_bytes = base64.b64decode(data["pdf_base64"])
    else:
        return False, "Se requiere pdf_url o pdf_base64."

    fd, path = tempfile.mkstemp(suffix=".pdf", prefix="mypos_")
    os.close(fd)
    with open(path, "wb") as handle:
        handle.write(pdf_bytes)

    printer = text(data.get("printer")) or default_printer()
    args = f'-print-to "{printer}" -silent "{path}"' if printer else f'-print-default -silent "{path}"'
    win32api.ShellExecute(0, "open", SUMATRA_PDF_PATH, args, ".", 0)
    return True, "PDF enviado a impresion."


@app.get("/health")
def health():
    return jsonify({"success": True, "data": {"status": "ok", "version": VERSION}})


@app.get("/status")
def status():
    printer = default_printer()
    printer_access, printer_access_message = check_printer_access(printer)
    return jsonify(
        {
            "success": True,
            "data": {
                "service": "MyPOS Print Server",
                "version": VERSION,
                "host": HOST,
                "port": PORT,
                "printer": printer,
                "printer_access": printer_access,
                "printer_access_message": printer_access_message,
                "pywin32": win32print is not None,
                "pillow": Image is not None,
                "dte_pdf417": True,
            },
        }
    )


@app.get("/printers")
def printers():
    return jsonify({"success": True, "data": {"printers": list_printers(), "default": default_printer()}})


@app.get("/test")
def test_print():
    payload = format_ticket(
        {
            "empresa": "MyPOS",
            "sucursal": "Prueba local",
            "venta_id": "TEST",
            "fecha": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            "metodo_pago": "EFECTIVO",
            "total": 1000,
            "monto_recibido": 1000,
            "vuelto": 0,
            "productos": [{"nombre": "Prueba de impresion", "cantidad": 1, "subtotal": 1000}],
        }
    )
    ok, message = print_raw(payload, request.args.get("printer"))
    return jsonify({"success": ok, "message": message}), 200 if ok else 500


@app.post("/print")
def print_ticket():
    data = request.get_json(silent=True) or {}
    if not isinstance(data, dict):
        return jsonify({"success": False, "error": "Payload JSON invalido."}), 400

    kind = text(data.get("tipo") or "ticket")
    copies = max(1, int(number(data.get("copias") or data.get("copies") or 1, 1)))
    printer = text(data.get("printer")) or None

    try:
        if kind in ("boleta_electronica_pdf", "pdf"):
            ok, message = print_pdf_job(data)
            return jsonify({"success": ok, "message": message}), 200 if ok else 500

        if kind == "etiquetas":
            payload = format_etiquetas_batch(data)
            ok, message = print_raw(payload, printer)
            return jsonify({"success": ok, "message": message}), 200 if ok else 500

        payload = format_boleta_electronica_dte(data) if kind == "boleta_electronica_dte" else format_ticket(data)
        printed = 0
        last_message = ""
        for _ in range(copies):
            ok, last_message = print_raw(payload, printer)
            if ok:
                printed += 1

        if printed:
            return jsonify({"success": True, "message": f"Impreso {printed}/{copies} copias."})
        return jsonify({"success": False, "error": last_message}), 500
    except Exception as exc:
        return jsonify({"success": False, "error": str(exc)}), 500


# ─── DIGI SM-300 Balanza Bridge ───────────────────────────────────────────────
# Corre en el mismo proceso que el print server.
# Detecta la balanza en la red local, graba la config y la expone vía HTTP.
# No interfiere con la impresión (canal completamente separado).

BALANZA_IP      = os.environ.get("MYPOS_BALANZA_IP", "192.168.0.130")
BALANZA_PORTS   = [10000, 8000, 4000, 9100]
BALANZA_TIMEOUT = 4
BALANZA_CFG     = os.path.join(os.path.dirname(os.path.abspath(__file__)), "balanza_config.json")
BALANZA_CACHE_TTL = 60  # segundos entre re-diagnósticos automáticos

_balanza_cache: dict = {}
_balanza_cache_ts: float = 0


def _guardar_balanza_cfg(cfg: dict) -> None:
    try:
        with open(BALANZA_CFG, "w") as f:
            json.dump(cfg, f, indent=2)
    except Exception:
        pass


def _cargar_balanza_cfg() -> dict:
    try:
        if os.path.exists(BALANZA_CFG):
            with open(BALANZA_CFG) as f:
                return json.load(f)
    except Exception:
        pass
    return {}


def _detectar_puerto(ip: str) -> int | None:
    for puerto in BALANZA_PORTS:
        try:
            s = socket.create_connection((ip, puerto), timeout=2)
            s.close()
            return puerto
        except Exception:
            continue
    return None


def _estado_balanza(forzar: bool = False) -> dict:
    global _balanza_cache, _balanza_cache_ts

    now = time.time()

    # Cache en memoria (evita TCP innecesario entre llamadas)
    if not forzar and _balanza_cache and (now - _balanza_cache_ts) < BALANZA_CACHE_TTL:
        return _balanza_cache

    # Config grabada en disco (sobrevive reinicios del print server)
    cfg = _cargar_balanza_cfg()
    if not forzar and cfg.get("estado") == "ok":
        _balanza_cache = cfg
        _balanza_cache_ts = now
        return cfg

    # Diagnóstico TCP completo
    resultado: dict = {
        "ip": BALANZA_IP,
        "puerto": None,
        "estado": "sin_conexion",
        "ts": datetime.now().isoformat(),
    }
    puerto = _detectar_puerto(BALANZA_IP)
    if puerto:
        resultado["puerto"] = puerto
        resultado["estado"] = "ok"

    _guardar_balanza_cfg(resultado)
    _balanza_cache = resultado
    _balanza_cache_ts = now
    return resultado


def _parsear_respuesta_rcth(raw: bytes) -> list:
    """
    PENDIENTE: implementar cuando se capture el protocolo Teraoka con Wireshark.
    Formato esperado: [{"nombre": "Lomo Liso", "peso_kg": 1.144, "precio_unit": 17000, "subtotal": 19450}]
    Por ahora devuelve lista vacía y el fallback usa el total del EAN-13.
    """
    return []


def _consultar_rcth(rcth: int, ip: str, puerto: int) -> dict:
    """Envía trama Teraoka y lee la respuesta de la balanza."""
    try:
        s = socket.create_connection((ip, puerto), timeout=BALANZA_TIMEOUT)
        trama = f"\x02RD{str(rcth).zfill(4)}\x03".encode()
        s.sendall(trama)

        respuesta = b""
        s.settimeout(3)
        try:
            while True:
                chunk = s.recv(512)
                if not chunk:
                    break
                respuesta += chunk
                if b"\x03" in respuesta:
                    break
        except socket.timeout:
            pass
        s.close()

        return {
            "ok": True,
            "rcth": rcth,
            "raw_hex": respuesta.hex(),
            "items": _parsear_respuesta_rcth(respuesta),
        }
    except Exception as exc:
        return {"ok": False, "error": str(exc), "rcth": rcth, "items": []}


@app.get("/balanza/estado")
def balanza_estado():
    forzar = request.args.get("forzar", "0") == "1"
    cfg = _estado_balanza(forzar=forzar)
    return jsonify({"success": True, "data": cfg})


@app.get("/balanza/rcth")
def balanza_rcth():
    try:
        rcth = int(request.args.get("n", 0))
        if rcth <= 0:
            raise ValueError
    except (ValueError, TypeError):
        return jsonify({"success": False, "error": "Parámetro n (rcth) inválido"}), 400

    cfg = _estado_balanza()
    if cfg.get("estado") != "ok":
        return jsonify({"success": False, "error": "Balanza no disponible", "items": []}), 503

    resultado = _consultar_rcth(rcth, cfg["ip"], cfg["puerto"])
    return jsonify({"success": resultado["ok"], "data": resultado})

# ─── Fin DIGI Bridge ──────────────────────────────────────────────────────────


if __name__ == "__main__":
    print(f"MyPOS Print Server {VERSION} listening on http://{HOST}:{PORT}")
    app.run(host=HOST, port=PORT, debug=False)
