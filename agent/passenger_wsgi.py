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

sys.path.insert(0, os.path.dirname(__file__))


def _diagnostic_app(error):
    def application(environ, start_response):
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
    from main import app

    application = ASGIMiddleware(app)
except Exception as exc:
    application = _diagnostic_app(exc)
