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


VERSION = "1.1.8"
HOST = "127.0.0.1"
PORT = 5555
DEFAULT_WIDTH = 48
DEFAULT_RASTER_WIDTH = 576
PDF417_RASTER_WIDTH = 480
SUMATRA_PDF_PATH = os.environ.get("MYPOS_SUMATRA_PDF", r"C:\MyPOSPrint\SumatraPDF.exe")
PDF_PRINTER_TOKENS = ("PDF", "PDFCREATOR", "PDF24", "MICROSOFT PRINT TO PDF", "ADOBE PDF")

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


def format_rut(value: Any) -> str:
    raw = text(value).replace(".", "").replace(" ", "").upper()
    if "-" not in raw and len(raw) > 1:
        raw = raw[:-1] + "-" + raw[-1]
    if "-" not in raw:
        return text(value)
    body, dv = raw.split("-", 1)
    if not body.isdigit():
        return text(value)
    groups: list[str] = []
    while body:
        groups.insert(0, body[-3:])
        body = body[:-3]
    return ".".join(groups) + "-" + dv[:1]


def dte_type_name(tipo_dte: Any) -> str:
    return {
        "39": "BOLETA ELECTRONICA",
        "41": "BOLETA EXENTA ELECTRONICA",
        "33": "FACTURA ELECTRONICA",
        "34": "FACTURA EXENTA ELECTRONICA",
        "52": "GUIA DESPACHO ELECTRONICA",
        "56": "NOTA DEBITO ELECTRONICA",
        "61": "NOTA CREDITO ELECTRONICA",
    }.get(text(tipo_dte or 39), "BOLETA ELECTRONICA")


def sii_unit_line(data: dict[str, Any]) -> str:
    unidad = text(data.get("unidad_sii") or data.get("unidadSII"), 34)
    return "S.I.I." + (f" - {unidad}" if unidad else "")


def resolution_line(data: dict[str, Any]) -> str:
    number_value = text(data.get("nro_resol") or data.get("resolNum"))
    date_value = text(data.get("fch_resol") or data.get("resolFch"))
    if not number_value and not date_value:
        return ""
    year = date_value[:4] if len(date_value) >= 4 else date_value
    return f"Res. Nro {number_value or '0'}" + (f" de {year}" if year else "")


def enc(value: str) -> bytes:
    return value.encode("cp850", errors="replace")


def line(left: str, right: str = "", width: int = DEFAULT_WIDTH) -> bytes:
    left = text(left)
    right = text(right)
    if not right:
        return enc(left[:width] + "\n")
    available = max(1, width - len(right))
    return enc(left[:available].ljust(available) + right[:width] + "\n")


def separator(char: str = "-", width: int = DEFAULT_WIDTH) -> bytes:
    return enc(char * width + "\n")


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
    preferred = (
        "TM-T20",
        "TM-T88",
        "TM-M",
        "POS",
        "THERMAL",
        "TERMICA",
        "TERMICO",
        "BIXOLON",
        "STAR",
        "TICKET",
        "XPRINTER",
        "XP-",
        "GP-",
        "GPRINTER",
        "USB",
        *PDF_PRINTER_TOKENS,
    )
    for name in printers:
        upper = name.upper()
        if any(token in upper for token in preferred):
            return name

    if win32print is not None:
        try:
            default = text(win32print.GetDefaultPrinter())
            if default:
                return default
        except Exception:
            pass

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


def is_pdf_printer(printer_name: str | None) -> bool:
    upper = text(printer_name).upper()
    return upper != "" and any(token in upper for token in PDF_PRINTER_TOKENS)


def pdf_escape(value: str) -> str:
    return value.replace("\\", "\\\\").replace("(", "\\(").replace(")", "\\)")


