set -e


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

echo
echo "[Oxidized Backup] Operating system:"
echo "       $PRETTY_NAME"

# ============================================================
# Variables
# ============================================================

OXIDIZED_USER="oxidized"
OXIDIZED_HOME="/home/${OXIDIZED_USER}"
OXIDIZED_CONFIG_DIR="${OXIDIZED_HOME}/.config/oxidized"
OXIDIZED_CONFIG="${OXIDIZED_CONFIG_DIR}/config"
OXIDIZED_ROUTER_DB="${OXIDIZED_CONFIG_DIR}/router.db"
OXIDIZED_OUTPUT_DIR="${OXIDIZED_CONFIG_DIR}/configs"

# ============================================================
# Install required packages
# ============================================================

echo

echo
echo "[Oxidized Backup] Enabling Oracle Linux CodeReady Builder repository..."

dnf config-manager --set-enabled ol9_codeready_builder

echo
echo "[Oxidized Backup] Refreshing package metadata..."

dnf makecache

echo
echo "[Oxidized Backup] Installing required packages..."

dnf install -y \
    ruby \
    ruby-devel \
    gcc \
    gcc-c++ \
    make \
    cmake \
    git \
    libyaml-devel \
    openssl-devel \
    zlib-devel \
    readline-devel \
    libffi-devel \
    libicu-devel \
    which

# ============================================================
# Check Ruby
# ============================================================

echo
echo "[Oxidized Backup] Checking Ruby..."

if ! command -v ruby >/dev/null 2>&1; then
    echo "ERROR: Ruby was not installed."
    exit 1
fi

ruby --version

# ============================================================
# Check RubyGems
# ============================================================

echo
echo "[Oxidized Backup] Checking RubyGems..."

if ! command -v gem >/dev/null 2>&1; then
    echo "ERROR: RubyGems was not installed."
    exit 1
fi

gem --version

# ============================================================
# Create Oxidized user
# ============================================================

echo
echo "[Oxidized Backup] Creating Oxidized user..."

if id "$OXIDIZED_USER" >/dev/null 2>&1; then

    echo "User '$OXIDIZED_USER' already exists."

else

    useradd \
        --create-home \
        --home-dir "$OXIDIZED_HOME" \
        --shell /bin/bash \
        "$OXIDIZED_USER"

    echo "User '$OXIDIZED_USER' created."

fi

# ============================================================
# Install Oxidized
# ============================================================

echo
echo "[Oxidized Backup] Checking Oxidized installation..."

# Ensure RubyGems executables are always available.
export PATH="/usr/local/bin:/usr/local/sbin:$PATH"

OXIDIZED_BIN="/usr/local/bin/oxidized"

if [ -x "$OXIDIZED_BIN" ]; then

    echo "Oxidized is already installed:"
    "$OXIDIZED_BIN" --version

else

    echo "Installing Oxidized..."

    gem install oxidized \
        --no-document \
        --bindir /usr/local/bin

fi

echo
echo "[Oxidized Backup] Verifying Oxidized executable..."

if [ ! -x "$OXIDIZED_BIN" ]; then

    echo "[WARN] Oxidized executable was not found after installation."
    echo "[Oxidized Backup] Retrying Oxidized installation..."

    gem install oxidized \
        --no-document \
        --bindir /usr/local/bin

fi

# ------------------------------------------------------------
# Firewall - allow Oxidized Web TCP port 8888
# ------------------------------------------------------------

echo
echo "[Oxidized Web] Configuring firewalld for TCP port 8888..."

if command -v firewall-cmd >/dev/null 2>&1; then

    if firewall-cmd --state >/dev/null 2>&1; then

        if firewall-cmd --query-port=8888/tcp >/dev/null 2>&1; then

            echo "Firewall: TCP port 8888 is already allowed."

        else

            echo "Firewall: Allowing TCP port 8888..."

            if firewall-cmd --permanent --add-port=8888/tcp >/dev/null 2>&1; then

                firewall-cmd --reload >/dev/null 2>&1

                echo "Firewall: TCP port 8888 allowed."

            else

                echo "ERROR: Failed to allow TCP port 8888."
                exit 1

            fi

        fi

    else

        echo "Firewall: firewalld is not running."
        echo "Skipping TCP port 8888 firewall configuration."

    fi

