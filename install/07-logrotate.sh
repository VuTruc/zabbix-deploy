#!/bin/bash



set -euo pipefail


# ============================================================
# Root check
# ============================================================

if [ "$EUID" -ne 0 ]; then
    echo "ERROR: This module must be run as root."
    echo "ERROR: Module này phải được chạy bằng root."
    exit 1
fi

# ============================================================
# Operating system check
# ============================================================

source /etc/os-release

if [[ "$ID" != "ol" ]]; then
    echo "ERROR: Oracle Linux is required."
    echo "ERROR: Yêu cầu Oracle Linux."
    exit 1
fi

if [[ "$VERSION_ID" != 9* ]]; then
    echo "ERROR: Oracle Linux 9 is required."
    echo "ERROR: Yêu cầu Oracle Linux 9."
    exit 1
fi

echo
echo "[Logrotate] Operating system:"
echo "       $PRETTY_NAME"

# ============================================================
# Install logrotate
# ============================================================

echo
echo "[Logrotate] Installing logrotate..."

dnf install -y logrotate

if ! command -v logrotate >/dev/null 2>&1; then
    echo "ERROR: logrotate command was not found after installation."
    exit 1
fi

LOGROTATE_VERSION="$(logrotate --version | head -n 1)"

echo
echo "Logrotate:"
echo "  ${LOGROTATE_VERSION}"

# ============================================================
# Check network log directory
# ============================================================

echo
echo "[Logrotate] Checking network log directory..."

if [ ! -d /var/log/network ]; then
    echo "[Logrotate] /var/log/network does not exist."
    echo "[Logrotate] Creating directory..."

    mkdir -p /var/log/network
fi

chmod 755 /var/log/network

echo "Network log directory:"
echo "  /var/log/network"

# ============================================================
# Create logrotate configuration
# ============================================================

echo
echo "[Logrotate] Creating network syslog logrotate configuration..."

cat > /etc/logrotate.d/network-syslog <<'EOF'
# ============================================================
# Centralized Network Syslog
# Managed by zabbix-monitoring-deploy
# ============================================================

/var/log/network/*/*.log {

    # Rotate logs every day
    daily

    # Keep 90 rotated files
    rotate 90

    # Add date to rotated filenames
    dateext

    # Do not report an error if a log does not exist
    missingok

    # Do not rotate empty logs
    notifempty

    # Compress old logs
    compress

    # Delay compression by one rotation
    delaycompress

    # Keep the same file while rsyslog continues writing
    copytruncate
}
EOF

# ============================================================
# Set configuration permissions
# ============================================================

echo
echo "[Logrotate] Setting logrotate configuration permissions..."

chmod 644 /etc/logrotate.d/network-syslog

# ============================================================
# Validate configuration file
# ============================================================

echo
echo "[Logrotate] Checking logrotate configuration..."

if logrotate -d /etc/logrotate.d/network-syslog >/dev/null 2>&1; then

    echo "Logrotate configuration: valid"

else

    echo
    echo "ERROR: Logrotate configuration validation failed."
    echo
    echo "========== Logrotate configuration =========="
    cat /etc/logrotate.d/network-syslog
    exit 1

fi

# ============================================================
# Check global logrotate configuration
# ============================================================

echo
echo "[Logrotate] Checking global logrotate configuration..."

if logrotate -d /etc/logrotate.conf >/dev/null 2>&1; then

    echo "Global logrotate configuration: valid"

else

    echo
    echo "ERROR: Global logrotate configuration validation failed."
    exit 1

fi

# ============================================================
# Check logrotate timer
# ============================================================

echo
echo "[Logrotate] Checking logrotate timer..."

if systemctl list-unit-files --type=timer 2>/dev/null \
    | grep -q '^logrotate\.timer'; then

    systemctl enable --now logrotate.timer

    if systemctl is-active --quiet logrotate.timer; then
        echo "logrotate.timer: enabled and active"
    else
        echo "ERROR: logrotate.timer failed to become active."
        systemctl status logrotate.timer --no-pager
        exit 1
    fi

else

    echo "INFO: logrotate.timer is not available."
    echo "INFO: System cron/anacron may be responsible for scheduled rotation."

fi

# ============================================================
# Check logrotate configuration file
# ============================================================

echo
echo "[Logrotate] Checking installed configuration file..."

if [ -f /etc/logrotate.d/network-syslog ]; then

    echo "Configuration file:"
    echo "  /etc/logrotate.d/network-syslog"

else

    echo "ERROR: Logrotate configuration file was not created."
    exit 1

fi

# ============================================================
# Display configuration
# ============================================================

echo
echo "[Logrotate] Installed logrotate configuration:"
echo

cat /etc/logrotate.d/network-syslog

# ============================================================
# Final status
# ============================================================

echo
echo "=============================================="
echo " Logrotate installation completed"
echo "=============================================="

echo
echo "Log path:"
echo "  /var/log/network/*/*.log"

echo
echo "Rotation:"
echo "  Daily"

echo
echo "Retention:"
echo "  90 rotations"

echo
echo "Date extension:"
echo "  Enabled"

echo
echo "Compression:"
echo "  Enabled"

echo
echo "Active log handling:"
echo "  copytruncate"

echo
echo "=============================================="
