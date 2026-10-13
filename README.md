# Zabbix Deploy — Project Guide for Humans and Claude

> **Repository:** `VuTruc/zabbix-deploy`  
> **Default branch:** `main`  
> **Purpose:** Automated deployment and extensions for a Zabbix 7.0 monitoring server on Oracle Linux 9.  
> **Project status:** Source has been migrated and inspected. A clean, end-to-end installation on the new repository has **not yet been verified**. Treat runtime behavior as unverified until tested.

This README is also the project handoff document for Claude or another developer. Read it first, then inspect the actual scripts before changing behavior. The code is the source of truth whenever this document and implementation differ.

## 1. Project goals

This repository automates a monitoring server and provides Zabbix frontend integrations for:

- Zabbix Server, Agent, frontend, API and MariaDB.
- Apache/PHP frontend hosting.
- Centralized network Syslog through rsyslog, with per-source-IP log folders.
- Log rotation for network logs.
- Oxidized network-device configuration backups and a Zabbix frontend module.
- Telegram notifications configured through the Zabbix API.
- Network Syslog discovery/dashboard module.
- Optional TACACS+ administration, configuration generation and accounting/audit log viewing.

The intended target is a **fresh Oracle Linux 9 server**. Do not assume the full installer is safe to rerun on a production server: several scripts modify system configuration, services, database objects and Zabbix API objects.

## 2. Installation entry point and order

Run the top-level `install.sh` as root. It currently performs these steps in this exact order:

| Step | Script | Responsibility |
|---|---|---|
| 1 | `install/01-system.sh` | OS checks, base packages, hostname, NetworkManager and time service preparation |
| 2 | `install/02-network.sh` | Optional static network configuration through NetworkManager |
| 3 | `install/03-mariadb.sh` | MariaDB, Zabbix DB/user, local DB credential file |
| 4 | `install/04-zabbix.sh` | Zabbix 7.0 repository/packages, DB schema and server/agent configuration |
| 5 | `install/05-apache-php.sh` | Apache/PHP installation and frontend configuration |
| 6 | `install/06-rsyslog.sh` | UDP/514 receiver and per-device full/event log files |
| 7 | `install/07-logrotate.sh` | Rotation policy for `/var/log/network/*/*.log` |
| 8 | `install/08-oxidized.sh` | Oxidized, service, web proxy, config repository and per-node scheduler |
| 9 | `install/09-telegram.sh` | Telegram credential setup, API/media type/action configuration |
| 10 | `install/10-network-syslog.sh` | Zabbix API configuration for Syslog discovery and frontend integration |
| 11 | `install/11-tacacs.sh` | Deploy TACACS+ Zabbix module/migrations/audit collector when prerequisites exist; otherwise skip |

The entry point disables SELinux (runtime and boot configuration) before running the numbered steps. This is a significant security-impacting behavior. Do not silently expand or further weaken it; discuss whether to replace it with proper SELinux policy before changing it.

The Telegram step is wrapped in a retry loop: if it exits non-zero, the top-level installer repeats that step. The other numbered steps are not wrapped in the same retry loop. The script uses `set -e`, so a failure in a normal step stops the run.

### Basic invocation

On a disposable/test Oracle Linux 9 host with network/package-repository access:

```bash
git clone https://github.com/VuTruc/zabbix-deploy.git
cd zabbix-deploy
sudo bash ./install.sh
```

The installer expects root. Read the script before running it on a host that already has Zabbix, MariaDB, Apache, Oxidized or TACACS+ installed.

## 3. Expected endpoints and important defaults

- Zabbix frontend: `http://<server-ip>:8000/zabbix/`
- Local frontend: `http://localhost:8000/zabbix/`
- Oxidized is configured on local port `8888`, proxied under `/oxidized/` by Apache.
- Network Syslog listens on UDP `514`.
- Default Zabbix login printed by the installer: `Admin` / `zabbix`. Change the password immediately and check whether later automation still assumes these credentials.

Do not treat these defaults as secure production settings.

## 4. Source tree map

