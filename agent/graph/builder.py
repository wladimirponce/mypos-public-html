"""
Construcción del grafo LangGraph para el agente MyPOS.

El modelo LLM se instancia vía init_chat_model() de LangChain,
que acepta cualquier proveedor soportado (Anthropic, OpenAI, Google, Ollama…)
con la misma interfaz: bind_tools() funciona igual para todos.

Flujo:
  START → assistant → tools → assistant → ... → END
                    ↘ escalate (pausa) → assistant → END
"""

import os
from langchain.chat_models import init_chat_model
from langchain_core.messages import SystemMessage, ToolMessage
from langgraph.graph import StateGraph, END
from langgraph.prebuilt import ToolNode

from config import settings
from graph.state import AgentState
from tools import ALL_TOOLS

# ─── System prompt ────────────────────────────────────────────────────────────

_SYSTEM = """\
Eres el asistente interno de MyPOS, sistema de punto de venta chileno.
Ayudas a los operadores a consultar información de su negocio.

CAPACIDADES (sin aprobación):
- Ventas del día y por período, top productos
- Stock disponible por producto y sucursal
- Folios SII disponibles y alertas de CAF
- Estado de cajas y cierres diarios pendientes

REGLAS:
1. Responde siempre en español, de forma concisa y directa.
2. Para cualquier acción que MODIFIQUE datos (anular venta, emitir DTE,
   cerrar caja, ajustar stock, cambiar configuración) usa primero
   `solicitar_aprobacion_humana`. Nunca ejecutes sin aprobación.
3. Pasa siempre el empresa_id del operador a las herramientas sin modificarlo.

CONTEXTO SII CHILE:
- Boleta electrónica tipo 39 · Factura tipo 33 · Nota de crédito tipo 61.
- Folios agotados no se reutilizan. Menos de 150 boletas = urgente.

Operador: {operator_name} · empresa_id: {empresa_id}
"""

# ─── Nodos ────────────────────────────────────────────────────────────────────

def _make_assistant(model):
    def assistant(state: AgentState) -> dict:
        system = SystemMessage(
            content=_SYSTEM.format(
                operator_name=state.get("operator_name") or "operador",
                empresa_id=state["empresa_id"],
            )
        )
        response = model.invoke([system] + state["messages"])
        return {"messages": [response]}
    return assistant


def _escalate_node(state: AgentState) -> dict:
    """
    LangGraph pausa ANTES de este nodo (interrupt_before=["escalate"]).
    Cuando el operador responde desde el panel, el grafo reanuda aquí
    con el estado actualizado (escalation_reason con la decisión).
    """
    last = state["messages"][-1]
    tool_call_id = ""
    for tc in getattr(last, "tool_calls", []):
        if tc["name"] == "solicitar_aprobacion_humana":
            tool_call_id = tc["id"]
            break

    human_reply = state.get("escalation_reason") or "Aprobado por supervisor."
    return {
        "escalated": False,
        "escalation_reason": "",
        "messages": [
            ToolMessage(content=f"Supervisor: {human_reply}", tool_call_id=tool_call_id)
        ],
    }


# ─── Router ───────────────────────────────────────────────────────────────────

def _route(state: AgentState) -> str:
    last = state["messages"][-1]
    calls = getattr(last, "tool_calls", None) or []
    if not calls:
        return END
    for tc in calls:
        if tc["name"] == "solicitar_aprobacion_humana":
            return "escalate"
    return "tools"


# ─── Build ────────────────────────────────────────────────────────────────────

async def build_graph():
    """Compila el grafo con checkpointer SQLite. Llamar una vez al arranque."""
    import aiosqlite
    from langgraph.checkpoint.sqlite.aio import AsyncSqliteSaver

    # init_chat_model acepta cualquier proveedor LangChain.
    # La API key la lee automáticamente de la variable de entorno estándar
    # (ANTHROPIC_API_KEY, OPENAI_API_KEY, GOOGLE_API_KEY…).
    provider_keys = {
        "anthropic": ("ANTHROPIC_API_KEY", settings.anthropic_api_key),
        "openai": ("OPENAI_API_KEY", settings.openai_api_key),
        "google_genai": ("GOOGLE_API_KEY", settings.google_api_key),
    }

    model_kwargs = {
        "model": settings.llm_model,
        "model_provider": settings.llm_provider,
        "timeout": 25,
        "max_retries": 1,
    }

    if settings.llm_provider in provider_keys:
        env_key, api_key = provider_keys[settings.llm_provider]
        if api_key:
            os.environ.setdefault(env_key, api_key)
            model_kwargs["api_key"] = api_key

    model = init_chat_model(**model_kwargs).bind_tools(ALL_TOOLS)

    workflow = StateGraph(AgentState)
    workflow.add_node("assistant", _make_assistant(model))
    workflow.add_node("tools", ToolNode(ALL_TOOLS))
    workflow.add_node("escalate", _escalate_node)

    workflow.set_entry_point("assistant")
    workflow.add_conditional_edges("assistant", _route)
    workflow.add_edge("tools", "assistant")
    workflow.add_edge("escalate", "assistant")

    # BUG FIX: dirname("agent.db") == "" → makedirs("") falla.
    # Usar el directorio padre solo si existe en el path.
    db_dir = os.path.dirname(settings.db_path)
    if db_dir:
        os.makedirs(db_dir, exist_ok=True)
    conn = await aiosqlite.connect(settings.db_path)
    checkpointer = AsyncSqliteSaver(conn)

    return workflow.compile(
        checkpointer=checkpointer,
        interrupt_before=["escalate"],
    )
