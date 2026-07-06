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
os.environ.setdefault("AGENT_THREAD_MEMORY_DB", os.path.join(_TMP, "threads.db"))
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
    # Regresión bug real 2026-07-02 (respondía "ventas de hoy" en prod):
    ("cual es el producto que mas se ha vendido", "top_productos", None, ""),
    ("que producto se ha vendido mas este mes", "top_productos", None, "mes"),
    ("que es lo que mas se vende", "top_productos", None, ""),
    ("cuales productos se han vendido mas", "top_productos", None, ""),
    ("que se esta vendiendo mas", "top_productos", None, ""),
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
    # Exportaciones -> Excel al correo registrado (ANTES que ventas: "excel de
    # ventas" no debe responder totales)
    ("enviame un excel del maestro de productos completo al correo", "exportar", "productos", None),
    ("exporta las ventas del mes", "exportar", "ventas", "mes"),
    ("mandame la planilla de clientes", "exportar", "clientes", None),
    ("enviame el maestro de productos al correo", "exportar", "productos", None),
    ("excel del stock", "exportar", "stock", None),
    ("exportar compras de la semana", "exportar", "compras", "semana"),
    ("csv de proveedores", "exportar", "proveedores", None),
    # Informe cruzado vendidos+stock (pedido real 2026-07-04); gana a
    # "productos" y "ventas" sueltos por el orden de _EXPORT_TIPOS
    ("enviame al correo los productos vendidos del mes pasado con su stock", "exportar", "ventas_productos", "mes_anterior"),
    ("exporta lo vendido de la semana", "exportar", "ventas_productos", "semana"),
    ("enviame un excel al correo", "exportar", "", None),  # sin tema -> menú de tipos
    # Acciones -> guía manual (el agente es de solo lectura, nunca ejecuta)
    ("anula la venta 1543", "accion", None, None),
    ("cierra la caja 2", "accion", None, None),
    ("emite una nota de credito", "accion", None, None),
    ("cambia el precio del pan a 1500", "accion", None, None),
    ("ajusta el stock del aceite", "accion", None, None),
    ("elimina el cliente juan", "accion", None, None),
    # No capturables por reglas -> clasificador/agente (regla de humildad:
    # ante ambigüedad, las reglas se abstienen y decide la IA)
    ("hola buenas tardes", None, None, None),
    ("cual es el producto mas economico", None, None, None),
    # "producto"+"ventas" sin marcador claro: NO debe responder totales de
    # ventas; se abstiene y lo clasifica Gemini
    ("cual es el producto estrella en ventas", None, None, None),
    # Petición compleja multi-parte: "stock de" NO debe convertirla en
    # búsqueda de producto con la frase entera como query (bug 2026-07-04)
    ("enviame un resumen de los productos vendidos el mes pasado, incluye el stock de esos productos y cuanto vendimos", None, None, None),
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


# ─── 5. Memoria de hilo y seguimientos ───────────────────────────────────────

def test_thread_memory() -> None:
    print("6. Memoria de hilo (thread_memory)...")
    import thread_memory

    thread_memory.save("t-abc", "ventas", query="", periodo="hoy")
    loaded = thread_memory.load("t-abc")
    check("save/load roundtrip", loaded is not None and loaded["intent"] == "ventas"
          and loaded["periodo"] == "hoy")
    check("hilo desconocido devuelve None", thread_memory.load("t-nope") is None)
    check("expirada devuelve None (ttl=0)", thread_memory.load("t-abc", ttl=0) is None)

    # Persistido en SQLite -> visible entre workers
    import sqlite3
    con = sqlite3.connect(os.environ["AGENT_THREAD_MEMORY_DB"])
    row = con.execute("SELECT intent FROM hilos WHERE thread_id='t-abc'").fetchone()
    con.close()
    check("persistida en SQLite", row is not None and row[0] == "ventas")
    print()


def test_followup_detection() -> None:
    print("7. Detección de seguimientos ('¿y ayer?')...")
    from main import _followup_target, _normalize_text

    ventas = {"intent": "ventas", "query": "", "periodo": "hoy"}
    top = {"intent": "top_productos", "query": "", "periodo": "mes"}
    top_ant = {"intent": "top_productos", "query": "", "periodo": "mes_anterior"}
    producto = {"intent": "producto", "query": "pan", "periodo": ""}

    cases = [
        # (mensaje, memoria, esperado (intent, query, periodo, top))
        ("¿y ayer?", ventas, ("ventas", "", "ayer", None)),
        ("y el mes pasado", ventas, ("ventas", "", "mes_anterior", None)),
        ("y la semana", top, ("top_productos", "", "semana", None)),
        ("ahora el mes", ventas, ("ventas", "", "mes", None)),
        ("hoy", ventas, ("ventas", "", "hoy", None)),
        # "top N" reusa el período recordado (bug real 2026-07-04)
        ("dame el top 2", top_ant, ("top_productos", "", "mes_anterior", 2)),
        ("top 5", top_ant, ("top_productos", "", "mes_anterior", 5)),
        ("muestrame el top 20 productos", top, ("top_productos", "", "mes", 20)),
        ("dame el top 3", ventas, None),                # memoria no es un ranking
        # No debe disparar:
        ("¿y ayer?", None, None),                       # sin memoria
        ("¿y ayer?", producto, None),                   # intent sin período
        ("cuanto vale el pan de ayer", ventas, None),   # trae contenido propio
        ("y las cajas", ventas, None),                  # sin período explícito
        ("dame el detalle completo de las ventas de ayer con margen", ventas, None),  # largo
    ]
    for msg, memoria, esperado in cases:
        result = _followup_target(_normalize_text(msg), memoria)
        check(f"'{msg}'", result == esperado, f"esperaba {esperado}, obtuve {result}")
    print()