```text
.
├── install.sh
├── install/
│   ├── 01-system.sh ... 11-tacacs.sh
│   ├── oxidized-module/             # Zabbix frontend module for Oxidized
│   ├── oxidized/                    # Per-node scheduler and systemd units
│   ├── tacacs-module/               # TACACS+ frontend module
│   ├── tacacs/
│   │   ├── schema.sql
│   │   ├── tacacs-config-generator.php
│   │   ├── tacacs-audit-collector.php
│   │   ├── migrate-*.php
│   │   ├── tests/
│   │   └── systemd/
│   └── zabbix/
│       ├── network-syslog.conf
│       ├── network-syslog-discovery
│       └── modules/NetworkSyslog/
├── backup/pre-network-syslog-dashboard/ # Historical scripts; not the active entry point
└── secrets/.gitkeep                   # Placeholder only; do not commit real secrets
```

### Active vs historical code

- `install.sh` is the active orchestrator.
- The numbered scripts under `install/` are the active install steps.
- `backup/pre-network-syslog-dashboard/` contains historical snapshots. Do not copy old behavior from these files into the active scripts without comparing intent and current implementation.
- Frontend modules are copied/deployed by install scripts; editing only the deployed server copy is not a durable source change. Make the source change in this repository and update the installer if required.

## 5. Component notes

### 5.1 System and network

- `install/01-system.sh` is intended for Oracle Linux 9 and asks for a hostname.
- `install/02-network.sh` can configure a static IP, gateway and DNS. It checks whether NetworkManager manages the interface and does not forcibly take over an unmanaged interface.
- Network changes can interrupt the current remote session. Test from console or a recoverable environment.

### 5.2 MariaDB and Zabbix

- MariaDB credentials are written to `/etc/zabbix/zabbix-db.env`; the database script sets restrictive permissions.
- Database defaults in the implementation include DB name `zabbix` and DB user `zabbix`.
- Zabbix package repository is the 7.0 Oracle Linux 9 repository.
- Zabbix Agent hostname is derived from the system hostname.
- The Apache/PHP step configures the frontend and port `8000`; inspect its current Apache configuration before changing ports or paths.

### 5.3 Centralized Syslog and discovery

- `install/06-rsyslog.sh` configures UDP/514 and writes rsyslog configuration under `/etc/rsyslog.d/`.
- Full logs: `/var/log/network/<source-ip>/syslog.log`.
- Filtered event logs: `/var/log/network/<source-ip>/events.log`.
- The Zabbix service account is granted read/traverse access through ACLs where supported by the script.
- `install/07-logrotate.sh` manages `/etc/logrotate.d/network-syslog`.
- `install/10-network-syslog.sh` installs the discovery key and config, then uses the Zabbix API to create/update the discovery rule, host group and host prototypes.
- Discovery script source: `install/zabbix/network-syslog-discovery`.
- Agent key configuration source: `install/zabbix/network-syslog.conf`.
- Frontend module: `install/zabbix/modules/NetworkSyslog/`.

When investigating a missing device in the Syslog UI, trace the whole path: device sends UDP/514 → rsyslog creates/writes the source-IP folder → discovery script emits JSON → Agent key returns JSON → LLD runs on the intended Zabbix host → discovered entities/items appear → frontend module queries the expected data. Do not assume that a folder's existence alone means the Zabbix discovery rule succeeded.

### 5.4 Oxidized

- Main installer: `install/08-oxidized.sh`.
- Oxidized configuration directory: `/home/oxidized/.config/oxidized/`.
- Inventory: `router.db` in that directory.
- Configuration history repository: `configs/` in that directory.
- Web service uses local TCP/8888 and is proxied through Apache at `/oxidized/`.
- The installer includes a compatibility adjustment for Cisco IOS prompts containing `#` in hostnames.
- Scheduler files are in `install/oxidized/`; the timer invokes the per-node scheduler.
- Frontend module source: `install/oxidized-module/`, module ID `oxidized`, action `oxidized.nodes`.