def write_text_pdf(path: str, lines: list[str], title: str = "MyPOS ticket") -> None:
    page_width = 226
    line_height = 11
    top_margin = 24
    bottom_margin = 24
    page_height = max(420, top_margin + bottom_margin + (len(lines) + 2) * line_height)
    y = page_height - top_margin

    stream_lines = [
        "BT",
        "/F1 8.5 Tf",
        f"14 {y} Td",
        f"{line_height} TL",
    ]
    for line_text in lines:
        stream_lines.append(f"({pdf_escape(line_text[:54])}) Tj")
        stream_lines.append("T*")
    stream_lines.append("ET")
    stream = "\n".join(stream_lines).encode("latin-1", errors="replace")

    objects = [
        b"<< /Type /Catalog /Pages 2 0 R >>",
        b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        (
            f"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {page_width} {page_height}] "
            f"/Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>"
        ).encode("ascii"),
        b"<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>",
        b"<< /Length " + str(len(stream)).encode("ascii") + b" >>\nstream\n" + stream + b"\nendstream",
    ]

    pdf = bytearray(b"%PDF-1.4\n%\xe2\xe3\xcf\xd3\n")
    offsets = [0]
    for index, obj in enumerate(objects, start=1):
        offsets.append(len(pdf))
        pdf.extend(f"{index} 0 obj\n".encode("ascii"))
        pdf.extend(obj)
        pdf.extend(b"\nendobj\n")

    xref_offset = len(pdf)
    pdf.extend(f"xref\n0 {len(objects) + 1}\n".encode("ascii"))
    pdf.extend(b"0000000000 65535 f \n")
    for offset in offsets[1:]:
        pdf.extend(f"{offset:010d} 00000 n \n".encode("ascii"))
    pdf.extend(
        (
            f"trailer\n<< /Size {len(objects) + 1} /Root 1 0 R /Info << /Title ({pdf_escape(title)}) >> >>\n"
            f"startxref\n{xref_offset}\n%%EOF\n"
        ).encode("latin-1", errors="replace")
    )

    with open(path, "wb") as handle:
        handle.write(pdf)


