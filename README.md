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