Do not commit device passwords, SSH secrets or real router inventory credentials. Verify the actual Oxidized model and credential handling before adding a new device type.

### 5.5 Telegram

- Main script: `install/09-telegram.sh`.
- Telegram credentials are expected in `/etc/zabbix/telegram.env`, including `TELEGRAM_BOT_TOKEN` and `TELEGRAM_CHAT_ID`, with mode `0600`.
- The script also refers to `/etc/zabbix/zabbix-export.env`. Before changing or relying on this feature, verify where that file is created and what variables it must contain. Do not assume it is created by the base installer.
- The script uses the Zabbix API and downloads the upstream Zabbix 7.0 Telegram media-type template.
- API credentials, bot tokens and chat IDs are secrets. Never paste their real values into issues, commits or this README.

### 5.6 TACACS+

TACACS+ is an optional integration, not a prerequisite for the base monitoring server. `install/11-tacacs.sh` skips the deployment if the runtime/configuration prerequisites are not ready. It expects at least:

- `/usr/sbin/tac_plus`
- Readable `/etc/tacacs/tacacs-db.conf`
- `/etc/tac_plus.conf`
- PHP with PDO and `pdo_mysql`

The script validates the TACACS+ config with `tac_plus -P`, verifies/creates missing database tables using the schema's non-destructive `CREATE TABLE IF NOT EXISTS` statements, runs migrations and PHP tests, deploys the Zabbix module, and enables the audit collector timer when systemd is available. It intentionally does **not** overwrite `/etc/tac_plus.conf`, `/etc/tacacs/tacacs-db.conf`, or force the TACACS+ daemon's service state.

TACACS+ module ID: `tacacs`. Frontend actions include:

- `tacacs.dashboard`
- `tacacs.users`
- `tacacs.devices`
- `tacacs.policies`
- `tacacs.assignments`
- `tacacs.auditlogs`
- `tacacs.auditlogs.csv`

Key files:

- Schema: `install/tacacs/schema.sql`
- Config generator: `install/tacacs/tacacs-config-generator.php`
- Accounting/audit collector: `install/tacacs/tacacs-audit-collector.php`
- Collector units: `install/tacacs/systemd/`
- Logrotate config: `install/tacacs/tacacs-audit-logrotate.conf`
- Migrations: `install/tacacs/migrate-*.php`
- Tests: `install/tacacs/tests/`

Network-device TACACS configuration is intentionally performed manually by the administrator. Do not assume that deploying the server/module automatically configures Cisco, FortiGate or other network devices. Authentication, authorization and accounting must be tested on a lab device before production use.

## 5.7 Detailed operational workflows (source-based)

This section documents the intended end-to-end behavior of the three network operations integrations. It is written for maintainers and coding assistants: follow the path across all layers before changing a single component. File paths and behavior must be rechecked against the current source before implementation changes.

### A. Oxidized — network configuration backup and version history

#### Components and data flow

```text
Administrator / Zabbix Oxidized page
  -> install/oxidized-module/actions/OxidizedNodes.php
  -> validates and saves node settings / inventory
  -> /home/oxidized/.config/oxidized/config
  -> /home/oxidized/.config/oxidized/router.db
  -> Oxidized service (SSH + vendor/model driver)
  -> /home/oxidized/.config/oxidized/configs (Git repository)
  -> Apache reverse proxy /oxidized/ -> local web service :8888

systemd timer
  -> oxidized-node-scheduler
  -> reads node-settings.json and due time
  -> requests Oxidized node endpoint
  -> advances next-backup time when the request succeeds
```

Main source files:
- Installer: `install/08-oxidized.sh`
- Scheduler: `install/oxidized/oxidized-node-scheduler`
- systemd units: `install/oxidized/oxidized-node-scheduler.service` and `install/oxidized/oxidized-node-scheduler.timer`
- Zabbix module: `install/oxidized-module/`, module ID `oxidized`, action `oxidized.nodes`

#### Adding or editing a node