else

    echo "Firewall: firewall-cmd not found."
    echo "Skipping TCP port 8888 firewall configuration."

fi

echo "[Oxidized Backup] Checking oxidized-web..."

if ! gem list --local oxidized-web -i >/dev/null 2>&1; then

    echo "[Oxidized Backup] Installing oxidized-web..."

    gem install oxidized-web \
        --no-document

fi

if ! gem list --local oxidized-web -i >/dev/null 2>&1; then

    echo "[ERROR] oxidized-web installation failed."
    exit 1

fi

echo "[OK] oxidized-web is installed."

# === PATCH CISCO IOS PROMPT BEGIN ===
#
# Cisco IOS devices may use prompts containing "#" inside the hostname,
# for example:
#   C3650#1_VN_HCMHQ(128.2)#
#
# Oxidized's default IOS prompt regex does not allow "#" inside the
# hostname, which causes PromptUndetect.
#
echo "[Oxidized Backup] Checking Cisco IOS prompt compatibility..."

IOS_MODEL_FILE="$(
    ruby -e '
        begin
            require "oxidized"
            spec = Gem.loaded_specs["oxidized"]
            if spec
                puts File.join(spec.full_gem_path, "lib", "oxidized", "model", "ios.rb")
            end
        rescue LoadError
        end
    ' 2>/dev/null
)"

if [ -z "$IOS_MODEL_FILE" ] || [ ! -f "$IOS_MODEL_FILE" ]; then
    IOS_MODEL_FILE="$(
        find /usr/local/share/gems /usr/local/lib/ruby /var/lib/gems \
            -path '*/oxidized-*/lib/oxidized/model/ios.rb' \
            -type f 2>/dev/null | head -n 1
    )"
fi

if [ -z "$IOS_MODEL_FILE" ] || [ ! -f "$IOS_MODEL_FILE" ]; then
    echo "[ERROR] Could not locate Oxidized IOS model file."
    exit 1
fi

echo "[INFO] IOS model: $IOS_MODEL_FILE"

if grep -qF 'prompt /^([\w.@()#-]+[#>]\s?)$/' "$IOS_MODEL_FILE"; then

    echo "[OK] Cisco IOS prompt compatibility is already patched."

elif grep -qF 'prompt /^([\w.@()-]+[#>]\s?)$/' "$IOS_MODEL_FILE"; then

    IOS_MODEL_BACKUP="${IOS_MODEL_FILE}.$(date +%Y%m%d_%H%M%S).bak"

    cp -a "$IOS_MODEL_FILE" "$IOS_MODEL_BACKUP"

    python3 - "$IOS_MODEL_FILE" <<'PYTHON_PATCH'
from pathlib import Path
import sys

path = Path(sys.argv[1])

text = path.read_text()

old = r"prompt /^([\w.@()-]+[#>]\s?)$/"
new = r"prompt /^([\w.@()#-]+[#>]\s?)$/"

if old not in text:
    raise SystemExit(
        f"Original Cisco IOS prompt regex not found in {path}"
    )

text = text.replace(old, new, 1)

path.write_text(text)
PYTHON_PATCH

    if grep -qF 'prompt /^([\w.@()#-]+[#>]\s?)$/' "$IOS_MODEL_FILE"; then
        echo "[OK] Cisco IOS prompt compatibility patched."
        echo "[INFO] Backup: $IOS_MODEL_BACKUP"
    else
        echo "[ERROR] Cisco IOS prompt patch verification failed."
        exit 1
    fi

else

    echo "[ERROR] Unsupported Oxidized IOS prompt regex."
    echo "[ERROR] File: $IOS_MODEL_FILE"
    echo "[ERROR] Expected either the original or already-patched regex."
    exit 1

fi

# === PATCH CISCO IOS PROMPT END ===


