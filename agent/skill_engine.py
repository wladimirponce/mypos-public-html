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

        patterns = data.get("patterns")
        if not isinstance(patterns, list) or not patterns:
            return None

        if data.get("tipo") == "sql_readonly":
            # No se re-valida el SQL aqui a proposito: esa logica vive UNICA
            # en agent/sql_whitelist_validator.php (PHP) y el backend la
            # vuelve a correr en cada ejecucion (ConsultaFlexibleService).
            # Aqui solo se exige que el envelope tenga lo minimo para poder
            # despachar (id + sql_template no vacios).
            if not str(data.get("id") or "").strip():
                return None
            if not str(data.get("sql_template") or "").strip():
                return None
            return data

        if data.get("tipo") == "respuesta_directa":
            # Respuesta de texto curada por un humano en admin (botón
            # "Responder con IA" → aprobar → crear skill). No ejecuta nada:
            # patrones → texto fijo, servido sin gastar LLM.
            if not str(data.get("id") or "").strip():
                return None
            if not str(data.get("respuesta") or "").strip():
                return None
            return data

        intent = str(data.get("intent") or "").strip()
        tool = str(data.get("tool") or "").strip()
        if ALLOWED_TOOLS_BY_INTENT.get(intent) != tool:
            return None
        return data
    except Exception:
        return None


def _scope_allows(skill: dict, empresa_id: Optional[int]) -> bool:
    """
    Ámbito de la skill: sin campo `scope` (o "global") aplica a todas las
    empresas; "empresa:<id>" solo a esa empresa. Un scope malformado excluye
    la skill (fallar cerrado).
    """
    scope = str(skill.get("scope") or "global").strip().lower()
    if scope == "global":
        return True
    if scope.startswith("empresa:"):
        try:
            return empresa_id is not None and int(scope.split(":", 1)[1]) == int(empresa_id)
        except (TypeError, ValueError):
            return False
    return False


def match_skill(message: str, empresa_id: Optional[int] = None) -> Optional[dict]:
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
        if not _scope_allows(skill, empresa_id):
            continue
        for pattern in skill.get("patterns", []):
            normalized_pattern = _normalize(str(pattern))
            if not normalized_pattern or normalized_pattern not in text:
                continue

            if skill.get("tipo") == "sql_readonly":
                return {
                    "tipo": "sql_readonly",
                    "skill_id": str(skill.get("id") or name),
                }

            if skill.get("tipo") == "respuesta_directa":
                return {
                    "tipo": "respuesta_directa",
                    "respuesta": str(skill.get("respuesta") or ""),
                    "skill_id": str(skill.get("id") or name),
                }

            params = skill.get("params") if isinstance(skill.get("params"), dict) else {}
            return {
                "tipo": "tool",
                "intent": str(skill.get("intent") or ""),
                "query": str(params.get("query") or ""),
                "periodo": str(params.get("periodo_default") or ""),
                "skill_id": str(skill.get("id") or name),
            }
    return None