1. Use the Oxidized frontend module to enter the node name, model, address, SSH username, password and port (normally 22), and backup interval in days.
2. Save the node. The module must validate the input and persist it to the inventory/settings files expected by the installed Oxidized instance.
3. The node must use a model supported by the installed Oxidized version and have working network reachability and valid credentials. A syntactically valid form is not proof that SSH login or command collection will work.
4. Reload Oxidized when needed so changes to the inventory/config are picked up. Use “Backup Now” to request an immediate collection when the UI supports it.
5. Check the resulting file under the Git-backed `configs/` directory and inspect the Git history/diff to confirm a backup was actually stored.

When editing an existing node, an empty password field is intended to mean “keep the existing password”; verify this behavior in the current controller before changing form or persistence logic. Never log or commit the password.

#### Per-node scheduling

- The scheduler reads each node's interval and next scheduled time from `node-settings.json`; the timer invokes the scheduler periodically (currently designed to run about once per minute).
- For a due node, it calls the local Oxidized node endpoint (currently expected at `http://127.0.0.1:8888/oxidized/node/next/<node>.json`).
- A successful HTTP 2xx response means the request was accepted/returned successfully. It does **not** independently prove that device authentication, command collection, Git commit or configuration parsing succeeded.
- State updates should be protected against overlapping runs (the scheduler uses a lock). Preserve this property when modifying the scheduler.
- Confirm the exact endpoint, node-name encoding and settings format in source before changing them; do not assume all node names are safe to place directly in a URL.

#### What Oxidized does and does not prove

Oxidized periodically collects configuration using vendor/model-specific logic and records snapshots in Git. Git history helps compare configuration versions and determine what changed between successful backups. It is **not** a TACACS+ command-accounting system: a diff cannot reliably identify who typed a command, every intermediate command, or the exact time each CLI command was entered.

Troubleshooting order:
1. Check `oxidized.service`, scheduler timer/service and their logs.
2. Confirm local web endpoint and Apache proxy path `/oxidized/`.
3. Verify inventory entry, model, credentials and SSH reachability from the Oxidized service account.
4. Inspect Oxidized logs for login/enable/prompt/model errors.
5. Check whether the node's config file changed and whether Git recorded a new version.
6. If the UI says a backup was requested, distinguish “request accepted” from “backup committed”.

### B. Network Syslog — receive, store, discover and display device logs

#### End-to-end data flow

```text
Network device
  -- Syslog over UDP/514 -->
rsyslog on the Zabbix server
  -> /var/log/network/<source-ip>/syslog.log
  -> /var/log/network/<source-ip>/events.log (filtered events)
  -> logrotate policy

Zabbix Agent UserParameter
  -> /usr/local/bin/zabbix-network-syslog-discovery
  -> JSON list of IP-named directories
  -> network.syslog.discovery
  -> Zabbix low-level discovery (LLD) / discovered objects

Zabbix frontend module NetworkSyslog
  -> scans source-IP directories and reads selected log file
  -> paginates a bounded set of lines for the UI
```

Main source files:
- Receiver configuration: `install/06-rsyslog.sh` (deployed config is under `/etc/rsyslog.d/`)
- Rotation: `install/07-logrotate.sh`, deployed as `/etc/logrotate.d/network-syslog`
- API/LLD installation: `install/10-network-syslog.sh`
- Agent key: `install/zabbix/network-syslog.conf`
- Discovery script: `install/zabbix/network-syslog-discovery`, installed as `/usr/local/bin/zabbix-network-syslog-discovery`
- UI module: `install/zabbix/modules/NetworkSyslog/`

#### Receive and store

1. Configure each network device manually to send Syslog to the server address and UDP port 514. The server-side installer does not automatically configure routers, switches or firewalls.
2. rsyslog receives messages and writes logs grouped by source IP:
   - Full log: `/var/log/network/<source-ip>/syslog.log`
   - Filtered event log: `/var/log/network/<source-ip>/events.log`
3. File and directory permissions/ACLs must let the Zabbix frontend process read the data without granting unnecessary write access.
4. Logrotate controls growth of `/var/log/network/*/*.log`. Validate that rotated files remain compatible with the UI and retention expectations.

