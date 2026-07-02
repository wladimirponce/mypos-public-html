"""
Construcción del grafo LangGraph para el agente MyPOS.

El modelo LLM se instancia vía init_chat_model() de LangChain,
que acepta cualquier proveedor soportado (Anthropic, OpenAI, Google, Ollama…)
con la misma interfaz: bind_tools() funciona igual para todos.

Flujo:
  START → assistant → tools → assistant → ... → END

El agente es de SOLO LECTURA: no existe ningún nodo de acciones ni de
escalación. Las peticiones de modificación se responden con la guía del
procedimiento manual (tool `guia_accion_manual`).
"""

import os
from langchain.chat_models import init_chat_model
from langchain_core.messages import SystemMessage
from langgraph.graph import StateGraph, END
from langgraph.prebuilt import ToolNode

from config import settings
from graph.state import AgentState
from tools import ALL_TOOLS

# ─── System prompt ────────────────────────────────────────────────────────────

_SYSTEM = """\
Eres el asistente interno de MyPOS, sistema de punto de venta chileno.
Ayudas a los operadores a consultar información de su negocio en tiempo real.
Eres de SOLO LECTURA: jamás modificas datos del sistema.

CAPACIDADES DE CONSULTA:
VENTAS
- ventas_periodo         → ventas de hoy / ayer / semana / mes / mes_anterior
- ventas_por_producto    → ranking de productos más vendidos (top N configurable)

STOCK Y PRODUCTOS
- buscar_producto        → precio y stock por nombre, código o código de barras
- consultar_stock        → detalle de stock por ubicación (acepta nombre o ID)
- stock_critico          → productos en nivel de alerta o agotados

CLIENTES
- buscar_cliente         → buscar por nombre, RUT o email

OPERACIÓN DIARIA
- estado_cajas           → cajas abiertas / cerradas por sucursal
- cierres_pendientes     → cajas sin cierre diario al día de hoy

COMPRAS Y REPOSICIÓN
- compras_pendientes     → órdenes de compra en estado pendiente o borrador
- sugerencias_reposicion → qué productos reponer según historial de consumo

FINANZAS
- resumen_iva            → IVA débito / crédito / a pagar del mes

SII
- estado_folios_sii      → folios disponibles por tipo de DTE y alertas de CAF

ACCIONES (NO las ejecutas)
- guia_accion_manual     → guía del procedimiento manual en MyPOS

REGLAS IMPORTANTES:
1. Responde siempre en español, de forma concisa y directa.
2. Usa siempre el empresa_id del operador sin modificarlo.
3. NUNCA modificas datos (anular venta, emitir DTE, cerrar caja, ajustar
   stock, cambiar precios, configuración). Si el operador pide algo así,
   usa `guia_accion_manual` y explica dónde hacerlo manualmente en MyPOS.
4. Si el usuario pide un rango de fechas libre (ej. "del 5 al 20 de mayo"),
   usa ventas_por_producto o ventas_periodo con las fechas explícitas.
5. Si la consulta es ambigua, haz UNA pregunta corta para aclarar.

CONTEXTO SII CHILE:
- Boleta electrónica tipo 39 · Factura tipo 33 · Nota de crédito tipo 61.
- Folios agotados NO se reutilizan. Menos de 150 boletas = urgente recargar.

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


# ─── Router ───────────────────────────────────────────────────────────────────

def _route(state: AgentState) -> str:
    last = state["messages"][-1]
    calls = getattr(last, "tool_calls", None) or []
    return "tools" if calls else END


# ─── Build ────────────────────────────────────────────────────────────────────

def _model_kwargs(llm_provider: str, llm_model: str) -> dict:
    provider_keys = {
        "anthropic": ("ANTHROPIC_API_KEY", settings.anthropic_api_key),
        "openai": ("OPENAI_API_KEY", settings.openai_api_key),
        "google_genai": ("GOOGLE_API_KEY", settings.google_api_key),
        "grok": ("GROK_API_KEY", settings.grok_api_key),
    }

    model_provider = "openai" if llm_provider == "grok" else llm_provider
    model_kwargs = {
        "model": llm_model,
        "model_provider": model_provider,
        "timeout": 25,
        "max_retries": 1,
    }

    if llm_provider in provider_keys:
        env_key, api_key = provider_keys[llm_provider]
        if api_key:
            os.environ.setdefault(env_key, api_key)
            model_kwargs["api_key"] = api_key

    if llm_provider == "grok":
        model_kwargs["base_url"] = settings.grok_api_base

    return model_kwargs


async def build_graph(llm_provider: str | None = None, llm_model: str | None = None):
    """Compila el grafo con checkpointer SQLite. Llamar una vez al arranque."""
    import aiosqlite
    from langgraph.checkpoint.sqlite.aio import AsyncSqliteSaver

    # init_chat_model acepta cualquier proveedor LangChain.
    # La API key la lee automáticamente de la variable de entorno estándar
    # (ANTHROPIC_API_KEY, OPENAI_API_KEY, GOOGLE_API_KEY…).
    model = init_chat_model(
        **_model_kwargs(
            llm_provider or settings.llm_provider,
            llm_model or settings.llm_model,
        )
    ).bind_tools(ALL_TOOLS)

    workflow = StateGraph(AgentState)
    workflow.add_node("assistant", _make_assistant(model))
    workflow.add_node("tools", ToolNode(ALL_TOOLS))

    workflow.set_entry_point("assistant")
    workflow.add_conditional_edges("assistant", _route)
    workflow.add_edge("tools", "assistant")

    # BUG FIX: dirname("agent.db") == "" → makedirs("") falla.
    # Usar el directorio padre solo si existe en el path.
    db_dir = os.path.dirname(settings.db_path)
    if db_dir:
        os.makedirs(db_dir, exist_ok=True)
    conn = await aiosqlite.connect(settings.db_path)
    checkpointer = AsyncSqliteSaver(conn)

    return workflow.compile(checkpointer=checkpointer)
