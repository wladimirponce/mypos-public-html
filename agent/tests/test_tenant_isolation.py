"""
Test del aislamiento de tenant en el nodo de tools del grafo LLM (hallazgo A1).

Verifica que empresa_id/sucursal_id se fuerzan SIEMPRE desde el estado
autenticado y que un prompt injection que intente fijar otra empresa en los
argumentos de la tool no tiene efecto.
"""

import sys
import os

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from langchain_core.messages import AIMessage

from graph.builder import _force_tenant_args


def _tool_call(empresa_id, sucursal_id=None, extra=None):
    args = {"empresa_id": empresa_id}
    if sucursal_id is not None:
        args["sucursal_id"] = sucursal_id
    if extra:
        args.update(extra)
    return {"name": "ventas_periodo", "args": args, "id": "call_1"}


def test_override_empresa_id_malicioso():
    ai = AIMessage(content="", tool_calls=[_tool_call(empresa_id=999, sucursal_id=77)])
    _force_tenant_args([ai], empresa_id=24, sucursal_id=3)
    args = ai.tool_calls[0]["args"]
    assert args["empresa_id"] == 24, "empresa_id debe forzarse al del contexto"
    assert args["sucursal_id"] == 3, "sucursal_id debe forzarse al del contexto"


def test_no_inyecta_en_tools_sin_empresa_id():
    # guia_accion_manual no declara empresa_id: no debe agregarse.
    call = {"name": "guia_accion_manual", "args": {"tema": "anular"}, "id": "c2"}
    ai = AIMessage(content="", tool_calls=[call])
    _force_tenant_args([ai], empresa_id=24, sucursal_id=3)
    assert "empresa_id" not in ai.tool_calls[0]["args"]


def test_preserva_otros_argumentos():
    ai = AIMessage(content="", tool_calls=[_tool_call(999, extra={"periodo": "mes"})])
    _force_tenant_args([ai], empresa_id=10, sucursal_id=None)
    args = ai.tool_calls[0]["args"]
    assert args["empresa_id"] == 10
    assert args["periodo"] == "mes", "otros argumentos deben conservarse"


def test_sin_mensajes_no_falla():
    _force_tenant_args([], empresa_id=1, sucursal_id=None)  # no debe lanzar


if __name__ == "__main__":
    test_override_empresa_id_malicioso()
    test_no_inyecta_en_tools_sin_empresa_id()
    test_preserva_otros_argumentos()
    test_sin_mensajes_no_falla()
    print("OK: aislamiento de tenant (A1) — todos los casos pasaron")
