#!/usr/bin/env bash
# tools/brain.sh — Centro de Control y Automatización del Segundo Cerebro de Akibara
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

DRIVE_VAULT="${HOME}/Akibara"
ACTION="${1:-all}"

case "$ACTION" in
    status)
        node tools/second-brain-status.mjs
        ;;
    sync)
        node tools/sync-notebooklm.mjs
        ;;
    open)
        echo "Abriendo Bóveda de Obsidian en Finder..."
        open "$DRIVE_VAULT" 2>/dev/null || open "${HOME}/Library/CloudStorage/GoogleDrive-ale.fvaras@gmail.com/Mi unidad/Akibara-Knowledge-Base"
        echo "Abriendo NotebookLM en tu navegador..."
        open "https://notebooklm.google.com/"
        ;;
    all|*)
        echo "=== EJECUTANDO CICLO COMPLETO DEL SEGUNDO CEREBRO ==="
        echo ""
        node tools/second-brain-status.mjs
        echo ""
        node tools/sync-notebooklm.mjs
        echo ""
        echo "✔ Todo sincronizado y operativo."
        echo "  • Bóveda Obsidian local y en Google Drive: ~/Akibara"
        echo "  • NotebookLM listo: Solo haz clic en 'Sincronizar fuentes con Drive'"
        ;;
esac
