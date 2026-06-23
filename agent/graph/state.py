from typing import Optional
from langgraph.graph import MessagesState


class AgentState(MessagesState):
    """
    Estado compartido entre todos los nodos del grafo.

    `messages` (heredado de MessagesState) acumula la conversación completa
    y es la única fuente de verdad que el checkpointer persiste por thread_id.

    Los campos de contexto (empresa_id, etc.) se inyectan al inicio de cada
    sesión desde el webhook o el chat widget y no cambian durante la conversación.
    """

    # BUG FIX: todos los campos necesitan default para que LangGraph
    # pueda hacer merge parcial de estado en nodos intermedios.
    empresa_id: int = 0
    sucursal_id: Optional[int] = None
    operator_name: str = ""
    channel: str = "web"
    escalated: bool = False
    escalation_reason: str = ""