UDP Syslog can lose messages; it does not provide the delivery guarantees of a reliable transport. Also, source IP reflects the packet source as seen by the receiver and may be affected by NAT or relays.

#### Discovery and dashboard

1. The discovery script scans immediate subdirectories under `/var/log/network` and emits LLD JSON for directory names that look like IPv4 addresses.
2. The agent exposes that output through `network.syslog.discovery`.
3. The Zabbix discovery rule created/updated by `install/10-network-syslog.sh` consumes the JSON and creates the configured discovered objects/items.
4. The Network Syslog frontend module reads log files directly from the filesystem, validates the selected IP and log type, and displays a bounded page of lines (currently capped at 500 lines per request in the implementation described by this guide).
5. These are related but distinct paths: Zabbix LLD object discovery does not itself guarantee that the UI can read the log file, and the custom UI's filesystem read is not the same as reading a Zabbix item-history value.

A directory may remain after a device stops sending logs. Therefore, the presence of a directory proves only that a matching directory exists; it does not prove the device is currently online or sending recent messages. If current activity matters, use log modification time or add an explicit “last message seen” check instead of treating directory discovery as liveness.

#### Troubleshooting order

1. Confirm the device's configured destination and source address.
2. Check UDP/514 reachability, host firewall policy and `rsyslog` service status.
3. Confirm a fresh message is appended to the correct `syslog.log` and that event filtering behaves as expected in `events.log`.
4. Run the discovery executable directly and validate its JSON output.
5. Test the agent key and confirm the Zabbix server/proxy can retrieve it.
6. Inspect the LLD rule status, last check, errors and discovered objects.
7. Check the Network Syslog module's file permissions, selected IP/log type, and pagination behavior.
8. Verify logrotate configuration and ACLs after rotation.

Do not “fix” a missing dashboard device by changing only the view before proving which stage of this chain failed.

### C. TACACS+ — centralized authentication, command authorization and accounting

#### Architecture and data flow

```text
Network device (TACACS+ client / NAS)
  -- TCP/49: authentication, authorization, accounting -->
tac_plus daemon + /etc/tac_plus.conf
  <- generated by install/tacacs/tacacs-config-generator.php
  <- reads users/devices/policies/rules/assignments from MariaDB

Zabbix TACACS+ module
  -> users, devices, policies, policy rules and assignments in DB
  -> configuration generator
  -> validate temporary config with tac_plus -P
  -> replace generated TACACS config only after validation

tac_plus accounting log
  -> install/tacacs/tacacs-audit-collector.php
  -> parse records / track file progress and fingerprint
  -> audit_log table
  -> tacacs.auditlogs UI and tacacs.auditlogs.csv export
```

Main source files:
- Optional integration installer: `install/11-tacacs.sh`
- Schema: `install/tacacs/schema.sql`
- Config generator: `install/tacacs/tacacs-config-generator.php`
- Accounting collector: `install/tacacs/tacacs-audit-collector.php`
- Migrations, tests and service/timer units: `install/tacacs/`
- Frontend: `install/tacacs-module/`, module ID `tacacs`

#### Configuration lifecycle

1. An administrator creates or updates users, network devices, policies, command rules and assignments in the Zabbix TACACS+ module.
2. The database represents entities and their relationships. Core tables include `users`, `devices`, `policies`, `policy_rules`, `user_devices`, `user_policies`, `device_policies`, `assignments`, `audit_log` and `assignment_page_security`. Always inspect the current schema and migrations before changing a field or relation.
3. Policy rules are ordered command/argument match rules with an action such as `permit` or `deny`. Matching behavior, rule precedence, default behavior and device-specific syntax are security-critical; verify them in the generator and tests instead of inferring from UI labels.
4. The config generator reads the DB and produces TACACS daemon configuration, including user/group/policy mappings and device/NAS restrictions as implemented by the current source.
5. It validates a temporary generated file using `tac_plus -C <temporary-file> -P`. Only a parser-valid file should replace the generated target. Parser success proves syntax acceptance, not that every policy will behave as intended on a live device.
6. The network administrator manually configures each device to use the server for AAA. The installer/module does not automatically configure Cisco, FortiGate or other device CLI settings.
7. Test a lab login, permitted commands, denied commands, fallback behavior and accounting before production use.