if [ ! -x "$OXIDIZED_BIN" ]; then

    echo "ERROR: Oxidized installation failed."
    echo "ERROR: Expected executable: $OXIDIZED_BIN"
    echo
    echo "[Oxidized Backup] RubyGems environment:"
    gem env
    exit 1

fi

echo "[OK] Oxidized executable found:"
ls -l "$OXIDIZED_BIN"

echo "[Oxidized Backup] Oxidized version:"
"$OXIDIZED_BIN" --version

# ============================================================
# Create configuration directories
# ============================================================

echo
echo "[Oxidized Backup] Creating Oxidized directories..."

mkdir -p "$OXIDIZED_CONFIG_DIR"
mkdir -p "$OXIDIZED_OUTPUT_DIR"

# ============================================================
# Create empty router database
# ============================================================

echo
echo "[Oxidized Backup] Creating router database..."

if [ ! -f "$OXIDIZED_ROUTER_DB" ]; then

    touch "$OXIDIZED_ROUTER_DB"

fi

if ! grep -qvE '^[[:space:]]*(#|$)' "$OXIDIZED_ROUTER_DB"; then

    echo
    echo "[Oxidized Backup] Router database has no usable nodes."
    echo "[Oxidized Backup] Creating Zabbix Server bootstrap node..."

    printf '%s\n'         'zabbix-server:ios:127.0.0.1'         > "$OXIDIZED_ROUTER_DB"

    echo "Bootstrap node:"
    echo "  zabbix-server:ios:127.0.0.1"

fi

# ============================================================
# Create initial Oxidized configuration
# ============================================================

echo
echo "[Oxidized Backup] Creating Oxidized configuration..."

cat > "$OXIDIZED_CONFIG" <<'EOF'
---
username: CHANGE_ME
password: CHANGE_ME

model: ios

interval: 0

use_syslog: false

debug: false

threads: 30

timeout: 20

retries: 3

extensions:
  oxidized-web:
    load: true
    listen: 0.0.0.0
    port: 8888
    url_prefix: oxidized

next_adds_job: true

vars:
  auth_methods: [password]

groups: {}

pid: "/home/oxidized/.config/oxidized/pid"

input:
  default: ssh
  debug: false
  ssh:
    secure: false

output:
  default: git
  git:
    user: Oxidized
    email: oxidized@localhost
    repo: "/home/oxidized/.config/oxidized/configs"

source:
  default: csv
  csv:
    file: "/home/oxidized/.config/oxidized/router.db"
    delimiter: ":"
    map:
      name: 0
      model: 1
      ip: 2
      username: 3
      password: 4
    vars_map:
      ssh_port: 5

model_map:
  cisco: ios
EOF

# ============================================================
# Security warning for initial credentials
# ============================================================

echo
echo "[WARNING] Oxidized configuration contains placeholder credentials."
echo "[WARNING] Replace CHANGE_ME before adding network devices."

# ============================================================
# Set permissions
# ============================================================

echo
echo "[Oxidized Backup] Setting Oxidized permissions..."

chown -R "${OXIDIZED_USER}:${OXIDIZED_USER}" "$OXIDIZED_HOME"

# Oxidized owns the files; Apache needs group access to manage router.db.
chown "${OXIDIZED_USER}:apache" "$OXIDIZED_HOME"
chmod 750 "$OXIDIZED_HOME"

chown "${OXIDIZED_USER}:apache" "$OXIDIZED_CONFIG_DIR"
chmod 750 "$OXIDIZED_CONFIG_DIR"

chown "${OXIDIZED_USER}:apache" "$OXIDIZED_ROUTER_DB"
chmod 660 "$OXIDIZED_ROUTER_DB"

chmod 600 "$OXIDIZED_CONFIG"

# ============================================================
# Create systemd service
# ============================================================

echo
echo "[Oxidized Backup] Creating Oxidized systemd service..."

