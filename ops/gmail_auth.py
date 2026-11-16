#!/usr/bin/env python3
"""Script de autenticación OAuth 2.0 y descarga desatendida de Gmail para Akibara.

Guarda el token permanente en ~/.codex/credentials/gmail-token.json (chmod 600).
"""

from __future__ import annotations

import os
from pathlib import Path

from google.auth.transport.requests import Request
from google.oauth2.credentials import Credentials
from google_auth_oauthlib.flow import InstalledAppFlow
from googleapiclient.discovery import build

SCOPES = ["https://www.googleapis.com/auth/gmail.readonly"]
CREDENTIALS_DIR = Path.home() / ".codex" / "credentials"
CLIENT_SECRET_FILE = CREDENTIALS_DIR / "client_secret.json"
TOKEN_FILE = CREDENTIALS_DIR / "gmail-token.json"

def get_gmail_service():
    creds = None
    if TOKEN_FILE.exists():
        creds = Credentials.from_authorized_user_file(str(TOKEN_FILE), SCOPES)

    if not creds or not creds.valid:
        if creds and creds.expired and creds.refresh_token:
            print("Renovando token de acceso expirado...")
            creds.refresh(Request())
        else:
            if not CLIENT_SECRET_FILE.exists():
                raise FileNotFoundError(f"No se encontró el archivo de credenciales en {CLIENT_SECRET_FILE}")
            print("Iniciando flujo de autorización en el navegador...")
            flow = InstalledAppFlow.from_client_secrets_file(str(CLIENT_SECRET_FILE), SCOPES)
            creds = flow.run_local_server(port=0)

        # Guardar credenciales de forma segura
        TOKEN_FILE.write_text(creds.to_json(), encoding="utf-8")
        TOKEN_FILE.chmod(0o600)
        print(f"Token guardado con éxito en {TOKEN_FILE}")

    return build("gmail", "v1", credentials=creds)

if __name__ == "__main__":
    service = get_gmail_service()
    results = service.users().getProfile(userId="me").execute()
    print(f"Autenticación exitosa para: {results.get('emailAddress')}")
    print(f"Total de mensajes en la cuenta: {results.get('messagesTotal')}")