The design avoids imposing one universal privilege level because vendors implement command authorization differently. Do not reintroduce a single hard-coded privilege-level assumption as a shortcut. Keep policy matching vendor-aware and document any device-specific limitation. In particular, test Cisco enable/privilege behavior and command authorization against the exact device OS and TACACS client behavior.

#### Authentication, authorization and accounting are separate

- **Authentication:** establishes the user's identity. Success depends on the configured user credentials, daemon configuration and device AAA method/order.
- **Authorization:** determines whether a requested service/command is allowed under the policy and assignment model. A successful login does not prove that command restrictions are correct.
- **Accounting:** the device sends records to the TACACS+ server for sessions and/or commands, depending on the device's AAA accounting configuration and what the platform supports. The server can only collect records that are actually sent and written.
- **Audit UI/export:** the collector parses accounting log records and stores normalized fields in `audit_log`; the module filters/displays them and can export CSV. Parsed/displayed records may lack fields that the device or source log did not provide.

Do not claim that every command is audited merely because TACACS+ authentication works or an audit page exists. Confirm per-device accounting configuration and inspect raw accounting records. A collector record or generic status such as `info` is not necessarily proof that the command executed successfully on the device.

#### Collector and audit investigation

When an audit record is missing or wrong:
1. Confirm the device is configured to send accounting for the required event types (login, exec, command, etc.) and that it points to the expected TACACS server.
2. Check daemon logs and the raw accounting log before looking at the database/UI.
3. Inspect collector service/timer status, journal output, configured input path, tracked offset/file state and duplicate-detection fingerprint logic.
4. Compare the raw record with the inserted `audit_log` row; verify username, device, command, event type, result and timestamp independently.
5. Check year handling for records whose source timestamp omits a year, especially around the December/January boundary.
6. Verify UI filters, pagination, access control and CSV export against the same DB records.
7. Test collector restarts, file rotation/truncation and repeated runs so records are neither silently skipped nor duplicated.

#### Optional installer boundaries and safety

`install/11-tacacs.sh` is optional. It checks for prerequisites including `/usr/sbin/tac_plus`, `/etc/tacacs/tacacs-db.conf`, `/etc/tac_plus.conf`, and PHP PDO/`pdo_mysql`. If prerequisites are absent, the integration may be skipped. The script is designed not to overwrite the existing `/etc/tac_plus.conf` or `/etc/tacacs/tacacs-db.conf`, and not to force the daemon's service state. Preserve these safety boundaries unless the user explicitly approves a different migration plan.

Schema creation with `CREATE TABLE IF NOT EXISTS` does not by itself upgrade existing table definitions. Review migrations and backup/rollback behavior for every schema change.

### D. How the three integrations complement each other during an incident

| Question | Primary evidence | What it can establish | What it cannot establish by itself |
|---|---|---|---|
| What messages did a device send? | Network Syslog raw/event log | Received messages present in stored logs | Messages lost before receipt; commands not logged by the device |
| Who logged in or issued an accounted command? | TACACS+ accounting records and audit UI | Events actually sent by the device and successfully collected | Every command on every platform; identity if the source record lacks it |
| What configuration changed between backups? | Oxidized Git history/diff | Differences between successful configuration snapshots | Exact operator, exact command sequence or every intermediate state |

For an investigation, correlate timestamps and device identity across all three sources. Check clock/time-zone alignment and whether the Syslog source IP, TACACS device mapping and Oxidized node address refer to the same device. Preserve raw records and redact secrets before sharing evidence. Treat correlation as supporting evidence, not automatic proof of causation.

### E. Required validation checklist before calling a feature complete

