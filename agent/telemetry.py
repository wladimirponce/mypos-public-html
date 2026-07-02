"""
Telemetría del agente: una línea JSON por consulta atendida.

Registra qué capa resolvió cada consulta (reglas, skill, clasificador,
grafo, grafo_fallback, cuota, error), la latencia y si la respuesta fue
útil. Es la base para decidir qué reglas conservar, qué skills podar y
cuánto tráfico llega realmente al LLM (Fase 0/5 del plan, ver
docs/AGENTE_PLAN_MEJORA.md).

Formato: JSONL en tmp/agent_metrics.jsonl con rotación simple por tamaño
(al superar el límite se renombra a .1 y se empieza de nuevo; se conserva
una sola generación). Nunca lanza excepciones: la telemetría jamás debe
romper el chat operativo.
"""

from __future__ import annotations

import json
import os
from datetime import datetime

from config import settings

_MAX_BYTES = 5 * 1024 * 1024  # ~5 MB por archivo, una generación de respaldo
_MESSAGE_LIMIT = 200


def _path() -> str:
    path = settings.metrics_log_path
    if not os.path.isabs(path):
        path = os.path.join(os.path.dirname(__file__), path)
    return path


def _rotate_if_needed(path: str) -> None:
    try:
        if os.path.isfile(path) and os.path.getsize(path) > _MAX_BYTES:
            backup = path + ".1"
            if os.path.isfile(backup):
                os.remove(backup)
            os.replace(path, backup)
    except Exception:
        pass


def record(
    layer: str,
    empresa_id: int,
    thread_id: str,
    latency_ms: int,
    ok: bool,
    message: str = "",
    reply_len: int = 0,
) -> None:
    """Registra una consulta atendida. Silencioso ante cualquier error."""
    try:
        path = _path()
        directory = os.path.dirname(path)
        if directory:
            os.makedirs(directory, exist_ok=True)
        _rotate_if_needed(path)

        entry = {
            "ts": datetime.now().isoformat(timespec="seconds"),
            "layer": layer,
            "empresa_id": empresa_id,
            "thread_id": thread_id,
            "latency_ms": latency_ms,
            "ok": ok,
            "message": (message or "")[:_MESSAGE_LIMIT],
            "reply_len": reply_len,
        }
        line = json.dumps(entry, ensure_ascii=False)
        with open(path, "a", encoding="utf-8") as fh:
            fh.write(line + "\n")
    except Exception:
        return