# ─── 6. Skills respuesta_directa y scope por empresa ─────────────────────────

def test_skill_respuesta_directa_y_scope() -> None:
    print("8. Skills respuesta_directa + scope por empresa...")
    import json
    import shutil

    from config import settings
    import skill_engine

    tmp_dir = tempfile.mkdtemp(prefix="mypos_skills_scope_")
    original = settings.skills_path
    try:
        settings.skills_path = tmp_dir

        def write_skill(name: str, data: dict) -> None:
            with open(os.path.join(tmp_dir, name), "w", encoding="utf-8") as fh:
                json.dump(data, fh)

        write_skill("respuesta_global.json", {
            "id": "funciona_en_tablet", "status": "aprobada",
            "tipo": "respuesta_directa", "scope": "global",
            "patterns": ["funciona en tablet"],
            "respuesta": "Sí, MyPOS funciona en tablet desde el navegador.",
        })
        write_skill("respuesta_empresa24.json", {
            "id": "horario_elida", "status": "aprobada",
            "tipo": "respuesta_directa", "scope": "empresa:24",
            "patterns": ["horario de la carniceria"],
            "respuesta": "La carnicería atiende de 9 a 20 hrs.",
        })
        write_skill("respuesta_sin_texto.json", {
            "id": "invalida", "status": "aprobada",
            "tipo": "respuesta_directa",
            "patterns": ["skill invalida sin texto"],
            "respuesta": "",
        })

        r = skill_engine.match_skill("¿funciona en tablet?", empresa_id=5)
        check("global aplica a cualquier empresa",
              r is not None and r["tipo"] == "respuesta_directa"
              and "tablet" in r["respuesta"], str(r))

        r = skill_engine.match_skill("cual es el horario de la carniceria", empresa_id=24)
        check("scope empresa:24 aplica a la 24",
              r is not None and r["skill_id"] == "horario_elida", str(r))

        r = skill_engine.match_skill("cual es el horario de la carniceria", empresa_id=7)
        check("scope empresa:24 NO aplica a otra empresa", r is None, str(r))

        r = skill_engine.match_skill("cual es el horario de la carniceria", empresa_id=None)
        check("scope empresa sin empresa_id no aplica", r is None, str(r))

        r = skill_engine.match_skill("skill invalida sin texto", empresa_id=24)
        check("respuesta_directa sin texto se descarta", r is None, str(r))
    finally:
        settings.skills_path = original
        shutil.rmtree(tmp_dir, ignore_errors=True)
    print()


# ─── 7. Capa 2.5: envelope y formateo de consultas dinámicas ─────────────────

def test_adhoc() -> None:
    print("9. Consultas dinámicas (adhoc): envelope y formateo...")
    from adhoc import construir_envelope, formatear_filas

    propuesta_ok = {
        "resoluble": True,
        "sql_template": "SELECT nombre, precio_venta FROM productos WHERE empresa_id = :empresa_id ORDER BY precio_venta DESC",
        "tablas_referenciadas": ["productos"],
        "params_permitidos": {"empresa_id": {"tipo": "int", "fuente": "contexto", "requerido": True}},
        "row_limit": 5,
        "patterns": ["producto mas caro"],
        "notes": "Productos ordenados por precio",
    }
    env = construir_envelope("cual es el producto mas caro", propuesta_ok)
    check("envelope válido construido",
          env is not None and env["tipo"] == "sql_readonly"
          and env["id"].startswith("adhoc_") and env["row_limit"] == 5
          and env["intent"] == "consulta_adhoc")

    check("no resoluble -> None",
          construir_envelope("x", {"resoluble": False, "notes": "no"}) is None)
    check("sin SQL -> None",
          construir_envelope("x", {"resoluble": True, "sql_template": ""}) is None)
    check("param extraido -> None (requiere aprobación humana)",
          construir_envelope("x", {**propuesta_ok, "params_permitidos": {
              "empresa_id": {"fuente": "contexto"},
              "producto_like": {"fuente": "extraido", "max_length": 60},
          }}) is None)
    env2 = construir_envelope("x", {**propuesta_ok, "row_limit": 9999})
    check("row_limit inválido -> 50", env2 is not None and env2["row_limit"] == 50)

    # Formateo
    r = formatear_filas(["total"], [{"total": 1234567}], False, "Total de ventas")
    check("una celda -> frase directa", "total: 1.234.567" in r and "Consulté" in r, r)

    filas = [{"nombre": f"P{i}", "precio_venta": 1000 + i} for i in range(15)]
    r = formatear_filas(["nombre", "precio_venta"], filas, True, "")
    check("máx 10 filas + resto anunciado", "10. " in r and "… y 5 fila(s) más" in r
          and "truncado" in r.lower(), r[:120])

    r = formatear_filas(["x"], [], False, "nota")
    check("sin filas -> mensaje claro", "no arrojó resultados" in r)
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
    test_thread_memory()
    test_followup_detection()
    test_skill_respuesta_directa_y_scope()
    test_adhoc()

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