- **Oxidized:** test a real lab node; confirm SSH/model behavior, manual backup request, scheduled backup, Git commit, config diff and failure logging.
- **Network Syslog:** send a fresh test message; verify both files, discovery JSON, agent key, LLD result, UI read permissions, rotation and behavior for a stale directory.
- **TACACS+:** parser-validate generated config; test allowed and denied commands on each target vendor; verify default-deny/fallback behavior; confirm raw accounting records, collector restart/rotation handling, DB rows, UI filters and CSV access.
- Record OS/Zabbix/PHP/Ruby/tac_plus versions, exact commands, observed results and date. Keep static checks separate from unit tests and live VM/device tests.

## 6. Secrets and safety rules

Never commit any of the following:

- Linux, database, Zabbix, TACACS+, Oxidized or device passwords.
- Telegram bot tokens or Zabbix API tokens.
- SNMP community strings or SNMPv3 credentials.
- SSH private keys, private configuration backups or production router inventory.
- Database dumps, production Syslog, accounting logs or other sensitive exports.

Keep local secret files outside the repository or ensure they are excluded by `.gitignore`. The tracked `secrets/.gitkeep` is only a directory placeholder.

Before changing installation behavior:
1. Identify every file and external system affected.
2. Check idempotency: running a step twice should not duplicate configuration or damage existing data.
3. Back up the target config/database before destructive or schema-changing operations.
4. Avoid printing secret values to terminal logs.
5. Prefer narrow changes over rewriting a whole installer module.
6. Never silently disable security controls or change network interfaces.
7. State clearly whether a check was static or was run on a real Oracle Linux host.

## 7. Validation and test commands

Run static checks from the repository root before committing:

```bash
# Shell syntax
find . -type f -name '*.sh' -print0 | xargs -0 -r -n1 bash -n
find . -type f -path '*/oxidized/oxidized-node-scheduler' -o -path '*/zabbix/network-syslog-discovery' -print

# PHP syntax (requires PHP)
find install -type f -name '*.php' -print0 | xargs -0 -r -n1 php -l

# TACACS+ contract/parser tests (requires PHP and the expected test environment)
php install/tacacs/tests/tacacs-assignment-schema-test.php
php install/tacacs/tests/tacacs-audit-collector-test.php

# Inspect changes
git diff --check
git status --short
git diff -- README.md
```

The second `find` command above is only a quick inventory helper; run `bash -n` explicitly on shell scripts and inspect the output of all checks. Static syntax checks do **not** prove that package installation, SQL migrations, API permissions, systemd units, firewall changes, device authentication or UI workflows work.

### Suggested end-to-end test plan

Use a disposable Oracle Linux 9 VM with a snapshot and working Internet/DNS:

1. Start from a clean VM and record OS/network state.
2. Clone this repository and inspect `install.sh` and every step first.
3. Run the installer interactively; capture logs without exposing secrets.
4. Verify MariaDB, `zabbix-server`, `zabbix-agent`, `httpd`, `rsyslog`, Oxidized and any configured timers.
5. Open `http://<server-ip>:8000/zabbix/`, change the default Admin password and confirm API access.
6. Send a test Syslog message from a lab source; verify the per-IP folder, both log files, ACL and logrotate configuration.
7. Confirm the discovery key returns valid JSON and LLD creates the expected objects; open the Network Syslog frontend module.
8. Test Oxidized against a lab device and verify a configuration backup is committed.
9. Test Telegram problem/recovery notifications, including HTML-special characters and correct links.
10. If TACACS+ is configured, validate the daemon config, test authentication/authorization on a lab device, generate accounting records, and confirm the audit UI/filter/export behavior.
11. Re-run only individual steps that are documented as safe to rerun. Check for duplicate API objects, duplicate config blocks and unexpected service restarts.
12. Document every failure and fix it before calling the full deployment successful.

## 8. Known verification gaps / first things to investigate

These are investigation items, not claims that every one is a confirmed runtime defect:

