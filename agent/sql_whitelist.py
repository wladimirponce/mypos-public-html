"""
Lector de la whitelist maestra de tablas/columnas para skills sql_readonly.

La UNICA fuente de verdad es agent/sql_whitelist.json. Este modulo solo la
lee para poder describirla en el prompt del LLM que propone plantillas SQL
(ver tarea "Boton Generar SQL con IA"). La validacion/aplicacion real de la
whitelist vive en agent/sql_whitelist_validator.php, porque quien aprueba
(admin) y quien ejecuta (backend) son ambos PHP -- este modulo Python nunca
valida ni ejecuta SQL, solo lee datos para prompting.
"""

from __future__ import annotations

import json
import os
from typing import Any


def _whitelist_path() -> str:
    return os.path.join(os.path.dirname(__file__), "sql_whitelist.json")


def load_whitelist() -> dict[str, Any]:
    with open(_whitelist_path(), "r", encoding="utf-8") as fh:
        return json.load(fh)


def describe_schema_for_prompt() -> str:
    """Texto compacto tabla(columnas) para inyectar en el prompt del LLM."""
    data = load_whitelist()
    lines = []
    for table, meta in data.get("tables", {}).items():
        columns = ", ".join(meta.get("columns", []))
        tenant_col = meta.get("tenant_column")
        note = f" [filtro obligatorio: {tenant_col}]" if tenant_col else ""
        lines.append(f"- {table}({columns}){note}")
    return "\n".join(lines)
