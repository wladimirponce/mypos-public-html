"""
FastAPI agent for MyPOS.

Endpoints:
  POST /chat
  GET  /escalaciones
  POST /escalaciones/{thread_id}/responder
  GET  /health
"""

import asyncio
import uuid
from typing import Optional

from fastapi import FastAPI, HTTPException, Request, Security
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from fastapi.security.api_key import APIKeyHeader
from pydantic import BaseModel

from config import settings

_graph = None
_SECRET_HEADER = APIKeyHeader(name="X-Agent-Secret", auto_error=False)
_RUN_TIMEOUT_SECONDS = 35


def _require_secret(key: Optional[str] = Security(_SECRET_HEADER)) -> None:
    if not settings.agent_secret:
        raise HTTPException(status_code=503, detail="AGENT_SECRET no configurado")
    if key != settings.agent_secret:
        raise HTTPException(status_code=401, detail="X-Agent-Secret invalido o ausente")


async def _get_graph():
    global _graph
    if _graph is not None:
        return _graph

    from graph.builder import build_graph

    _graph = await build_graph()
    return _graph


def _provider_ready() -> tuple[bool, str]:
    required_keys = {
        "anthropic": ("ANTHROPIC_API_KEY", settings.anthropic_api_key),
        "openai": ("OPENAI_API_KEY", settings.openai_api_key),
        "google_genai": ("GOOGLE_API_KEY", settings.google_api_key),
    }

    if settings.llm_provider == "ollama":
        return True, ""

    if settings.llm_provider not in required_keys:
        return False, f"LLM_PROVIDER no soportado: {settings.llm_provider}"

    key_name, key_value = required_keys[settings.llm_provider]
    if not key_value:
        return False, f"{key_name} no configurado para LLM_PROVIDER={settings.llm_provider}"

    return True, ""


app = FastAPI(title="MyPOS Agent", docs_url=None, redoc_url=None)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["https://www.mypos.cl", "https://mypos.cl"],
    allow_methods=["POST", "GET"],
    allow_headers=["Content-Type", "X-Agent-Secret"],
)


@app.exception_handler(Exception)
async def unhandled_exception_handler(request: Request, exc: Exception):
    detail = str(exc).strip() or exc.__class__.__name__
    if len(detail) > 240:
        detail = detail[:240] + "..."

    return JSONResponse(
        status_code=500,
        content={
            "detail": f"Error interno del agente IA: {detail}",
            "error_type": exc.__class__.__name__,
        },
    )


class ChatRequest(BaseModel):
    message: str
    empresa_id: int
    sucursal_id: Optional[int] = None
    operator_name: str = ""
    thread_id: Optional[str] = None


class ChatResponse(BaseModel):
    thread_id: str
    reply: str
    escalated: bool = False


class EscalationReply(BaseModel):
    decision: str
    comentario: str = ""


async def _run(
    message: str,
    empresa_id: int,
    thread_id: str,
    sucursal_id: Optional[int] = None,
    operator_name: str = "",
) -> ChatResponse:
    ready, reason = _provider_ready()
    if not ready:
        raise HTTPException(status_code=503, detail=reason)

    try:
        graph = await asyncio.wait_for(_get_graph(), timeout=10)
    except asyncio.TimeoutError as exc:
        raise HTTPException(
            status_code=504,
            detail="No se pudo inicializar el proveedor IA en 10 segundos",
        ) from exc
    config = {"configurable": {"thread_id": thread_id}}

    state = {
        "messages": [{"role": "user", "content": message}],
        "empresa_id": empresa_id,
        "sucursal_id": sucursal_id,
        "operator_name": operator_name,
        "channel": "web",
        "escalated": False,
        "escalation_reason": "",
    }

    try:
        result = await asyncio.wait_for(
            graph.ainvoke(state, config=config),
            timeout=_RUN_TIMEOUT_SECONDS,
        )
    except asyncio.TimeoutError as exc:
        raise HTTPException(
            status_code=504,
            detail=f"El proveedor IA no respondio en {_RUN_TIMEOUT_SECONDS} segundos",
        ) from exc

    snapshot = await graph.aget_state(config)
    if "escalate" in (snapshot.next or []):
        last = result["messages"][-1]
        reason = ""
        for tool_call in getattr(last, "tool_calls", []):
            if tool_call["name"] == "solicitar_aprobacion_humana":
                reason = tool_call["args"].get("motivo", "")
                break

        return ChatResponse(
            thread_id=thread_id,
            reply=f"Esta accion requiere aprobacion de un supervisor: {reason}",
            escalated=True,
        )

    last = result["messages"][-1]
    return ChatResponse(thread_id=thread_id, reply=getattr(last, "content", "") or "")


@app.post("/chat", response_model=ChatResponse)
async def chat(req: ChatRequest, _: None = Security(_require_secret)):
    thread_id = req.thread_id or f"web-{req.empresa_id}-{uuid.uuid4().hex[:8]}"
    return await _run(
        message=req.message,
        empresa_id=req.empresa_id,
        thread_id=thread_id,
        sucursal_id=req.sucursal_id,
        operator_name=req.operator_name,
    )


@app.get("/escalaciones")
async def listar_escalaciones(_: None = Security(_require_secret)):
    return {"escalaciones": [], "nota": "implementar query sobre agent.db"}


@app.post("/escalaciones/{thread_id}/responder")
async def responder_escalacion(
    thread_id: str,
    body: EscalationReply,
    _: None = Security(_require_secret),
):
    graph = await _get_graph()
    config = {"configurable": {"thread_id": thread_id}}

    snapshot = await graph.aget_state(config)
    if "escalate" not in (snapshot.next or []):
        raise HTTPException(404, "No hay escalacion pendiente para este thread")

    await graph.aupdate_state(
        config,
        {
            "escalation_reason": f"{body.decision}: {body.comentario}",
            "escalated": False,
        },
    )
    result = await graph.ainvoke(None, config=config)
    last = result["messages"][-1]

    return {
        "thread_id": thread_id,
        "reply": getattr(last, "content", ""),
        "decision": body.decision,
    }


@app.get("/health")
async def health():
    required_config = {
        "AGENT_SECRET": bool(settings.agent_secret),
        "MYPOS_SERVICE_EMAIL": bool(settings.mypos_service_email),
        "MYPOS_SERVICE_PASSWORD": bool(settings.mypos_service_password),
        "MYPOS_API_KEY": bool(settings.mypos_api_key),
    }
    provider_key_configured = {
        "anthropic": bool(settings.anthropic_api_key),
        "openai": bool(settings.openai_api_key),
        "google_genai": bool(settings.google_api_key),
        "ollama": True,
    }.get(settings.llm_provider, False)

    return {
        "status": "ok",
        "agent": "MyPOS Agent",
        "host": "www.mypos.cl",
        "model": settings.llm_model,
        "provider": settings.llm_provider,
        "provider_key_configured": provider_key_configured,
        "required_config": required_config,
        "graph_loaded": _graph is not None,
    }
