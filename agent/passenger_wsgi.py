"""
cPanel Passenger entrypoint.

Passenger loads a WSGI callable named `application`. The real app is FastAPI
(ASGI), adapted through a2wsgi. If dependencies are not installed yet, return a
small JSON diagnostic instead of crashing Passenger in a restart loop.
"""

import json
import os
import sys
import traceback
from pathlib import Path

sys.path.insert(0, os.path.dirname(__file__))

_asgi_application = None


def _load_dotenv_values():
    values = {}
    env_path = Path(__file__).with_name(".env")
    if not env_path.exists():
        return values

    for raw_line in env_path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip('"').strip("'")
    return values


def _env_value(values, key, default=""):
    return os.environ.get(key) or values.get(key) or default


def _json_response(start_response, status, payload):
    body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    start_response(
        status,
        [
            ("Content-Type", "application/json; charset=utf-8"),
            ("Content-Length", str(len(body))),
        ],
    )
    return [body]


def _health_app(environ, start_response):
    values = _load_dotenv_values()
    provider = _env_value(values, "LLM_PROVIDER", "anthropic")
    provider_key = {
        "anthropic": bool(_env_value(values, "ANTHROPIC_API_KEY")),
        "openai": bool(_env_value(values, "OPENAI_API_KEY")),
        "google_genai": bool(_env_value(values, "GOOGLE_API_KEY")),
        "ollama": True,
    }.get(provider, False)

    return _json_response(
        start_response,
        "200 OK",
        {
            "status": "ok",
            "agent": "MyPOS Agent",
            "entrypoint": "passenger_wsgi",
            "model": _env_value(values, "LLM_MODEL", "claude-opus-4-8"),
            "provider": provider,
            "provider_key_configured": provider_key,
            "llm_min_interval_seconds": int(_env_value(values, "LLM_MIN_INTERVAL_SECONDS", "8") or 8),
            "llm_quota_cooldown_seconds": int(_env_value(values, "LLM_QUOTA_COOLDOWN_SECONDS", "300") or 300),
            "required_config": {
                "AGENT_SECRET": bool(_env_value(values, "AGENT_SECRET")),
                "MYPOS_SERVICE_EMAIL": bool(_env_value(values, "MYPOS_SERVICE_EMAIL")),
                "MYPOS_SERVICE_PASSWORD": bool(_env_value(values, "MYPOS_SERVICE_PASSWORD")),
                "MYPOS_API_KEY": bool(_env_value(values, "MYPOS_API_KEY")),
            },
        },
    )


def _diagnostic_app(error):
    def application(environ, start_response):
        if environ.get("PATH_INFO", "").rstrip("/").endswith("/health"):
            return _health_app(environ, start_response)

        payload = {
            "status": "error",
            "agent": "MyPOS Agent",
            "detail": "Python dependencies are not installed or failed to load.",
            "error_type": error.__class__.__name__,
            "error": str(error),
            "hint": "Activate the cPanel Python virtualenv and run: pip install -r requirements.txt",
        }
        if environ.get("QUERY_STRING") == "debug=1":
            payload["traceback"] = traceback.format_exception_only(error.__class__, error)

        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        start_response(
            "503 Service Unavailable",
            [
                ("Content-Type", "application/json; charset=utf-8"),
                ("Content-Length", str(len(body))),
            ],
        )
        return [body]

    return application


try:
    from a2wsgi import ASGIMiddleware

    def application(environ, start_response):
        global _asgi_application

        if environ.get("PATH_INFO", "").rstrip("/").endswith("/health"):
            return _health_app(environ, start_response)

        if _asgi_application is None:
            from main import app

            _asgi_application = ASGIMiddleware(app)

        return _asgi_application(environ, start_response)
except Exception as exc:
    application = _diagnostic_app(exc)
