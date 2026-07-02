"""
Memoria ligera por hilo de conversación (Fase 2 del plan).

Guarda el último intent resuelto (con su query y período) por thread_id,
para que las capas 1-2 puedan responder seguimientos como "¿y ayer?" tras
"ventas de hoy" sin pasar por el LLM. Complementa al checkpointer de
LangGraph (que solo aplica a la capa 3): aquí vive el contexto mínimo que
necesita el router de reglas.

SQLite compartido entre workers (mismo patrón que quota_state.py); si falla,
degrada a memoria del proceso — la memoria de hilo nunca rompe el chat.
Las entradas expiran a los 30 minutos: un seguimiento de ayer no debe
contaminar la conversación de hoy.
"""

from __future__ import annotations

import os
import sqlite3
import time
from typing import Optional

from config import settings

TTL_SECONDS = 30 * 60

_fallback: dict[str, dict] = {}
_sqlite_broken = False


def _db_path() -> str:
    path = settings.thread_memory_db_path
    if not os.path.isabs(path):
        path = os.path.join(os.path.dirname(__file__), path)
    return path


def _connect() -> sqlite3.Connection:
    path = _db_path()
    directory = os.path.dirname(path)
    if directory:
        os.makedirs(directory, exist_ok=True)
    conn = sqlite3.connect(path, timeout=5)
    conn.execute(
        "CREATE TABLE IF NOT EXISTS hilos ("
        " thread_id TEXT PRIMARY KEY,"
        " intent TEXT NOT NULL,"
        " query TEXT NOT NULL DEFAULT '',"
        " periodo TEXT NOT NULL DEFAULT '',"
        " ts REAL NOT NULL)"
    )
    return conn


def save(thread_id: str, intent: str, query: str = "", periodo: str = "") -> None:
    global _sqlite_broken
    if not thread_id or not intent:
        return
    entry = {"intent": intent, "query": query or "", "periodo": periodo or "", "ts": time.time()}
    _fallback[thread_id] = entry
    if _sqlite_broken:
        return
    try:
        with _connect() as conn:
            conn.execute(
                "INSERT INTO hilos (thread_id, intent, query, periodo, ts)"
                " VALUES (?, ?, ?, ?, ?)"
                " ON CONFLICT(thread_id) DO UPDATE SET"
                " intent = excluded.intent, query = excluded.query,"
                " periodo = excluded.periodo, ts = excluded.ts",
                (thread_id, entry["intent"], entry["query"], entry["periodo"], entry["ts"]),
            )
    except Exception:
        _sqlite_broken = True


def load(thread_id: str, ttl: float = TTL_SECONDS) -> Optional[dict]:
    """Devuelve {'intent','query','periodo'} si hay memoria fresca, o None."""
    global _sqlite_broken
    if not thread_id:
        return None

    entry: Optional[dict] = None
    if not _sqlite_broken:
        try:
            with _connect() as conn:
                row = conn.execute(
                    "SELECT intent, query, periodo, ts FROM hilos WHERE thread_id = ?",
                    (thread_id,),
                ).fetchone()
            if row:
                entry = {"intent": row[0], "query": row[1], "periodo": row[2], "ts": row[3]}
        except Exception:
            _sqlite_broken = True

    if entry is None:
        entry = _fallback.get(thread_id)
    if entry is None:
        return None
    if time.time() - float(entry["ts"]) > ttl:
        return None
    return {"intent": entry["intent"], "query": entry["query"], "periodo": entry["periodo"]}
