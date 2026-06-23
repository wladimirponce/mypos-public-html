"""
Entrada para cPanel Passenger (WSGI).
Passenger espera WSGI; FastAPI es ASGI → a2wsgi los convierte.

Configuración en cPanel → Setup Python App:
  Application root:    /home/<user>/mypos/agent
  Application URL:     /agent              (o subdominio agent.mypos.cl)
  Application startup: passenger_wsgi.py
  Python version:      3.11+

Instalar dependencias:
  pip install -r requirements.txt
  pip install langchain-anthropic   # o el proveedor que uses

Variables de entorno: agregar en cPanel → Setup Python App → Environment variables
  (todas las de .env.example)
"""

import sys
import os

# Asegura que el directorio del agente esté en el path
sys.path.insert(0, os.path.dirname(__file__))

from a2wsgi import ASGIMiddleware
from main import app

# Passenger llama a `application` como punto de entrada WSGI
application = ASGIMiddleware(app)
