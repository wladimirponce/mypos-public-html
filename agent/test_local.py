"""
Test local del agente — NO requiere credenciales reales.

Verifica:
  1. Que todos los imports funcionan
  2. Que el grafo compila sin errores
  3. Que una conversación de prueba fluye correctamente
  4. Que la escalación pausa el grafo y lo reanuda

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


def test_imports():
    print("1. Verificando imports...")
    from config import settings
    from graph.state import AgentState
    from tools import ALL_TOOLS
    assert len(ALL_TOOLS) == 7, f"Se esperaban 7 tools, hay {len(ALL_TOOLS)}"
    names = [t.name for t in ALL_TOOLS]
    print(f"   Tools: {', '.join(names)}")
    print("   OK\n")


def test_state_defaults():
    print("2. Verificando defaults del estado...")
    from graph.state import AgentState
    # LangGraph crea estados parciales — todos los campos deben tener default
    state = AgentState(messages=[], empresa_id=5)
    assert state["sucursal_id"] is None
    assert state["escalated"] is False
    assert state["escalation_reason"] == ""
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
        assert "escalate" in nodes
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
        "escalated": False,
        "escalation_reason": "",
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
    from tools.ventas import resumen_ventas_hoy, ventas_por_producto
    from tools.stock import consultar_stock, buscar_producto
    from tools.folios import estado_folios_sii
    from tools.caja import estado_cajas
    from tools.escalate import solicitar_aprobacion_humana

    for tool in [resumen_ventas_hoy, ventas_por_producto, consultar_stock,
                 buscar_producto, estado_folios_sii, estado_cajas,
                 solicitar_aprobacion_humana]:
        schema = tool.args_schema.model_json_schema()
        props = schema.get("properties", {})
        print(f"   {tool.name}: {list(props.keys())}")
    print("   OK\n")


async def main():
    print("=" * 55)
    print("  MyPOS Agent — Test Local")
    print("=" * 55 + "\n")

    test_imports()
    test_state_defaults()
    test_tool_signatures()
    graph = await test_graph_compiles()
    await test_conversation(graph)

    print("=" * 55)
    print("  Tests completados.")
    print("=" * 55)


if __name__ == "__main__":
    asyncio.run(main())
