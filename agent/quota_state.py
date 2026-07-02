"""
Estado de cuota LLM compartido entre workers.

Passenger puede levantar varios procesos del agente; los cooldowns de cuota
(_quota_blocked_until, _provider_busy_until) y el intervalo mínimo entre
llamadas LLM vivían como globals de Python, así que cada worker tenía su
propia visión y los límites no se respetaban de forma consistente.

Este módulo persiste esos tres valores en un SQLite pequeño (clave→valor)
con escrituras atómicas. Si SQLite falla por cualquier motivo (permisos,
disco), degrada a un dict en memoria del proceso: el chat nunca se rompe
por el tracking de cuota.

Claves usadas:
  last_llm_call_at      → epoch de la última llamada al agente completo
  quota_blocked_until   → epoch hasta el que la cuota está agotada
  provider_busy_until   → epoch hasta el que el proveedor está saturado
"""

from __future__ import annotations

import os
import sqlite3
import time

from config import settings

_fallback: dict[str, float] = {}
_sqlite_broken = False


def _db_path() -> str:
    path = settings.quota_db_path
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
        "CREATE TABLE IF NOT EXISTS kv (key TEXT PRIMARY KEY, value REAL NOT NULL)"
    )
    return conn


def get_value(key: str) -> float:
    global _sqlite_broken
    if _sqlite_broken:
        return _fallback.get(key, 0.0)
    try:
        with _connect() as conn:
            row = conn.execute("SELECT value FROM kv WHERE key = ?", (key,)).fetchone()
        return float(row[0]) if row else 0.0
    except Exception:
        _sqlite_broken = True
        return _fallback.get(key, 0.0)


def set_value(key: str, value: float) -> None:
    global _sqlite_broken
    _fallback[key] = value
    if _sqlite_broken:
        return
    try:
        with _connect() as conn:
            conn.execute(
                "INSERT INTO kv (key, value) VALUES (?, ?) "
                "ON CONFLICT(key) DO UPDATE SET value = excluded.value",
                (key, value),
            )
    except Exception:
        _sqlite_broken = True


def try_reserve_slot(min_interval: float) -> tuple[bool, int]:
    """
    Intenta reservar un slot de llamada LLM respetando el intervalo mínimo.
    Devuelve (True, 0) si el slot quedó reservado, o (False, segundos_a_esperar).
    Atómico entre procesos vía BEGIN IMMEDIATE (lock de escritura de SQLite).
    """
    global _sqlite_broken
    now = time.time()

    if not _sqlite_broken:
        try:
            conn = _connect()
            try:
                conn.execute("BEGIN IMMEDIATE")
                row = conn.execute(
                    "SELECT value FROM kv WHERE key = 'last_llm_call_at'"
                ).fetchone()
                last = float(row[0]) if row else 0.0
                elapsed = now - last
                if elapsed < min_interval:
                    conn.rollback()
                    return False, max(1, int(min_interval - elapsed))
                conn.execute(
                    "INSERT INTO kv (key, value) VALUES ('last_llm_call_at', ?) "
                    "ON CONFLICT(key) DO UPDATE SET value = excluded.value",
                    (now,),
                )
                conn.commit()
                _fallback["last_llm_call_at"] = now
                return True, 0
            finally:
                conn.close()
        except Exception:
            _sqlite_broken = True

    # Fallback en memoria (mejor esfuerzo dentro del proceso)
    last = _fallback.get("last_llm_call_at", 0.0)
    elapsed = now - last
    if elapsed < min_interval:
        return False, max(1, int(min_interval - elapsed))
    _fallback["last_llm_call_at"] = now
    return True, 0
