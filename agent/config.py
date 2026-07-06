from pydantic_settings import BaseSettings
from pydantic import Field


class Settings(BaseSettings):
    # ── LLM (cualquier proveedor soportado por LangChain) ─────────────────────
    # Ejemplos de modelo:
    #   "claude-opus-4-8"      → provider "anthropic"  → requiere ANTHROPIC_API_KEY
    #   "gpt-4o"               → provider "openai"     → requiere OPENAI_API_KEY
    #   "gemini-2.5-flash"     → provider "google_genai" → requiere GOOGLE_API_KEY
    #   "llama3"               → provider "ollama"     → sin API key (local)
    llm_model: str = Field(default="claude-opus-4-8", alias="LLM_MODEL")
    llm_provider: str = Field(default="anthropic", alias="LLM_PROVIDER")

    # Claves de los proveedores — solo la del proveedor activo es obligatoria
    anthropic_api_key: str = Field(default="", alias="ANTHROPIC_API_KEY")
    openai_api_key: str = Field(default="", alias="OPENAI_API_KEY")
    google_api_key: str = Field(default="", alias="GOOGLE_API_KEY")
    grok_api_key: str = Field(default="", alias="GROK_API_KEY")
    grok_api_base: str = Field(default="https://api.x.ai/v1", alias="GROK_API_BASE")
    grok_model: str = Field(default="grok-2-latest", alias="GROK_MODEL")
    llm_min_interval_seconds: int = Field(default=8, alias="LLM_MIN_INTERVAL_SECONDS")
    llm_quota_cooldown_seconds: int = Field(default=300, alias="LLM_QUOTA_COOLDOWN_SECONDS")

    # ── Consultas SQL dinámicas en línea (capa 2.5, ver adhoc.py) ─────────────
    # Interruptor GLOBAL del agente; el flag por empresa (agente_alertas_config
    # → consulta_adhoc) es el autoritativo y lo verifica el backend siempre.
    adhoc_enabled: bool = Field(default=True, alias="ADHOC_ENABLED")

    # ── Clasificador de intención (segunda instancia) ─────────────────────────
    # Modelo chico y barato que SOLO entiende qué quiere el usuario y devuelve
    # {intent, query, periodo} en JSON. Reusa GOOGLE_API_KEY. No usa tool-calling
    # ni el grafo pesado, así consume muy pocos tokens y la cuota dura mucho más.
    classifier_enabled: bool = Field(default=True, alias="CLASSIFIER_ENABLED")
    classifier_model: str = Field(default="gemini-2.5-flash", alias="CLASSIFIER_MODEL")

    # ── MyPOS Web Backend (JWT Bearer) ────────────────────────────────────────
    # En producción usar 127.0.0.1 (misma máquina, evita DNS y TLS extra)
    mypos_web_url: str = Field(default="http://127.0.0.1/api", alias="MYPOS_WEB_URL")
    mypos_service_email: str = Field(default="", alias="MYPOS_SERVICE_EMAIL")
    mypos_service_password: str = Field(default="", alias="MYPOS_SERVICE_PASSWORD")

    # ── MyPOS Admin / FB API (X-API-KEY) ─────────────────────────────────────
    mypos_admin_url: str = Field(
        default="http://127.0.0.1/admin/api.php", alias="MYPOS_ADMIN_URL"
    )
    mypos_fb_url: str = Field(
        default="http://127.0.0.1/admin/fb", alias="MYPOS_FB_URL"
    )
    mypos_api_key: str = Field(default="", alias="MYPOS_API_KEY")

    # ── Seguridad del agente ──────────────────────────────────────────────────
    # Header X-Agent-Secret requerido en cada request al agente
    agent_secret: str = Field(default="", alias="AGENT_SECRET")

    # ── Persistencia ─────────────────────────────────────────────────────────
    db_path: str = Field(default="persistence/agent.db", alias="AGENT_DB")
    # Estado de cuota LLM compartido entre workers (ver quota_state.py)
    quota_db_path: str = Field(default="persistence/quota_state.db", alias="AGENT_QUOTA_DB")
    # Memoria ligera por hilo para seguimientos "¿y ayer?" (ver thread_memory.py)
    thread_memory_db_path: str = Field(default="persistence/thread_memory.db", alias="AGENT_THREAD_MEMORY_DB")
    unanswered_log_path: str = Field(default="tmp/agent_unanswered.txt", alias="AGENT_UNANSWERED_LOG")
    # Telemetría JSONL por consulta (capa, latencia, éxito) — ver telemetry.py
    metrics_log_path: str = Field(default="tmp/agent_metrics.jsonl", alias="AGENT_METRICS_LOG")
    skills_path: str = Field(default="skills", alias="AGENT_SKILLS_PATH")

    model_config = {"env_file": ".env", "populate_by_name": True, "extra": "ignore"}


settings = Settings()
