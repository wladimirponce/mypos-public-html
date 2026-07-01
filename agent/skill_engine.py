"""
Motor simple de skills JSON para el agente MyPOS.

Las skills son datos aprobados por admin: patrones, intent, herramienta y
parametros permitidos. No ejecutan codigo dinamico.
"""

from __future__ import annotations

import json
import os
import unicodedata
from typing import Optional

from config import settings

ALLOWED_TOOLS_BY_INTENT = {
    "ventas": "ventas_periodo",
    "top_productos": "ventas_por_producto",
    "stock_critico": "stock_critico",
    "reposicion": "sugerencias_reposicion",
    "cajas": "estado_cajas",
    "cierres": "cierres_pendientes",
    "iva": "resumen_iva",
    "compras": "compras_pendientes",
    "folios": "estado_folios_sii",
    "cliente": "buscar_cliente",
    "producto": "buscar_producto",
    "stock_producto": "buscar_producto",
    "ayuda": "ayuda",
}


def _base_dir() -> str:
    path = settings.skills_path
    if os.path.isabs(path):
        return path
    return os.path.join(os.path.dirname(__file__), path)


def _normalize(value: str) -> str:
    normalized = unicodedata.normalize("NFKD", value.lower())
    return "".join(ch for ch in normalized if not unicodedata.combining(ch)).strip()


def _load_skill(path: str) -> Optional[dict]:
    try:
        with open(path, "r", encoding="utf-8") as fh:
            data = json.load(fh)
        if not isinstance(data, dict):
            return None
        if data.get("status") not in ("aprobada", "activa"):
            return None
        intent = str(data.get("intent") or "").strip()
        tool = str(data.get("tool") or "").strip()
        if ALLOWED_TOOLS_BY_INTENT.get(intent) != tool:
            return None
        patterns = data.get("patterns")
        if not isinstance(patterns, list) or not patterns:
            return None
        return data
    except Exception:
        return None


def match_skill(message: str) -> Optional[dict]:
    skills_dir = _base_dir()
    if not os.path.isdir(skills_dir):
        return None

    text = _normalize(message)
    for name in sorted(os.listdir(skills_dir)):
        if not name.endswith(".json"):
            continue
        skill = _load_skill(os.path.join(skills_dir, name))
        if not skill:
            continue
        for pattern in skill.get("patterns", []):
            normalized_pattern = _normalize(str(pattern))
            if normalized_pattern and normalized_pattern in text:
                params = skill.get("params") if isinstance(skill.get("params"), dict) else {}
                return {
                    "intent": str(skill.get("intent") or ""),
                    "query": str(params.get("query") or ""),
                    "periodo": str(params.get("periodo_default") or ""),
                    "skill_id": str(skill.get("id") or name),
                }
    return None