cat > /etc/systemd/system/oxidized.service <<EOF
[Unit]
Description=Oxidized Network Configuration Backup
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=${OXIDIZED_USER}
Group=${OXIDIZED_USER}
WorkingDirectory=${OXIDIZED_HOME}
Environment=HOME=${OXIDIZED_HOME}
ExecStart=/usr/local/bin/oxidized
Restart=on-failure
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF

# ============================================================
# Reload systemd
# ============================================================

echo
echo "[Oxidized Backup] Reloading systemd..."

systemctl daemon-reload

# ============================================================
# Enable Oxidized
# ============================================================

echo
echo "[Oxidized Backup] Enabling Oxidized..."

systemctl enable oxidized

# ============================================================
# Start Oxidized
# ============================================================

echo
echo "[Oxidized Backup] Checking initial configuration..."

if grep -q "CHANGE_ME" "$OXIDIZED_CONFIG"; then

    echo
    echo "[WARNING] Oxidized contains placeholder credentials."
    echo "[WARNING] Real device backups require valid credentials."
    echo "[Oxidized Backup] Starting Oxidized anyway so the Web interface is available."

fi

echo
echo "[Oxidized Backup] Starting Oxidized..."

systemctl restart oxidized

# ============================================================
# Display service status
# ============================================================

echo
echo "[Oxidized Backup] Oxidized service status..."

systemctl is-enabled oxidized || true

if systemctl is-active --quiet oxidized; then

    echo "Oxidized: running"

else

    echo "Oxidized: not running"
    echo "Check: journalctl -u oxidized -n 100 --no-pager"

fi

# ============================================================
# Final information
# ============================================================

echo
echo "=============================================="
echo " Oxidized installation completed"
echo "=============================================="

echo
echo "Oxidized user:"
echo "  $OXIDIZED_USER"

echo
echo "Configuration:"
echo "  $OXIDIZED_CONFIG"

echo
echo "Router database:"
echo "  $OXIDIZED_ROUTER_DB"

echo
echo "Backup repository:"
echo "  $OXIDIZED_OUTPUT_DIR"

echo
echo "Systemd service:"
echo "  oxidized.service"

echo
echo "IMPORTANT:"
echo "  Add real device credentials only after installation."
echo "  Do not add real device credentials to GitHub."

echo
echo "=============================================="

# === PER-NODE OXIDIZED SCHEDULER BEGIN ===

# Per-node scheduler files from the deployment repository.
INSTALL_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OXIDIZED_SCHEDULER_DIR="${INSTALL_SCRIPT_DIR}/oxidized"

if [ ! -f "${OXIDIZED_SCHEDULER_DIR}/oxidized-node-scheduler" ]; then
    echo "ERROR: Missing per-node scheduler script."
    exit 1
fi

if [ ! -f "${OXIDIZED_SCHEDULER_DIR}/oxidized-node-scheduler.service" ]; then
    echo "ERROR: Missing per-node scheduler service file."
    exit 1
fi

if [ ! -f "${OXIDIZED_SCHEDULER_DIR}/oxidized-node-scheduler.timer" ]; then
    echo "ERROR: Missing per-node scheduler timer file."
    exit 1
fi

# ------------------------------------------------------------
# Force Oxidized to use the external per-node scheduler.
# ------------------------------------------------------------

OXIDIZED_CONFIG_FILE="/home/oxidized/.config/oxidized/config"

if [ ! -f "$OXIDIZED_CONFIG_FILE" ]; then
    echo "ERROR: Oxidized config not found: $OXIDIZED_CONFIG_FILE"
    exit 1
fi

python3 - "$OXIDIZED_CONFIG_FILE" <<'PY'
from pathlib import Path
import re
import sys

path = Path(sys.argv[1])
text = path.read_text(encoding="utf-8")

if re.search(r'^interval:\s*', text, re.MULTILINE):
    text = re.sub(
        r'^interval:\s*.*$',
        'interval: 0',
        text,
        count=1,
        flags=re.MULTILINE
    )
else:
    raise SystemExit("ERROR: Oxidized interval setting not found.")

auth_pattern = re.compile(
    r'^  auth_methods:\s*\[[^\]]*\]\s*$',
    re.MULTILINE
)

