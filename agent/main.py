"""
FastAPI — agente MyPOS.
Corre en www.mypos.cl, expuesto vía Passenger (passenger_wsgi.py).

Endpoints:
  POST /chat                              → mensaje desde el dashboard React
  POST /escalaciones/{thread_id}/responder → operador aprueba/rechaza
  GET  /escalaciones                      → conversaciones pausadas
  GET  /health
"""

import uuid
from contextlib import asynccontextmanager
from typing import Optional

from fastapi import FastAPI, HTTPException, Security
from fastapi.security.api_key import APIKeyHeader
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel

from config import settings

_graph = None
_SECRET_HEADER = APIKeyHeader(name="X-Agent-Secret", auto_error=False)


# ─── Auth ─────────────────────────────────────────────────────────────────────

def _require_secret(key: Optional[str] = Security(_SECRET_HEADER)) -> None:
    if key != settings.agent_secret:
        raise HTTPException(status_code=401, detail="X-Agent-Secret inválido o ausente")


# ─── Lifespan ─────────────────────────────────────────────────────────────────

@asynccontextmanager
async def lifespan(app: FastAPI):
    global _graph
    from graph.builder import build_graph
    _graph = await build_graph()
    yield


# ─── App ──────────────────────────────────────────────────────────────────────

app = FastAPI(title="MyPOS Agent", lifespan=lifespan, docs_url=None, redoc_url=None)

# Solo el frontend de www.mypos.cl puede llamar al agente
app.add_middleware(
    CORSMiddleware,
    allow_origins=["https://www.mypos.cl"],
    allow_methods=["POST", "GET"],
    allow_headers=["Content-Type", "X-Agent-Secret"],
)


# ─── Schemas ──────────────────────────────────────────────────────────────────

class ChatRequest(BaseModel):
    message: str
    empresa_id: int
    sucursal_id: Optional[int] = None
    operator_name: str = ""
    thread_id: Optional[str] = None  # None = nueva conversación


class ChatResponse(BaseModel):
    thread_id: str
    reply: str
    escalated: bool = False


class EscalationReply(BaseModel):
    decision: str       # "aprobado" | "rechazado"
    comentario: str = ""


# ─── Helper ───────────────────────────────────────────────────────────────────

async def _run(
    message: str,
    empresa_id: int,
    thread_id: str,
    sucursal_id: Optional[int] = None,
    operator_name: str = "",
) -> ChatResponse:
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

    result = await _graph.ainvoke(state, config=config)

    snapshot = await _graph.aget_state(config)
    if "escalate" in (snapshot.next or []):
        last = result["messages"][-1]
        reason = ""
        for tc in getattr(last, "tool_calls", []):
            if tc["name"] == "solicitar_aprobacion_humana":
                reason = tc["args"].get("motivo", "")
                break
        return ChatResponse(
            thread_id=thread_id,
            reply=f"Esta acción requiere aprobación de un supervisor: {reason}",
            escalated=True,
        )

    last = result["messages"][-1]
    return ChatResponse(
        thread_id=thread_id,
        reply=getattr(last, "content", "") or "",
    )


# ─── Endpoints ────────────────────────────────────────────────────────────────

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
    """
    Retorna threads pausados esperando aprobación.
    Pendiente: implementar query directo sobre el SQLite del checkpointer.
    """
    return {"escalaciones": [], "nota": "implementar query sobre agent.db"}


@app.post("/escalaciones/{thread_id}/responder")
async def responder_escalacion(
    thread_id: str,
    body: EscalationReply,
    _: None = Security(_require_secret),
):
    config = {"configurable": {"thread_id": thread_id}}

    snapshot = await _graph.aget_state(config)
    if "escalate" not in (snapshot.next or []):
        raise HTTPException(404, "No hay escalación pendiente para este thread")

    # Actualizar el estado con la decisión del operador y reanudar
    await _graph.aupdate_state(
        config,
        {
            "escalation_reason": f"{body.decision}: {body.comentario}",
            "escalated": False,
        },
    )
    result = await _graph.ainvoke(None, config=config)
    last = result["messages"][-1]

    return {
        "thread_id": thread_id,
        "reply": getattr(last, "content", ""),
        "decision": body.decision,
    }


@app.get("/health")
async def health():
    return {"status": "ok", "agent": "MyPOS Agent", "host": "www.mypos.cl"}
