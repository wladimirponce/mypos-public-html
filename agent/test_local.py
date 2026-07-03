"""
Test local del agente — NO requiere credenciales reales.

Verifica:
  1. Que todos los imports funcionan
  2. Que el grafo compila sin errores
  3. Que una conversación de prueba fluye correctamente
  4. Que el grafo solo tiene nodos de lectura (assistant + tools)

Uso:
  pip install -r requirements.txt
  pip install langchain-anthropic   # o el proveedor que uses
  python test_local.py
"""

import asyncio
import os

# Inyectar variables de entorno mínimas para el test (sin hits reales a APIs)
os.environ.setdefault("LLM_MODEL", "claude-opus-4-8")
os.environ.setdefault("LLM_PROVIDER", "anthropic")
os.environ.setdefault("ANTHROPIC_API_KEY", "sk-ant-test-fake-key")
os.environ.setdefault("MYPOS_WEB_URL", "http://127.0.0.1/api")
os.environ.setdefault("MYPOS_SERVICE_EMAIL", "test@test.cl")
os.environ.setdefault("MYPOS_SERVICE_PASSWORD", "test")
os.environ.setdefault("MYPOS_ADMIN_URL", "http://127.0.0.1/admin/api.php")
os.environ.setdefault("MYPOS_FB_URL", "http://127.0.0.1/admin/fb")
os.environ.setdefault("MYPOS_API_KEY", "test-api-key")
os.environ.setdefault("AGENT_SECRET", "test-secret")
os.environ.setdefault("AGENT_DB", ":memory:")  # SQLite en memoria, sin archivo

_EXPECTED_TOOLS = 14


def test_imports():
    print("1. Verificando imports...")
    from config import settings
    from graph.state import AgentState
    from tools import ALL_TOOLS
    assert len(ALL_TOOLS) == _EXPECTED_TOOLS, (
        f"Se esperaban {_EXPECTED_TOOLS} tools, hay {len(ALL_TOOLS)}"
    )
    names = [t.name for t in ALL_TOOLS]
    print(f"   Tools ({len(names)}): {', '.join(names)}")
    print("   OK\n")


def test_state_defaults():
    print("2. Verificando defaults del estado...")
    from graph.state import AgentState
    state = AgentState(messages=[], empresa_id=5)
    assert state.get("empresa_id") == 5
    print("   OK\n")


async def test_graph_compiles():
    print("3. Compilando grafo (requiere paquete del proveedor LLM)...")
    try:
        from graph.builder import build_graph
        graph = await build_graph()
        nodes = list(graph.nodes.keys())
        print(f"   Nodos: {nodes}")
        assert "assistant" in nodes
        assert "tools" in nodes
        assert "escalate" not in nodes, "El nodo escalate fue eliminado (agente solo lectura)"
        print("   OK\n")
        return graph
    except ImportError as e:
        print(f"   SKIP: falta paquete del proveedor — {e}")
        print("   Instala: pip install langchain-anthropic  (o el de tu proveedor)\n")
        return None
    except Exception as e:
        print(f"   ERROR: {e}\n")
        return None


async def test_conversation(graph):
    if graph is None:
        print("4. SKIP conversación (grafo no disponible)\n")
        return

    print("4. Prueba de conversación (hará llamada real al LLM si la key es válida)...")
    from langchain_core.messages import HumanMessage

    config = {"configurable": {"thread_id": "test-001"}}
    state = {
        "messages": [HumanMessage(content="Hola, ¿qué puedes hacer?")],
        "empresa_id": 1,
        "sucursal_id": None,
        "operator_name": "Tester",
        "channel": "web",
    }

    try:
        result = await graph.ainvoke(state, config=config)
        last = result["messages"][-1]
        reply = getattr(last, "content", "")[:120]
        print(f"   Respuesta: {reply}...")
        print("   OK\n")
    except Exception as e:
        print(f"   ERROR (esperado si la key es falsa): {e}\n")


def test_tool_signatures():
    print("5. Verificando firmas de tools...")
    from tools import ALL_TOOLS
    for tool in ALL_TOOLS:
        schema = tool.args_schema.model_json_schema()
        props = schema.get("properties", {})
        print(f"   {tool.name}: {list(props.keys())}")
    print("   OK\n")


def test_direct_intents():
    """Verifica que los detectores de intent funcionan para frases comunes."""
    print("6. Verificando detección de intents directos...")
    import unicodedata
    import sys
    sys.path.insert(0, ".")
    from main import (
        _normalize_text, _is_sales_query, _is_top_products_query,
        _is_boxes_query, _is_closures_query, _is_stock_critical_query,
        _is_client_query, _is_iva_query, _is_purchase_query,
        _is_restock_query, _is_folios_query, _detect_period,
    )

    cases = [
        ("cuanto vendimos hoy", _is_sales_query, "hoy"),
        ("ventas de ayer", _is_sales_query, "ayer"),
        ("ventas del mes", _is_sales_query, "mes"),
        ("ventas de la semana", _is_sales_query, "semana"),
        ("estado de las cajas", _is_boxes_query, None),
        ("stock bajo", _is_stock_critical_query, None),
        ("que productos se estan agotando", _is_stock_critical_query, None),
        ("busca al cliente juan perez", _is_client_query, None),
        ("cuanto iva llevamos este mes", _is_iva_query, None),
        ("compras pendientes", _is_purchase_query, None),
        ("que me sugiere reponer", _is_restock_query, None),
        ("cuantos folios quedan", _is_folios_query, None),
        ("cierres pendientes", _is_closures_query, None),
        ("mas vendidos del mes", _is_top_products_query, None),
    ]

    ok = 0
    for phrase, detector, expected_period in cases:
        n = _normalize_text(phrase)
        result = detector(n)
        period_ok = expected_period is None or _detect_period(n) == expected_period
        status = "OK" if result and period_ok else "FAIL"
        print(f"   [{status}] '{phrase}' -> {detector.__name__}")
        if status == "OK":
            ok += 1
    print(f"   {ok}/{len(cases)} intents detectados correctamente\n")