1. **Clean-install verification:** No end-to-end install has yet been confirmed for this repository's `main`.
2. **Telegram export environment:** `install/09-telegram.sh` references `/etc/zabbix/zabbix-export.env`; find its creator/contract and confirm it exists on a clean install.
3. **Default Zabbix credentials:** `install/10-network-syslog.sh` defaults to `Admin` / `zabbix`. Check behavior if the password was changed in the previous step or the environment already has another admin credential.
4. **SELinux:** `install.sh` disables SELinux. Decide whether this is acceptable for the intended deployment or should be replaced by correct policies/labels.
5. **Network step recovery:** Static-IP changes may sever SSH access; validate the prompt, connection selection and rollback/recovery path.
6. **Installer idempotency:** Verify reruns do not append duplicate Apache/PHP/Zabbix/rsyslog configuration or duplicate API objects.
7. **TACACS+ separation:** Verify the optional TACACS+ installer remains a no-op when prerequisites are absent and does not unintentionally change an existing TACACS+ service/config.
8. **Module compatibility:** Validate frontend module API/action/view behavior against the exact installed Zabbix 7.0 patch release, not only PHP syntax.
9. **Public repository review:** Re-scan tracked files and commit history for secrets before publishing changes.

When a gap is resolved, update this section with the tested environment, command or procedure, observed result, and date. Do not mark a feature “tested” based only on reading its source.

## 9. Git workflow

Use a focused branch and small commits:

```bash
git checkout -b docs/readme-claude-handoff
# edit README.md
git diff --check
git diff -- README.md
git add README.md
git commit -m "docs: rewrite README for project handoff"
git push -u origin docs/readme-claude-handoff
```

Follow the repository's agreed review/push process before merging to `main`. Do not overwrite another developer's changes or force-push shared branches.

## 10. Instructions for Claude (project continuation contract)

When Claude continues this project:

1. **Establish state first.** Confirm repository, branch, commit, dirty working tree and current OS/runtime context. The active repository is `VuTruc/zabbix-deploy`; do not confuse it with the legacy `VuTruc/zabbix-monitoring-deploy`.
2. **Read implementation before editing.** Start with this README, `install.sh`, the relevant numbered installer, then the exact module/controller/view/schema/test files involved.
3. **Do not infer source from memory or an old branch.** Check the current branch contents. If prior discussion conflicts with current code, current code wins unless the user explicitly asks to restore earlier behavior.
4. **Keep the source of truth in Git.** Edit repository files, not only deployed copies under `/etc` or `/usr/share/zabbix/modules`.
5. **Preserve working features.** Make minimal changes; show the affected files and explain behavior changes.
6. **For TACACS+ bugs, trace the full path:** frontend view/form → JS validation/submission → action/controller input validation → DB schema and migration → generated `/etc/tac_plus.conf` → `tac_plus -P` → daemon behavior → accounting collector → audit UI/export. Test policy matching across device types. The current design avoids a single hard-coded privilege level because it must support multiple device vendors; do not reintroduce a universal privilege-level assumption without an explicit design decision.
7. **Audit logging is not proof of every CLI command.** Distinguish TACACS+ accounting records actually received from parsed/displayed fields. Validate what each device sends and clearly identify unavailable data.
8. **For Syslog bugs, follow the end-to-end data path** described above rather than changing only the dashboard.
9. **Run tests and report results honestly.** Separate static checks, unit/contract tests and real VM/device tests. Include exact commands and failures. Never claim end-to-end success if only syntax or source review was done.
10. **Protect secrets and production state.** Redact tokens/passwords from output; do not make live network-device configuration changes unless explicitly requested.
11. **At handoff, report:** commit/branch, files changed, implementation summary, tests run/results, known limitations and the next concrete action.

## 11. Definition of done

A change is ready for review when:
- The implementation matches the stated behavior.
- Relevant shell/PHP syntax and focused tests pass.
- Migration/backward-compatibility behavior is understood.
- Failure paths and permissions are checked.
- No secrets or production data are committed.
- The README or other docs are updated where behavior or operations changed.
- The report distinguishes what was verified statically from what was tested live.

A full deployment is ready to call successful only after the clean-VM end-to-end test plan has passed and the result is recorded.