def ticket_pdf_lines(data: dict[str, Any], kind: str = "ticket") -> list[str]:
    width = 38
    lines: list[str] = []
    company = text(data.get("nombre_fantasia") or data.get("empresa") or data.get("razon_social"), 46) or "Empresa no informada"
    lines.append(company[:width].center(width))
    legal_name = text(data.get("razon_social"), 46)
    if legal_name and legal_name != company:
        lines.append(legal_name[:width].center(width))

    for key, label in (("rut_emisor", "RUT"), ("giro", ""), ("direccion", ""), ("comuna", ""), ("ciudad", "")):
        value = text(data.get(key), 46)
        if value:
            lines.append(((f"{label}: " if label else "") + value)[:width])

    lines.append("=" * width)
    if kind == "boleta_electronica_dte":
        lines.append(("-" * 30).center(width))
        rut = format_rut(data.get("rut_emisor"))
        if rut:
            lines.append(f"R.U.T.: {rut}".center(width))
        lines.append(dte_type_name(data.get("tipo_dte")).center(width))
        folio = text(data.get("folio_dte") or data.get("folio"))
        if folio:
            lines.append(f"Nro {folio}".center(width))
        lines.append(("-" * 30).center(width))
        lines.append(sii_unit_line(data).center(width))
    else:
        lines.append("TICKET DE VENTA".center(width))
        lines.append("NO VALIDO COMO DOCUMENTO TRIBUTARIO".center(width))
    lines.append("=" * width)

    if data.get("venta_id"):
        lines.append(f"Venta: #{text(data.get('venta_id'))}")
    lines.append(f"Fecha: {text(data.get('fecha_dte') or data.get('fecha')) or datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    lines.append(f"Pago : {text(data.get('metodo_pago')) or 'EFECTIVO'}")
    lines.append("-" * width)
    lines.append(f"{'Producto':<22}{'Cant':>5}{'Total':>11}")
    lines.append("-" * width)

    for item in data.get("productos") or []:
        quantity = number(item.get("cantidad"), 1)
        subtotal = item.get("subtotal")
        if subtotal is None:
            subtotal = number(item.get("precio") or item.get("precio_unitario"), 0) * quantity
        rows = wrap_label(text(item.get("nombre")) or "Producto", 22)
        lines.append(f"{rows[0]:<22}{quantity:>5.2f}{money(subtotal):>11}")
        for extra in rows[1:]:
            lines.append(extra[:22])

    lines.append("=" * width)
    lines.append(f"TOTAL {money(data.get('total'))}".rjust(width))
    received = number(data.get("monto_recibido"), 0)
    change = number(data.get("vuelto"), 0)
    if received > 0:
        lines.append(f"Recibido {money(received)}".rjust(width))
        lines.append(f"Vuelto   {money(change)}".rjust(width))
    if kind == "boleta_electronica_dte":
        lines.append("")
        if data.get("ted_xml"):
            lines.append("[PDF417 TED disponible en impresora termica]".center(width))
        else:
            lines.append("*** SIN TIMBRE ELECTRONICO ***".center(width))
        lines.append("Timbre Electronico SII".center(width))
        resol = resolution_line(data)
        if resol:
            lines.append(resol.center(width))
        lines.append("Verifique documento: www.sii.cl".center(width))
        verify_url = text(data.get("verify_url"), width)
        if verify_url:
            lines.append(verify_url.center(width))
    lines.append("")
    if kind != "boleta_electronica_dte":
        lines.append("Gracias por su compra".center(width))
    return lines


def etiquetas_pdf_lines(data: dict[str, Any]) -> list[str]:
    lines = [text(data.get("empresa") or data.get("nombre_fantasia"), 38) or "MyPOS", "=" * 38]
    for item in data.get("etiquetas") or []:
        copies = max(1, min(99, int(number(item.get("copias") or item.get("cantidad"), 1))))
        lines.append(text(item.get("nombre"), 38) or "Producto")
        lines.append(f"COD: {text(item.get('codigo'), 30)}")
        ean = text(item.get("ean") or item.get("codigo_barra"), 30)
        if ean:
            lines.append(f"EAN: {ean}")
        lines.append(f"Precio: {money(item.get('precio_venta') or item.get('precio'))}")
        if item.get("fecha_vencimiento"):
            lines.append(f"Vto: {text(item.get('fecha_vencimiento'), 20)}")
        lines.append(f"Copias: {copies}")
        lines.append("-" * 38)
    return lines


def write_boleta_dte_image_pdf(path: str, data: dict[str, Any]) -> bool:
    if Image is None or not (data.get("ted_b64") or data.get("ted_xml")):
        return False

    try:
        from PIL import ImageDraw, ImageFont
        from pdf417 import encode as pdf417_encode
        from pdf417 import render_image as pdf417_render

        width = 640
        margin = 26
        line_h = 22
        font = ImageFont.load_default()
        bold_font = font
        canvas = Image.new("RGB", (width, 1800), "white")
        draw = ImageDraw.Draw(canvas)
        y = 24

        def text_width(value: str, used_font: Any = font) -> int:
            box = draw.textbbox((0, 0), value, font=used_font)
            return box[2] - box[0]

        def center(value: str, used_font: Any = font, step: int = line_h) -> None:
            nonlocal y
            value = text(value, 78)
            draw.text(((width - text_width(value, used_font)) // 2, y), value, fill="black", font=used_font)
            y += step

        def left(value: str, used_font: Any = font, step: int = line_h) -> None:
            nonlocal y
            draw.text((margin, y), text(value, 90), fill="black", font=used_font)
            y += step

        def rule(x_pad: int = 70, step: int = 12) -> None:
            nonlocal y
            draw.line((x_pad, y, width - x_pad, y), fill="black", width=2)
            y += step

        rule()
        rut = format_rut(data.get("rut_emisor"))
        if rut:
            center(f"R.U.T.: {rut}", bold_font)
        center(dte_type_name(data.get("tipo_dte")), bold_font)
        folio = text(data.get("folio_dte") or data.get("folio"))
        if folio:
            center(f"Nro {folio}", bold_font)
        rule()
        center(sii_unit_line(data), bold_font)
        y += 10

        left(text(data.get("razon_social") or data.get("nombre_fantasia"), 70) or "Empresa no informada", bold_font)
        for key, label in (("giro", "Giro"), ("direccion", "Casa Matriz"), ("comuna", "Comuna"), ("ciudad", "Ciudad")):
            value = text(data.get(key), 70)
            if value:
                left(f"{label}: {value}")
        left(f"Fecha: {text(data.get('fecha_dte') or data.get('fecha'))}")
        y += 6
        rule(margin)

        left(f"{'Producto':<30}{'Cant':>7}{'Total':>14}", bold_font)
        rule(margin)
        for item in data.get("productos") or []:
            quantity = number(item.get("cantidad"), 1)
            subtotal = item.get("subtotal")
            if subtotal is None:
                subtotal = number(item.get("precio") or item.get("precio_unitario"), 0) * quantity
            rows = wrap_label(text(item.get("nombre")) or "Producto", 30)
            left(f"{rows[0]:<30}{quantity:>7.2f}{money(subtotal):>14}")
            for extra in rows[1:]:
                left(extra)

        rule(margin)
        center(f"TOTAL {money(data.get('total'))}", bold_font, 28)
        y += 8

        barcode_img = timbre_image(data)
        if barcode_img is None:
            ted_safe = resolve_ted(data).encode("iso-8859-1", errors="replace").decode("iso-8859-1")
            codes = pdf417_encode(ted_safe, columns=8, security_level=5, encoding="iso-8859-1")
            barcode_img = pdf417_render(codes, scale=3, ratio=3, padding=12).convert("RGB")
        if barcode_img.width > 540:
            ratio = 540 / barcode_img.width
            barcode_img = barcode_img.resize((540, max(1, int(barcode_img.height * ratio))))
        canvas.paste(barcode_img, ((width - barcode_img.width) // 2, y))
        y += barcode_img.height + 10
        center("Timbre Electronico SII", bold_font)
        resol = resolution_line(data)
        if resol:
            center(resol)
        center("Verifique documento: www.sii.cl")
        verify_url = text(data.get("verify_url"), 70)
        if verify_url:
            center(verify_url)

        cropped = canvas.crop((0, 0, width, min(canvas.height, y + 30)))
        cropped.save(path, "PDF", resolution=203.0)
        return True
    except Exception as exc:
        print(f"[PDF DTE] image fallback failed: {exc}")
        return False


def print_as_pdf_file(data: dict[str, Any], kind: str = "ticket") -> tuple[bool, str]:
    documents = os.path.join(os.path.expanduser("~"), "Documents", "MyPOS")
    os.makedirs(documents, exist_ok=True)
    stamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    venta = text(data.get("venta_id") or data.get("folio_dte") or data.get("folio") or stamp, 40)
    filename = f"MyPOS ticket {venta}.pdf".replace("/", "-").replace("\\", "-").replace(":", "-")
    path = os.path.join(documents, filename)
    if kind != "boleta_electronica_dte" or not write_boleta_dte_image_pdf(path, data):
        lines = etiquetas_pdf_lines(data) if kind == "etiquetas" else ticket_pdf_lines(data, kind)
        write_text_pdf(path, lines, title="MyPOS ticket")
    try:
        os.startfile(path)  # type: ignore[attr-defined]
    except Exception:
        pass
    return True, f"PDF generado: {path}"


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


def resolve_ted(data: dict[str, Any]) -> str:
    """Devuelve el TED en ISO-8859-1 byte-exacto. Prefiere ted_b64 (base64) porque
    el TED incluye el CAF con Ñ/tildes y, como texto JSON, se corrompe en transito;
    cae a ted_xml por compatibilidad."""
    b64 = text(data.get("ted_b64"))
    if b64:
        try:
            return base64.b64decode(b64).decode("iso-8859-1")
        except Exception as exc:
            print(f"[TED] base64 decode fallback: {exc}")
    return text(data.get("ted_xml"))


def timbre_image(data: dict[str, Any]) -> Any:
    """Imagen PNG del timbre PDF417 que renderizó el admin (TCPDF), la que la app del
    SII reconoce. Devuelve un PIL.Image o None si no viene / no se puede leer."""
    if Image is None:
        return None
    b64 = text(data.get("timbre_png_b64"))
    if not b64:
        return None
    try:
        return Image.open(io.BytesIO(base64.b64decode(b64))).convert("RGB")
    except Exception as exc:
        print(f"[TIMBRE] png decode fallback: {exc}")
        return None


def append_pdf417(ticket: bytearray, ted: str, columns: int = 8, raster_width: int = 560) -> None:
    if not ted:
        ticket.extend(CMD_CENTER)
        ticket.extend(enc("*** SIN TIMBRE ELECTRONICO ***\n"))
        return

    try:
        from pdf417 import encode as pdf417_encode
        from pdf417 import render_image as pdf417_render

        ted_safe = ted.encode("iso-8859-1", errors="replace").decode("iso-8859-1")
        # SII exige ECC nivel 5. Las columnas se eligen segun el ancho del papel
        # (8 en 80mm, menos en 56mm) para que el timbre quepa sin encoger los modulos.
        # scale=2 (~0.25 mm/modulo a 203 dpi), ratio=3 (alto de fila = 3x el ancho del
        # modulo) y padding=10 (zona de silencio, imprescindible para el lector del SII).
        codes = pdf417_encode(ted_safe, columns=columns, security_level=5, encoding="iso-8859-1")
        img = pdf417_render(codes, scale=2, ratio=3, padding=10)
        ticket.extend(CMD_CENTER)
        ticket.extend(image_to_escpos_raster(img, max_width=raster_width))
        ticket.extend(b"\n")
        return
    except Exception as raster_error:
        print(f"[PDF417] raster fallback: {raster_error}")

    try:
        data_bytes = ted.encode("latin-1", errors="replace")[:1850]
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
    # Ancho del papel: 56mm => 32 caracteres; 80mm (o por defecto) => 48.
    ancho_mm = int(number(data.get("ancho"), 80))
    if ancho_mm <= 58:
        width, pdf_cols, raster_w = 32, 5, 376
    else:
        width, pdf_cols, raster_w = 48, 8, 560

    ticket = bytearray()
    ticket.extend(CMD_INIT)
    ticket.extend(CMD_CODEPAGE_PC858)
    ticket.extend(CMD_CENTER)
    ticket.extend(separator("=", width))
    ticket.extend(CMD_BOLD_ON)
    rut = format_rut(data.get("rut_emisor"))
    if rut:
        ticket.extend(enc(f"R.U.T.: {rut}\n"))
    ticket.extend(enc(dte_type_name(data.get("tipo_dte")) + "\n"))
    folio = text(data.get("folio_dte") or data.get("folio"))
    if folio:
        ticket.extend(enc(f"Nro {folio}\n"))
    ticket.extend(CMD_BOLD_OFF)
    ticket.extend(separator("=", width))
    ticket.extend(enc(sii_unit_line(data) + "\n"))
    ticket.extend(enc("\n"))

    ticket.extend(CMD_LEFT)
    ticket.extend(CMD_BOLD_ON)
    ticket.extend(enc((text(data.get("razon_social") or data.get("nombre_fantasia"), width) or "Empresa no informada") + "\n"))
    ticket.extend(CMD_BOLD_OFF)

    for key, label in (("giro", "Giro"), ("direccion", "Casa Matriz"), ("comuna", "Comuna"), ("ciudad", "Ciudad"), ("telefono", "Telefono"), ("sitio_web", "Web")):
        value = text(data.get(key), width)
        if value:
            ticket.extend(enc((f"{label}: " if label else "") + value + "\n"))

    ticket.extend(CMD_LEFT)
    ticket.extend(line("Fecha", text(data.get("fecha_dte") or data.get("fecha")), width))
    if data.get("track_id"):
        ticket.extend(line("Track ID", text(data.get("track_id")), width))

    ticket.extend(format_ticket_body(data, width))
    # Timbre: preferir la imagen del admin (TCPDF, la que el SII reconoce); si no
    # viene, rasterizar el PDF417 propio como respaldo.
    tpng = timbre_image(data)
    if tpng is not None:
        ticket.extend(CMD_CENTER)
        ticket.extend(image_to_escpos_raster(tpng, max_width=raster_w))
        ticket.extend(b"\n")
    else:
        append_pdf417(ticket, resolve_ted(data), columns=pdf_cols, raster_width=raster_w)
    ticket.extend(CMD_CENTER)
    ticket.extend(CMD_BOLD_ON)
    ticket.extend(enc("Timbre Electronico SII\n"))
    ticket.extend(CMD_BOLD_OFF)
    resol = resolution_line(data)
    if resol:
        ticket.extend(enc(resol + "\n"))
    ticket.extend(enc("Verifique documento: www.sii.cl\n"))
    if data.get("verify_url"):
        ticket.extend(enc(text(data.get("verify_url"), width) + "\n"))
    ticket.extend(CMD_FEED_CUT)
    return bytes(ticket)


def format_ticket_body(data: dict[str, Any], width: int = DEFAULT_WIDTH) -> bytes:
    body = bytearray()
    total_w = 12 if width <= 32 else 15
    cant_w = 5
    name_w = max(8, width - cant_w - total_w)

    body.extend(separator("-", width))
    body.extend(CMD_BOLD_ON)
    body.extend(enc(f"{'Producto':<{name_w}}{'Cant':>{cant_w}}{'Total':>{total_w}}\n"))
    body.extend(CMD_BOLD_OFF)
    body.extend(separator("-", width))

    for item in data.get("productos") or []:
        quantity = number(item.get("cantidad"), 1)
        subtotal = item.get("subtotal")
        if subtotal is None:
            subtotal = number(item.get("precio") or item.get("precio_unitario"), 0) * quantity
        rows = wrap_label(text(item.get("nombre")) or "Producto", name_w)
        body.extend(enc(f"{rows[0]:<{name_w}}{quantity:>{cant_w}.2f}{money(subtotal):>{total_w}}\n"))
        for extra in rows[1:]:
            body.extend(enc(extra[:name_w] + "\n"))

    body.extend(separator("=", width))
    total = number(data.get("total"), 0)
    tax_rate = number(data.get("tasa_iva"), 19)
    net = number(data.get("neto"), 0)
    tax = number(data.get("iva"), 0)
    exento = number(data.get("exento"), 0)
    # Desglose fiel al DTE: Neto/IVA solo si la boleta es afecta; Exento solo si
    # hay monto exento. No se inventa IVA cuando el documento es exento.
    if net > 0 or tax > 0:
        body.extend(line("Neto", money(net), width))
        body.extend(line(f"IVA {int(tax_rate)}%", money(tax), width))
    if exento > 0:
        body.extend(line("Monto Exento", money(exento), width))
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
                "printers": list_printers(),
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
    data = {
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
    printer = text(request.args.get("printer")) or default_printer()
    if is_pdf_printer(printer):
        ok, message = print_as_pdf_file(data, "ticket")
        return jsonify({"success": ok, "message": message}), 200 if ok else 500

    payload = format_ticket(data)
    ok, message = print_raw(payload, printer)
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
        effective_printer = printer or default_printer()
        if kind in ("boleta_electronica_pdf", "pdf"):
            ok, message = print_pdf_job(data)
            return jsonify({"success": ok, "message": message}), 200 if ok else 500

        if kind == "boleta_electronica_dte":
            # Boleta electronica termica nativa (ESC/POS) con timbre PDF417 raster.
            # En impresoras "PDF" (Microsoft Print to PDF, etc.) cae al PDF imagen
            # que tambien lleva el timbre.
            if is_pdf_printer(effective_printer):
                ok, message = print_as_pdf_file(data, "boleta_electronica_dte")
                return jsonify({"success": ok, "message": message}), 200 if ok else 500
            payload = format_boleta_electronica_dte(data)
            ok, message = print_raw(payload, printer)
            return jsonify({"success": ok, "message": message}), 200 if ok else 500

        if is_pdf_printer(effective_printer):
            ok, message = print_as_pdf_file(data, kind)
            return jsonify({"success": ok, "message": message}), 200 if ok else 500

        if kind == "etiquetas":
            payload = format_etiquetas_batch(data)
            ok, message = print_raw(payload, printer)
            return jsonify({"success": ok, "message": message}), 200 if ok else 500

        payload = format_ticket(data)
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