def test_natural_product_queries():
    """Productos preguntados en lenguaje natural deben resolverse SIN IA."""
    print("7. Verificando consultas naturales de producto (router ampliado)...")
    import sys
    sys.path.insert(0, ".")
    from main import _normalize_text, _detect_intent_rules

    cases = [
        ("que tipos de carne tiene?", "producto", "carne"),
        ("tienen coca cola?", "producto", "coca cola"),
        ("hay pan?", "producto", "pan"),
        ("que carnes hay", "producto", "carnes"),
        ("venden cigarros", "producto", "cigarros"),
        ("muestrame las bebidas", "producto", "bebidas"),
        ("que puedes hacer", "ayuda", ""),
        ("hola buenas", None, ""),
    ]

    ok = 0
    for phrase, exp_intent, exp_query in cases:
        intent, query, _ = _detect_intent_rules(_normalize_text(phrase), phrase)
        intent_ok = intent == exp_intent
        query_ok = (not exp_query) or (query == exp_query)
        status = "OK" if intent_ok and query_ok else "FAIL"
        print(f"   [{status}] '{phrase}' -> intent={intent} query={query!r}")
        if status == "OK":
            ok += 1
    print(f"   {ok}/{len(cases)} consultas naturales resueltas por reglas\n")


def test_skill_engine_sql_readonly():
    """Verifica match_skill() con skills sql_readonly y con el formato legacy intent+tool."""
    import json
    import shutil
    import tempfile

    print("8. Verificando skill_engine con skills sql_readonly...")
    from config import settings
    import skill_engine

    tmp_dir = tempfile.mkdtemp(prefix="mypos_skills_test_")
    original_skills_path = settings.skills_path
    try:
        settings.skills_path = tmp_dir

        skill_sql = {
            "id": "producto_mas_economico_test",
            "status": "aprobada",
            "tipo": "sql_readonly",
            "intent": "producto_mas_economico",
            "patterns": ["cual es el producto mas economico"],
            "sql_template": "SELECT nombre FROM productos WHERE empresa_id = :empresa_id ORDER BY precio_venta ASC LIMIT 1",
            "row_limit": 1,
        }
        with open(os.path.join(tmp_dir, "sql_readonly_test.json"), "w", encoding="utf-8") as fh:
            json.dump(skill_sql, fh)

        skill_legacy = {
            "id": "ventas_hoy_test",
            "status": "aprobada",
            "intent": "ventas",
            "tool": "ventas_periodo",
            "patterns": ["como van las ventas de hoy"],
            "params": {"periodo_default": "hoy"},
        }
        with open(os.path.join(tmp_dir, "legacy_test.json"), "w", encoding="utf-8") as fh:
            json.dump(skill_legacy, fh)

        skill_malformada = {
            "id": "malformada_test",
            "status": "aprobada",
            "tipo": "sql_readonly",
            "patterns": ["esto no deberia matchear nunca de verdad 12345"],
            "sql_template": "",  # vacio -> debe ser rechazada por _load_skill
            "row_limit": 1,
        }
        with open(os.path.join(tmp_dir, "malformada_test.json"), "w", encoding="utf-8") as fh:
            json.dump(skill_malformada, fh)

        cases = [
            ("¿Cual es el producto mas economico?", "sql_readonly", "producto_mas_economico_test"),
            ("Como van las ventas de hoy", "tool", None),
            ("esto no deberia matchear nunca de verdad 12345", None, None),
            ("hola, buenas tardes", None, None),
        ]

        ok = 0
        for phrase, exp_tipo, exp_skill_id in cases:
            result = skill_engine.match_skill(phrase)
            tipo = result.get("tipo") if result else None
            status = "OK" if tipo == exp_tipo else "FAIL"
            if exp_skill_id is not None and result:
                if result.get("skill_id") != exp_skill_id:
                    status = "FAIL"
            print(f"   [{status}] '{phrase}' -> {result}")
            if status == "OK":
                ok += 1
        print(f"   {ok}/{len(cases)} casos OK\n")
        assert ok == len(cases), "Fallaron casos de skill_engine"
    finally:
        settings.skills_path = original_skills_path
        shutil.rmtree(tmp_dir, ignore_errors=True)


async def main():
    print("=" * 55)
    print("  MyPOS Agent — Test Local")
    print("=" * 55 + "\n")

    test_imports()
    test_state_defaults()
    test_tool_signatures()
    test_direct_intents()
    test_natural_product_queries()
    test_skill_engine_sql_readonly()
    graph = await test_graph_compiles()
    await test_conversation(graph)

    print("=" * 55)
    print("  Tests completados.")
    print("=" * 55)


if __name__ == "__main__":
    asyncio.run(main())
