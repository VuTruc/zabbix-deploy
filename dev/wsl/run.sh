#!/bin/bash
# Dev harness: run a task inside WSL and save the output into the repo
# so the remote assistant can read it.
# Usage (inside WSL, as root):
#   sudo bash /mnt/c/Projects/zabbix-deploy/dev/wsl/run.sh <task> [args...]
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
TASK="${1:-}"; shift || true
if [ -z "$TASK" ] || [ ! -f "$HERE/tasks/$TASK.sh" ]; then
    echo "Usage: $0 <task> [args]"; echo "Tasks:"; ls "$HERE/tasks" | sed 's/\.sh$//;s/^/  /'; exit 2
fi
mkdir -p "$HERE/logs"
TS="$(date +%Y%m%d-%H%M%S)"
LOG="$HERE/logs/$TASK-$TS.log"
{
  echo "### task=$TASK args=$* host=$(hostname) wsl_distro=${WSL_DISTRO_NAME:-?} date=$(date -Is)"
  # Strip CR so CRLF checkouts on /mnt/c still run
  tr -d '\r' < "$HERE/tasks/$TASK.sh" | REPO="$REPO" bash -s -- "$@"
  echo "### exit=$?"
} 2>&1 | tee "$LOG"
cp -f "$LOG" "$HERE/logs/$TASK.latest.log"
echo; echo "[log] $LOG"
