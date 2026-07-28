#!/usr/bin/env bash
# Install Print Server as systemd user service (auto-start on boot)
# Run: bash install-service.sh

SERVICE_NAME="scrap-pos-print-server"
SERVICE_FILE="$HOME/.config/systemd/user/${SERVICE_NAME}.service"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# Create user systemd directory
mkdir -p "$HOME/.config/systemd/user"

# Create service file
cat > "$SERVICE_FILE" << EOF
[Unit]
Description=Scrap POS Thermal Print Server (Deli S420)
After=network.target cups.service

[Service]
Type=simple
ExecStart=${SCRIPT_DIR}/venv/bin/python3 ${SCRIPT_DIR}/print_server_http.py
Restart=on-failure
RestartSec=5
WorkingDirectory=${SCRIPT_DIR}
StandardOutput=append:${SCRIPT_DIR}/print-server.log
StandardError=append:${SCRIPT_DIR}/print-server.log

[Install]
WantedBy=default.target
EOF

# Enable linger for user services (run even when not logged in)
sudo loginctl enable-linger "$(whoami)" 2>/dev/null || true

# Enable and start
systemctl --user daemon-reload
systemctl --user enable "$SERVICE_NAME"
systemctl --user start "$SERVICE_NAME"

echo "✅ Service installed: ${SERVICE_NAME}"
echo ""
echo "Commands:"
echo "  systemctl --user start   ${SERVICE_NAME}   # Start"
echo "  systemctl --user stop    ${SERVICE_NAME}   # Stop"
echo "  systemctl --user status  ${SERVICE_NAME}   # Status"
echo "  systemctl --user enable  ${SERVICE_NAME}   # Auto-start on boot"
echo "  journalctl --user -u ${SERVICE_NAME} -f    # View logs"
