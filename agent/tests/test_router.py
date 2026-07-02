"""
Suite de regresión del router de reglas + módulos de Fase 0.

NO requiere red, credenciales ni paquetes de proveedores LLM: solo prueba
la lógica pura de main.py (detección de intents), quota_state.py,
telemetry.py y la redefinición de la escalación (agente de solo lectura).

Uso (desde la raíz de agent/):
  python tests/test_router.py

Cada vez que se ajuste un prefijo/marcador del router, correr esta suite:
si un caso deja de detectarse, el cambio rompió una consulta real.
"""

from __future__ import annotations

import asyncio
import json
import os
import sys
import tempfile

_AGENT_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, _AGENT_DIR)

# Entorno mínimo ANTES de importar config/main (sin tocar archivos reales)
_TMP = tempfile.mkdtemp(prefix="mypos_agent_tests_")
os.environ.setdefault("AGENT_QUOTA_DB", os.path.join(_TMP, "quota.db"))
os.environ.setdefault("AGENT_METRICS_LOG", os.path.join(_TMP, "metrics.jsonl"))
os.environ.setdefault("AGENT_UNANSWERED_LOG", os.path.join(_TMP, "unanswered.txt"))
os.environ.setdefault("AGENT_DB", ":memory:")

FAILURES: list[str] = []


def check(label: str, condition: bool, detail: str = "") -> None:
    status = "OK" if condition else "FAIL"
    print(f"   [{status}] {label}" + (f" — {detail}" if detail and not condition else ""))
    if not condition:
        FAILURES.append(label)


# ─── 1. Router de reglas ──────────────────────────────────────────────────────

# (frase, intent esperado, query esperada o None, periodo esperado o None)
# Dataset: ejemplos del menú de ayuda (_HELP_TEXT), test_local histórico y
# variantes reales de operadores chilenos. None en intent = debe caer al
# clasificador (no ser capturada por reglas).
ROUTER_CASES = [
    # Ventas
    ("ventas de hoy", "ventas", None, "hoy"),
    ("cuanto vendimos ayer", "ventas", None, "ayer"),
    ("ventas del mes", "ventas", None, "mes"),
    ("ventas de la semana", "ventas", None, "semana"),
    ("cuanto facturamos el mes pasado", "ventas", None, "mes_anterior"),
    ("cuanto llevamos vendido hasta hoy", "ventas", None, "mes"),
    # Top productos
    ("top productos del mes", "top_productos", None, "mes"),
    ("lo mas vendido de la semana", "top_productos", None, "semana"),
    ("que producto se vendio mas hoy", "top_productos", None, "hoy"),
    ("cual es el producto que mas vendemos", "top_productos", None, ""),
    # Stock crítico / reposición
    ("stock critico", "stock_critico", None, None),
    ("que se esta agotando", "stock_critico", None, None),
    ("productos sin stock", "stock_critico", None, None),
    ("que me sugieres reponer", "reposicion", None, None),
    ("que conviene comprar", "reposicion", None, None),
    # Cajas y cierres
    ("estado de las cajas", "cajas", None, None),
    ("cierres pendientes", "cierres", None, None),
    ("hay cajas sin cerrar", "cierres", None, None),
    # Finanzas / SII
    ("iva del mes", "iva", None, None),
    ("cuanto pago al sii", "iva", None, None),
    ("cuantos folios quedan", "folios", None, None),
    ("folios disponibles de boletas", "folios", None, None),
    # Compras
    ("ordenes de compra pendientes", "compras", None, None),
    # Clientes
    ("busca al cliente juan perez", "cliente", "juan perez", None),
    ("cliente rut 12345678-9", "cliente", None, None),
    # Productos (búsqueda directa y lenguaje natural)
    ("precio del aceite 1L", "producto", "aceite 1L", None),
    ("cuanto vale la coca cola", "producto", None, None),
    ("stock del pan", "producto", "pan", None),
    ("que tipos de carne tiene", "producto", "carne", None),
    ("tienen coca cola", "producto", "coca cola", None),
    ("hay pan", "producto", "pan", None),
    ("venden cigarros", "producto", "cigarros", None),
    ("7802820442731", "producto", "7802820442731", None),
    # Ayuda
    ("que puedes hacer", "ayuda", None, None),
    # Acciones → guía manual (el agente es de solo lectura, nunca ejecuta)
    ("anula la venta 1543", "accion", None, None),
    ("cierra la caja 2", "accion", None, None),
    ("emite una nota de credito", "accion", None, None),
    ("cambia el precio del pan a 1500", "accion", None, None),
    ("ajusta el stock del aceite", "accion", None, None),
    ("elimina el cliente juan", "accion", None, None),
    # No capturables por reglas → clasificador/agente
    ("hola buenas tardes", None, None, None),
    ("cual es el producto mas economico", None, None, None),
]