if auth_pattern.search(text):
    text = auth_pattern.sub(
        '  auth_methods: [password]',
        text,
        count=1
    )
elif re.search(r'^vars:\s*$', text, re.MULTILINE):
    text = re.sub(
        r'^vars:\s*$',
        'vars:\n  auth_methods: [password]',
        text,
        count=1,
        flags=re.MULTILINE
    )
else:
    raise SystemExit("ERROR: Oxidized vars section not found.")

path.write_text(text, encoding="utf-8")
PY

if ! grep -qE '^next_adds_job:[[:space:]]*true[[:space:]]*$' \
    "$OXIDIZED_CONFIG_FILE"; then
    echo "ERROR: next_adds_job: true is missing from Oxidized config."
    exit 1
fi

# ------------------------------------------------------------
# Validate CSV per-node credential mapping.
# ------------------------------------------------------------

for required_line in \
    "      name: 0" \
    "      model: 1" \
    "      ip: 2" \
    "      username: 3" \
    "      password: 4" \
    "      ssh_port: 5"
do
    if ! grep -qF "$required_line" "$OXIDIZED_CONFIG_FILE"; then
        echo "ERROR: Missing Oxidized CSV mapping: $required_line"
        exit 1
    fi
done

# ------------------------------------------------------------
# Install scheduler.
# ------------------------------------------------------------

install -o root -g oxidized -m 0750 \
    "${OXIDIZED_SCHEDULER_DIR}/oxidized-node-scheduler" \
    /usr/local/bin/oxidized-node-scheduler

install -o root -g root -m 0644 \
    "${OXIDIZED_SCHEDULER_DIR}/oxidized-node-scheduler.service" \
    /etc/systemd/system/oxidized-node-scheduler.service

install -o root -g root -m 0644 \
    "${OXIDIZED_SCHEDULER_DIR}/oxidized-node-scheduler.timer" \
    /etc/systemd/system/oxidized-node-scheduler.timer

# ------------------------------------------------------------
# Create per-node settings database.
# ------------------------------------------------------------

OXIDIZED_SETTINGS="/home/oxidized/.config/oxidized/node-settings.json"

if [ ! -f "$OXIDIZED_SETTINGS" ]; then
    printf '{}\n' > "$OXIDIZED_SETTINGS"
fi

if [ ! -s "$OXIDIZED_SETTINGS" ]; then
    printf '{}\n' > "$OXIDIZED_SETTINGS"
fi

chown oxidized:apache "$OXIDIZED_SETTINGS"
chmod 0660 "$OXIDIZED_SETTINGS"

# ------------------------------------------------------------
# Apache + SELinux integration for Oxidized Web.
# ------------------------------------------------------------

echo
echo "============================================================"
echo "[INFO] Configuring Oxidized Web access for Apache..."
echo "============================================================"

dnf install -y acl policycoreutils-python-utils

# Allow Apache to traverse Oxidized directories.
setfacl -m u:apache:--x "$OXIDIZED_HOME"
setfacl -m u:apache:--x "$OXIDIZED_HOME/.config"
setfacl -m u:apache:rwx "$OXIDIZED_CONFIG_DIR"

# Allow Apache to read/write the files used by the Zabbix Oxidized module.
chown "$OXIDIZED_USER:apache" "$OXIDIZED_ROUTER_DB"
chown "$OXIDIZED_USER:apache" "$OXIDIZED_SETTINGS"

chmod 0660 "$OXIDIZED_ROUTER_DB"
chmod 0660 "$OXIDIZED_SETTINGS"

setfacl -m u:apache:rw "$OXIDIZED_ROUTER_DB"
setfacl -m u:apache:rw "$OXIDIZED_SETTINGS"

# SELinux labels for Oxidized configuration.
semanage fcontext -a -t httpd_sys_rw_content_t "${OXIDIZED_CONFIG_DIR}(/.*)?" 2>/dev/null || semanage fcontext -m -t httpd_sys_rw_content_t "${OXIDIZED_CONFIG_DIR}(/.*)?"

