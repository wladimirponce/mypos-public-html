"""
Herramienta de escalación a humano.
Cuando Claude la invoca, el grafo pausa (interrupt_before="escalate")
y espera que un operador apruebe o rechace desde el panel de escalaciones.
"""

from langchain_core.tools import tool


@tool
def solicitar_aprobacion_humana(motivo: str, accion_propuesta: str) -> str:
    """
    Solicita aprobación de un supervisor humano antes de ejecutar una acción sensible.
    Usar para: anular ventas, emitir DTEs, cerrar cajas, modificar stock, cambiar configuración.

    Esta herramienta PAUSA la conversación hasta que un operador autorizado responda.

    Args:
        motivo: Por qué se requiere aprobación (qué pidió el cliente).
        accion_propuesta: Qué haría el agente si se aprueba.
    """
    # El cuerpo no se ejecuta: el grafo intercepta esta tool_call
    # en el router y desvía al nodo "escalate" antes de llegar aquí.
    return f"Escalación registrada: {motivo}"
