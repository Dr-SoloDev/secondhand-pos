#!/usr/bin/env bash
# Wrapper: ใช้โดย PHP exec() เพื่อเรียก Python print server
DIR="$(cd "$(dirname "$0")" && pwd)"
exec "$DIR/venv/bin/python3" "$DIR/print_receipt.py" "$@"