semanage fcontext -a -t httpd_sys_rw_content_t "$OXIDIZED_ROUTER_DB" 2>/dev/null || semanage fcontext -m -t httpd_sys_rw_content_t "$OXIDIZED_ROUTER_DB"

semanage fcontext -a -t httpd_sys_rw_content_t "$OXIDIZED_SETTINGS" 2>/dev/null || semanage fcontext -m -t httpd_sys_rw_content_t "$OXIDIZED_SETTINGS"

restorecon -Rv "$OXIDIZED_CONFIG_DIR"

# Explicitly restore writable labels.
restorecon -v "$OXIDIZED_ROUTER_DB"
restorecon -v "$OXIDIZED_SETTINGS"

# Oxidized Web listens on TCP/8888.
if semanage port -l | awk '$1 == "http_port_t" && $2 == "tcp" {print $3}' | tr ',' '\n' | grep -qx "8888"; then
    :
else
    semanage port -a -t http_port_t -p tcp 8888 2>/dev/null || \
    semanage port -m -t http_port_t -p tcp 8888
fi

# Allow Apache to connect to Oxidized Web.
setsebool -P httpd_can_network_connect 1

# Apache reverse proxy.
cat > /etc/httpd/conf.d/oxidized.conf <<APACHE
ProxyPreserveHost On

ProxyPass        /oxidized/ http://127.0.0.1:8888/oxidized/
ProxyPassReverse /oxidized/ http://127.0.0.1:8888/oxidized/

<Location /oxidized/>
    Require all granted
</Location>
APACHE

# Validate Apache configuration.
httpd -t

systemctl enable httpd
systemctl restart httpd

echo "[INFO] Oxidized Apache integration configured."

# ------------------------------------------------------------
# Verify permissions and SELinux.
# ------------------------------------------------------------

echo
echo "[INFO] Checking Oxidized permissions..."

ls -ld "$OXIDIZED_HOME"
ls -ld "$OXIDIZED_HOME/.config"
ls -ld "$OXIDIZED_CONFIG_DIR"

getfacl -p "$OXIDIZED_ROUTER_DB" | grep -E 'user:apache|^# file'
getfacl -p "$OXIDIZED_SETTINGS" | grep -E 'user:apache|^# file'

ls -Z "$OXIDIZED_ROUTER_DB"
ls -Z "$OXIDIZED_SETTINGS"

# Test Apache write permission.
TEST_FILE="$OXIDIZED_CONFIG_DIR/.apache-write-test"

if runuser -u apache -- sh -c "printf '%s\\n' test > '$TEST_FILE'"; then
    rm -f "$TEST_FILE"
    echo "[OK] Apache can write Oxidized configuration directory."
else
    echo "[WARN] Apache write test failed."
fi

# ------------------------------------------------------------
# Verify Oxidized Web.
# ------------------------------------------------------------

echo
echo "[INFO] Checking Oxidized Web..."

if curl -fsS --max-time 10 http://127.0.0.1:8888/oxidized/ >/dev/null; then
    echo "[OK] Oxidized Web backend is reachable."
else
    echo "[WARN] Oxidized Web backend is not reachable."
fi

if curl -fsS --max-time 10 http://127.0.0.1:8000/oxidized/ >/dev/null; then
    echo "[OK] Apache proxy /oxidized/ is reachable."
else
    echo "[WARN] Apache proxy /oxidized/ is not reachable."
fi

echo
echo "============================================================"
echo "[OK] Oxidized Web integration completed."
echo "============================================================"

# ------------------------------------------------------------
# Reload systemd and enable scheduler.
# ------------------------------------------------------------

systemctl daemon-reload
systemctl enable --now oxidized-node-scheduler.timer

# Re-read the changed Oxidized configuration.
systemctl restart oxidized

# Ensure scheduler timer is running after the Oxidized restart.
systemctl restart oxidized-node-scheduler.timer

echo "Per-node Oxidized scheduler installed successfully."

# === PER-NODE OXIDIZED SCHEDULER END ===