def test_router() -> None:
    print("1. Router de reglas (regresión)...")
    from main import _detect_intent_rules, _normalize_text

    for phrase, exp_intent, exp_query, exp_period in ROUTER_CASES:
        intent, query, periodo = _detect_intent_rules(_normalize_text(phrase), phrase)
        ok = intent == exp_intent
        if ok and exp_query is not None:
            ok = query == exp_query
        if ok and exp_period is not None:
            ok = periodo == exp_period
        check(
            f"'{phrase}'",
            ok,
            f"esperaba intent={exp_intent} query={exp_query} periodo={exp_period}; "
            f"obtuve intent={intent} query={query!r} periodo={periodo!r}",
        )
    print()


# ─── 2. Agente de solo lectura (redefinición de escalación) ──────────────────

def test_read_only_tools() -> None:
    print("2. Agente de solo lectura...")
    from tools import ALL_TOOLS

    names = {t.name for t in ALL_TOOLS}
    check("guia_accion_manual reemplaza a solicitar_aprobacion_humana",
          "guia_accion_manual" in names and "solicitar_aprobacion_humana" not in names,
          f"tools: {sorted(names)}")

    from graph import state as state_mod
    check("estado sin campos de escalación",
          "escalated" not in state_mod.AgentState.__annotations__
          and "escalation_reason" not in state_mod.AgentState.__annotations__)


def test_accion_intent() -> None:
    print("\n3. Intent 'accion' responde guía manual sin LLM...")
    from main import _dispatch_intent
    from tools.escalate import GUIA_ACCIONES

    resp = asyncio.run(_dispatch_intent("accion", empresa_id=1, thread_id="t-test"))
    check("dispatch de 'accion' devuelve la guía",
          resp is not None and resp.reply == GUIA_ACCIONES and resp.escalated is False,
          f"resp={resp}")
    print()


# ─── 3. quota_state ───────────────────────────────────────────────────────────

def test_quota_state() -> None:
    print("4. Estado de cuota compartido (quota_state)...")
    import quota_state

    quota_state.set_value("quota_blocked_until", 123.5)
    check("set/get roundtrip", quota_state.get_value("quota_blocked_until") == 123.5)
    check("clave inexistente devuelve 0.0", quota_state.get_value("no_existe") == 0.0)

    quota_state.set_value("last_llm_call_at", 0.0)
    ok1, wait1 = quota_state.try_reserve_slot(min_interval=8)
    ok2, wait2 = quota_state.try_reserve_slot(min_interval=8)
    check("primer slot reservado", ok1 and wait1 == 0)
    check("segundo slot bloqueado por intervalo", not ok2 and 1 <= wait2 <= 8,
          f"ok2={ok2} wait2={wait2}")

    ok3, _ = quota_state.try_reserve_slot(min_interval=0)
    check("intervalo 0 siempre reserva", ok3)

    # El valor debe estar persistido en el archivo SQLite (visible a otro worker)
    import sqlite3
    con = sqlite3.connect(os.environ["AGENT_QUOTA_DB"])
    row = con.execute("SELECT value FROM kv WHERE key='quota_blocked_until'").fetchone()
    con.close()
    check("persistido en SQLite (visible entre workers)", row is not None and row[0] == 123.5)
    print()


# ─── 4. telemetry ─────────────────────────────────────────────────────────────

def test_telemetry() -> None:
    print("5. Telemetría JSONL...")
    import telemetry

    telemetry.record(
        layer="reglas", empresa_id=7, thread_id="t-1",
        latency_ms=12, ok=True, message="ventas de hoy", reply_len=80,
    )
    telemetry.record(
        layer="error", empresa_id=7, thread_id="t-2",
        latency_ms=5000, ok=False, message="x" * 500, reply_len=0,
    )

    path = os.environ["AGENT_METRICS_LOG"]
    with open(path, encoding="utf-8") as fh:
        lines = [json.loads(line) for line in fh if line.strip()]

    check("dos registros escritos", len(lines) == 2, f"lineas={len(lines)}")
    check("campos esperados",
          lines[0]["layer"] == "reglas" and lines[0]["empresa_id"] == 7
          and lines[0]["ok"] is True and lines[0]["latency_ms"] == 12)
    check("mensaje truncado a 200", len(lines[1]["message"]) == 200)
    print()


# ─── main ─────────────────────────────────────────────────────────────────────

def main() -> int:
    print("=" * 60)
    print("  MyPOS Agent — Regresión del router y módulos Fase 0")
    print("=" * 60 + "\n")

    test_router()
    test_read_only_tools()
    test_accion_intent()
    test_quota_state()
    test_telemetry()

    print("=" * 60)
    if FAILURES:
        print(f"  {len(FAILURES)} caso(s) FALLARON:")
        for f in FAILURES:
            print(f"   - {f}")
        print("=" * 60)
        return 1
    print("  Todos los casos OK.")
    print("=" * 60)
    return 0


if __name__ == "__main__":
    sys.exit(main())
