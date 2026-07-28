#!/usr/bin/env bash
# Start Print Server for Scrap POS — Deli S420 Thermal Printer
# Usage: ./start.sh [start|stop|restart|status]

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PIDFILE="$SCRIPT_DIR/print-server.pid"
LOGFILE="$SCRIPT_DIR/print-server.log"
PORT=9120

start() {
    if [ -f "$PIDFILE" ] && kill -0 $(cat "$PIDFILE") 2>/dev/null; then
        echo "❌ Print Server already running (PID $(cat $PIDFILE))"
        exit 1
    fi

    cd "$SCRIPT_DIR"
    nohup "$SCRIPT_DIR/venv/bin/python3" "$SCRIPT_DIR/print_server_http.py" \
        --port "$PORT" > "$LOGFILE" 2>&1 &
    PID=$!
    echo "$PID" > "$PIDFILE"

    sleep 2
    if kill -0 "$PID" 2>/dev/null; then
        echo "✅ Print Server started (PID $PID) on port $PORT"
    else
        echo "❌ Print Server failed to start. Check log: $LOGFILE"
        cat "$LOGFILE"
        exit 1
    fi
}

stop() {
    if [ ! -f "$PIDFILE" ]; then
        echo "❌ No PID file found"
        # Try to kill any running instance
        pkill -f "print_server_http.py" 2>/dev/null && echo "✅ Stopped by process name" || echo "❌ No running instance"
        return
    fi

    PID=$(cat "$PIDFILE")
    kill "$PID" 2>/dev/null && echo "✅ Print Server stopped (PID $PID)" || echo "❌ Failed to stop"
    rm -f "$PIDFILE"
}

status() {
    if [ -f "$PIDFILE" ] && kill -0 $(cat "$PIDFILE") 2>/dev/null; then
        echo "✅ Print Server is running (PID $(cat $PIDFILE))"
        curl -s http://localhost:$PORT/health 2>/dev/null || echo "   (health check failed)"
    else
        echo "❌ Print Server is not running"
    fi
}

case "${1:-status}" in
    start)   start ;;
    stop)    stop ;;
    restart) stop; sleep 1; start ;;
    status)  status ;;
    *)       echo "Usage: $0 {start|stop|restart|status}" ;;
esac
