# Zabbix Monitoring Deployment
# Bộ triển khai Zabbix Monitoring

---

## 1. Purpose
## 1. Mục đích

This repository provides an automated deployment framework for building a
centralized monitoring server on a clean Oracle Linux installation.

Repository này cung cấp bộ triển khai tự động để xây dựng một máy chủ
monitoring tập trung trên nền Oracle Linux mới.

The target monitoring platform includes:

Nền tảng monitoring bao gồm:

- Zabbix Server
- Zabbix Agent
- MariaDB
- Apache
- PHP
- Centralized Syslog / rsyslog
- Logrotate
- Oxidized
- Telegram notification
- Zabbix configuration

The goal is to allow an administrator to deploy a new monitoring server
without manually repeating the complete installation process.

Mục tiêu là cho phép quản trị viên triển khai một monitoring server mới
mà không phải lặp lại toàn bộ quá trình cài đặt thủ công.

---

## 2. Important Warning
## 2. Cảnh báo quan trọng

This repository is designed primarily for a NEW Oracle Linux server.

Repository này chủ yếu được thiết kế cho một máy Oracle Linux MỚI.

Do NOT run the complete installer on an existing production Zabbix Server
unless the installer documentation explicitly states that the operation
is safe.

KHÔNG chạy toàn bộ installer trên Zabbix Server production đang hoạt động
trừ khi tài liệu của installer xác nhận rõ thao tác đó an toàn.

Always test changes on a separate server before applying them to production.

Luôn kiểm tra thay đổi trên một server riêng trước khi áp dụng vào
production.

---

## 3. Deployment Architecture
## 3. Kiến trúc triển khai

The deployment process is:

Quy trình triển khai:

    Oracle Linux
         |
         v
    Git clone
         |
         v
    ./install.sh
         |
         +-- System preparation
         |
         +-- MariaDB
         |
         +-- Zabbix Server
         |
         +-- Zabbix Agent
         |
         +-- Apache / PHP
         |
         +-- Syslog / rsyslog
         |
         +-- Logrotate
         |
         +-- Oxidized
         |
         +-- Telegram
         |
         v
    Monitoring Server

The installer is modular.

Installer được thiết kế theo module.

Each major component has a separate installation/configuration script.

Mỗi thành phần chính có script cài đặt/cấu hình riêng.

---

## 4. Requirements
## 4. Yêu cầu

The target server should have:

Server đích cần có:

- Oracle Linux
- Network connectivity
- DNS or working name resolution
- Internet access to package repositories
- Sufficient CPU, RAM and disk space
- Root or sudo access

The exact resource requirements depend on the number of monitored devices
and the amount of Syslog data.

Yêu cầu tài nguyên cụ thể phụ thuộc vào số lượng thiết bị được giám sát
và dung lượng Syslog.

---

## 5. Installation Workflow
## 5. Quy trình cài đặt

The administrator should follow this general sequence:

Quản trị viên thực hiện theo trình tự:

1. Install Oracle Linux.
2. Configure basic network connectivity.
3. Obtain access to the GitHub repository.
4. Clone this repository.
5. Run the installer.
6. Enter server-specific information.
7. Allow the installer to install required packages.
8. Verify all services.
9. Access the Zabbix frontend.
10. Add monitoring hosts separately.

1. Cài Oracle Linux.
2. Cấu hình kết nối mạng cơ bản.
3. Có quyền truy cập GitHub repository.
4. Clone repository.
5. Chạy installer.
6. Nhập thông tin riêng của server.
7. Để installer tự cài các package cần thiết.
8. Kiểm tra toàn bộ service.
9. Truy cập giao diện Zabbix.
10. Thêm các thiết bị monitoring sau.

---

## 6. Information Required During Installation
## 6. Thông tin cần nhập khi cài đặt

The installer must request server-specific information interactively.

Installer phải yêu cầu nhập các thông tin riêng của server.

Typical information includes:

Thông tin thường bao gồm:

- Hostname — Tên máy chủ
- IP address — Địa chỉ IP
- Network prefix — Prefix mạng
- Default gateway — Gateway mặc định
- DNS server — DNS
- Linux administrator username — Tài khoản quản trị Linux
- Linux administrator password — Mật khẩu quản trị Linux
- MariaDB password — Mật khẩu MariaDB
- Zabbix database password — Mật khẩu database Zabbix
- Zabbix administrator password — Mật khẩu quản trị Zabbix
- Telegram Bot Token — Token Telegram
- Telegram Chat ID — Chat ID Telegram
- Oxidized credentials — Thông tin đăng nhập Oxidized khi cần

These values must not be hard-coded in the repository.

Các thông tin này không được hard-code trong repository.

---

## 7. Secrets Policy
## 7. Chính sách bảo mật thông tin

The following information must NEVER be committed to Git:

Các thông tin sau TUYỆT ĐỐI KHÔNG được commit vào Git:

- Passwords
- Database passwords
- SNMP community strings
- SNMPv3 authentication credentials
- SNMPv3 privacy credentials
- Telegram Bot Tokens
- API tokens
- SSH private keys
- Other production secrets

Secrets must be entered interactively or stored in local files excluded
by `.gitignore`.

Secret phải được nhập trực tiếp hoặc lưu trong file local được loại trừ
bởi `.gitignore`.

The `secrets/` directory is intentionally excluded from Git except for
`.gitkeep`.

Thư mục `secrets/` được cố ý loại khỏi Git, ngoại trừ file `.gitkeep`.

---

## 8. Zabbix
## 8. Zabbix

The installer will deploy the Zabbix monitoring platform.

Installer sẽ triển khai nền tảng Zabbix monitoring.

Expected components:

Các thành phần dự kiến:

- Zabbix Server
- Zabbix Agent
- Zabbix Frontend
- Zabbix API
- MariaDB
- Apache
- PHP

The installer will configure the database connection and required services.

Installer sẽ cấu hình kết nối database và các service cần thiết.

---

## 9. Database
## 9. Database

MariaDB is used as the Zabbix database.

MariaDB được sử dụng làm database cho Zabbix.

The installer will:

Installer sẽ:

1. Install MariaDB.
2. Enable MariaDB at boot.
3. Start MariaDB.
4. Create the Zabbix database.
5. Create the Zabbix database user.
6. Import the Zabbix schema.
7. Configure Zabbix Server.
8. Verify database connectivity.

---

## 10. Network Configuration
## 10. Cấu hình mạng

The administrator must provide the network information for the new
monitoring server.

Quản trị viên phải cung cấp thông tin mạng của monitoring server mới.

Example:

Ví dụ:

    Hostname: zabbix-server
    IP address: 192.168.18.57
    Prefix: /24
    Gateway: 192.168.18.1
    DNS: 8.8.8.8

The installer should verify:

Installer phải kiểm tra:

- IP address
- Default route
- DNS resolution
- Internet connectivity
- Repository connectivity

---

## 11. Centralized Syslog
## 11. Syslog tập trung

The monitoring server also acts as a centralized Syslog server.

Monitoring server đồng thời đóng vai trò Syslog server tập trung.

Network devices can send Syslog messages to the monitoring server using
UDP port 514.

Các thiết bị mạng có thể gửi Syslog đến monitoring server thông qua
UDP port 514.

The expected architecture is:

Kiến trúc dự kiến:

    Network Device
         |
         | UDP 514
         v
    rsyslog Server
         |
         +-- /var/log/network/<device-ip>/syslog.log
         |
         +-- /var/log/network/<device-ip>/events.log

The full `syslog.log` file is retained as the complete log evidence.

File `syslog.log` được giữ lại để lưu toàn bộ bằng chứng log.

The `events.log` file is a filtered log intended for easier investigation
of important device events.

File `events.log` là log được lọc nhằm hỗ trợ việc kiểm tra các sự kiện
quan trọng của thiết bị.

---

## 12. Syslog Log Types
## 12. Các loại Syslog

The deployment supports two main log files per network device.

Hệ thống hỗ trợ hai file log chính cho mỗi thiết bị mạng.

### Full Syslog

    /var/log/network/<device-ip>/syslog.log

This file contains the complete Syslog stream received from the device.

File này chứa toàn bộ Syslog nhận được từ thiết bị.

It should normally be preserved for troubleshooting and forensic analysis.

Thông thường file này cần được giữ lại để troubleshooting và phân tích
sự cố.

### Event Syslog

    /var/log/network/<device-ip>/events.log

This file contains filtered messages considered useful for event analysis.

File này chứa các message được lọc để thuận tiện phân tích sự kiện.

The initial implementation includes filtering for messages containing:

    type="event"

This filter is primarily based on the current FortiGate Syslog format.

Bộ lọc này chủ yếu dựa trên format Syslog hiện tại của FortiGate.

Vendor-specific filters may be required for Cisco, DrayTek, MikroTik or
other network devices.

Các thiết bị Cisco, DrayTek, MikroTik hoặc vendor khác có thể cần bộ lọc
riêng.

---

## 13. Syslog and Zabbix
## 13. Syslog và Zabbix

Zabbix and Syslog have different roles.

Zabbix và Syslog có vai trò khác nhau.

Zabbix is primarily responsible for:

Zabbix chủ yếu chịu trách nhiệm:

- Availability
- Ping
- SNMP monitoring
- CPU
- Memory
- Interface traffic
- Interface errors
- Device health
- Alerts

Syslog is primarily responsible for:

Syslog chủ yếu chịu trách nhiệm:

- Event evidence
- Interface state changes
- Authentication events
- Configuration changes
- Security events
- System messages
- Root-cause investigation

The recommended workflow is:

Quy trình khuyến nghị:

    Device failure
         |
         +-- Zabbix detects availability problem
         |
         +-- SNMP shows device/interface status
         |
         +-- Syslog provides event evidence
         |
         v
    Troubleshooting

Syslog should not automatically create a Zabbix trigger for every message.

Không nên tự động tạo Zabbix trigger cho mọi Syslog message.

---

## 14. Syslog ACL
## 14. ACL cho Syslog

The Zabbix service account may need read access to centralized Syslog files.

Tài khoản service `zabbix` có thể cần quyền đọc các file Syslog tập trung.

The deployment should configure appropriate filesystem permissions or ACLs.

Installer phải cấu hình permission hoặc ACL phù hợp.

Example target:

    /var/log/network/

The exact ACL implementation may depend on the Oracle Linux environment.

Cách triển khai ACL cụ thể có thể phụ thuộc vào môi trường Oracle Linux.

The goal is to allow Zabbix to read required log files without giving
unnecessary write or administrative permissions.

Mục tiêu là cho phép Zabbix đọc log cần thiết mà không cấp quyền ghi hoặc
quyền quản trị không cần thiết.

---

## 15. Logrotate
## 15. Logrotate

Network Syslog data can grow quickly.

Dữ liệu Syslog mạng có thể tăng rất nhanh.

The deployment therefore configures logrotate for network logs.

Installer sẽ cấu hình logrotate cho các log mạng.

Expected policy:

Chính sách dự kiến:

    /var/log/network/*/*.log {
        daily
        rotate 90
        missingok
        notifempty
        compress
        delaycompress
        copytruncate
    }

The policy provides approximately 90 rotation periods when the logs are
rotated daily.

Chính sách này cung cấp khoảng 90 kỳ rotation khi log được rotate hàng ngày.

The exact retention period may be adjusted later.

Thời gian lưu trữ có thể được điều chỉnh sau.

---

## 16. Automatic New Device Log Directory
## 16. Tự động tạo thư mục log cho thiết bị mới

The rsyslog configuration should dynamically create directories based on
the source IP address.

Cấu hình rsyslog sẽ tự động tạo thư mục dựa trên IP nguồn.

Example:

    /var/log/network/192.168.18.1/syslog.log

If a new network device sends Syslog, its directory should be created
automatically.

Khi một thiết bị mạng mới gửi Syslog, thư mục tương ứng sẽ được tạo
tự động.

The wildcard logrotate configuration then applies to the new device.

Cấu hình logrotate wildcard sẽ tự động áp dụng cho thiết bị mới.

No manual logrotate entry should be required for every new device.

Không cần tạo riêng cấu hình logrotate cho từng thiết bị mới.

---

## 17. Oxidized
## 17. Oxidized

Oxidized is used for automated network device configuration backup.

Oxidized được sử dụng để tự động backup cấu hình thiết bị mạng.

The deployment should provide:

Installer cần cung cấp:

- Oxidized installation
- Service configuration
- Repository/storage configuration
- Device inventory configuration
- Credential configuration
- Scheduled polling
- Configuration history

Production credentials must not be stored in Git.

Thông tin đăng nhập production không được lưu trong Git.

Credentials should be entered during deployment or supplied through a local
secret configuration.

Thông tin đăng nhập phải được nhập khi triển khai hoặc cung cấp thông qua
file secret local.

---

## 18. Telegram Notification
## 18. Thông báo Telegram

Telegram is used as one of the notification channels for Zabbix.

Telegram được sử dụng làm một trong các kênh thông báo của Zabbix.

The installer should request:

Installer phải yêu cầu:

- Telegram Bot Token
- Telegram Chat ID

These values must never be hard-coded into public configuration files.

Các giá trị này tuyệt đối không được hard-code trong file cấu hình public.

The installer should preferably write the values into a local secret file
with restricted permissions.

Installer nên lưu các giá trị vào file secret local với permission hạn chế.

Example:

    chmod 600 /etc/zabbix/telegram.env

The bot token must be treated as a secret.

Bot token phải được xem là thông tin bí mật.

If a production bot token is ever exposed publicly, it should be revoked
and replaced.

Nếu production bot token bị lộ công khai, cần revoke và tạo token mới.

---

## 19. Zabbix Hosts
## 19. Zabbix Hosts

Network devices should NOT automatically be created during the base
monitoring server installation.

Các thiết bị mạng KHÔNG nên được tự động tạo trong quá trình cài
monitoring server cơ bản.

The base installation only prepares the Zabbix platform.

Cài đặt cơ bản chỉ chuẩn bị nền tảng Zabbix.

Network devices are added separately.

Thiết bị mạng được thêm sau.

This prevents the installer from creating incorrect or unwanted hosts.

Điều này tránh việc installer tạo host sai hoặc không mong muốn.

---

## 20. SNMP
## 20. SNMP

SNMP is the preferred mechanism for continuous monitoring of network
devices.

SNMP là cơ chế ưu tiên để giám sát liên tục các thiết bị mạng.

Typical monitoring includes:

Thông thường sẽ giám sát:

- Device availability
- CPU
- Memory
- Interface status
- Interface traffic
- Interface errors
- Packet counters
- Device uptime
- Hardware sensors when supported

SNMP community strings must not be stored in GitHub.

SNMP community string không được lưu trong GitHub.

SNMPv3 credentials must also be treated as secrets.

Thông tin xác thực SNMPv3 cũng phải được xem là secret.

---

## 21. Zabbix Syslog Monitoring
## 21. Giám sát Syslog bằng Zabbix

Zabbix can read selected centralized Syslog files using log monitoring
items.

Zabbix có thể đọc các file Syslog tập trung bằng log monitoring item.

Example:

    logrt[] 

A typical design is to create the Syslog Server as a Zabbix host.

Một thiết kế điển hình là tạo Syslog Server thành một Zabbix host.

The Zabbix Agent on that host can provide access to the required log files.

Zabbix Agent trên host đó có thể cung cấp quyền truy cập đến các file log
cần thiết.

Example:

    /var/log/network/192.168.18.1/events.log

The exact Zabbix item and trigger configuration should be created
separately from the base installer.

Item và trigger cụ thể nên được tạo riêng với installer cơ bản.

---

## 22. Installation Stages
## 22. Các giai đoạn cài đặt

The installer should execute the following stages:

Installer nên thực hiện các giai đoạn:

1. Pre-installation checks
2. System preparation
3. Hostname configuration
4. Network configuration
5. Repository configuration
6. Package installation
7. MariaDB installation
8. Zabbix installation
9. Apache installation
10. PHP installation
11. Zabbix database configuration
12. Zabbix Server configuration
13. Zabbix Agent configuration
14. Syslog configuration
15. ACL configuration
16. Logrotate configuration
17. Oxidized installation
18. Telegram configuration
19. Service enable/start
20. Final verification

---

## 23. Pre-installation Checks
## 23. Kiểm tra trước khi cài đặt

The installer should verify:

Installer phải kiểm tra:

- Running Oracle Linux version
- Root/sudo privileges
- Internet connectivity
- DNS resolution
- Available disk space
- Available memory
- Required ports
- Existing conflicting services
- Existing Zabbix installation

The installer should stop or warn when it detects an existing production
installation.

Installer phải dừng hoặc cảnh báo khi phát hiện hệ thống production
đang tồn tại.

---

## 24. Installation Verification
## 24. Kiểm tra sau cài đặt

After installation, the administrator should verify:

Sau khi cài đặt, quản trị viên phải kiểm tra:

    systemctl status mariadb
    systemctl status zabbix-server
    systemctl status zabbix-agent
    systemctl status httpd
    systemctl status rsyslog
    systemctl status oxidized

The administrator should also verify:

Ngoài ra cần kiểm tra:

- Database connectivity
- Zabbix Server log
- Apache status
- PHP functionality
- Zabbix frontend
- UDP 514 Syslog listener
- Telegram notification
- Oxidized service
- Logrotate configuration

---

## 25. Repository Structure
## 25. Cấu trúc repository

The repository is organized into independent modules.

Repository được tổ chức thành các module độc lập.

    zabbix-monitoring-deploy/
    |
    +-- install/
    |   +-- 01-system.sh
    |   +-- 02-network.sh
    |   +-- 03-mariadb.sh
    |   +-- 04-zabbix.sh
    |   +-- 05-apache-php.sh
    |   +-- 06-rsyslog.sh
    |   +-- 07-logrotate.sh
    |   +-- 08-oxidized.sh
    |   +-- 09-telegram.sh
    |
    +-- config/
    |   +-- zabbix/
    |   +-- rsyslog/
    |   +-- logrotate/
    |   +-- oxidized/
    |   +-- telegram/
    |
    +-- scripts/
    |
    +-- templates/
    |
    +-- secrets/
    |   +-- .gitkeep
    |
    +-- install.sh
    +-- README.md
    +-- .gitignore

The exact module names may change during development.

Tên module cụ thể có thể thay đổi trong quá trình phát triển.

---

## 26. Main Installer
## 26. Installer chính

The root installer should remain small.

Installer chính ở thư mục root nên được giữ đơn giản.

Example workflow:

Quy trình ví dụ:

    ./install.sh

The main installer should:

Installer chính sẽ:

1. Check privileges.
2. Collect input.
3. Validate input.
4. Store temporary runtime values securely.
5. Execute modules in order.
6. Stop on critical failure.
7. Report successful stages.
8. Run final verification.

Each module should return a meaningful exit status.

Mỗi module phải trả về exit status rõ ràng.

---

## 27. Configuration Templates
## 27. Template cấu hình

Production configuration should be converted into reusable templates.

Cấu hình production cần được chuyển thành template có thể tái sử dụng.

Templates must not contain:

Template không được chứa:

- Production passwords
- Production tokens
- Private keys
- SNMP secrets
- API tokens
- Personal credentials

Values should be injected during installation.

Giá trị cụ thể sẽ được đưa vào trong quá trình cài đặt.

---

## 28. Git Workflow
## 28. Quy trình Git

The repository uses Git for version control.

Repository sử dụng Git để quản lý phiên bản.

Recommended workflow:

Quy trình khuyến nghị:

    git status
    git add .
    git commit -m "Description"
    git push

Before every commit:

Trước mỗi commit:

1. Check `.gitignore`.
2. Check for passwords.
3. Check for tokens.
4. Check for SNMP credentials.
5. Check for private keys.
6. Review changed files.

Never commit production secrets.

Không bao giờ commit production secret.

---

## 29. Backup and Restore
## 29. Backup và Restore

The deployment project should support backup of important configuration.

Project triển khai nên hỗ trợ backup các cấu hình quan trọng.

Potential backup targets:

Các thành phần cần backup:

- Zabbix database
- Zabbix configuration
- rsyslog configuration
- Logrotate configuration
- Oxidized configuration
- Oxidized configuration repository
- Telegram configuration
- Local deployment secrets

Secrets should be backed up separately and securely.

Secret phải được backup riêng và bảo vệ an toàn.

---

## 30. Adding a New Network Device
## 30. Thêm thiết bị mạng mới

The expected workflow is:

Quy trình dự kiến:

1. Configure the network device hostname.
2. Configure management IP.
3. Configure SNMP.
4. Configure Syslog destination.
5. Configure Oxidized credentials if required.
6. Send Syslog to the monitoring server.
7. Verify the Syslog directory is created.
8. Create the Zabbix host.
9. Configure SNMP interfaces/items.
10. Configure triggers.
11. Verify Telegram notifications.

Example Syslog destination:

    Monitoring Server: 192.168.18.57
    Port: UDP 514

After the first Syslog message is received, rsyslog should create:

    /var/log/network/<device-ip>/syslog.log

The event log may also be created automatically.

File events.log cũng có thể được tạo tự động.

---

## 31. Troubleshooting
## 31. Xử lý sự cố

### Zabbix Server does not start

Check:

    systemctl status zabbix-server
    journalctl -u zabbix-server
    tail -100 /var/log/zabbix/zabbix_server.log

Verify:

- Database hostname
- Database name
- Database username
- Database password
- Database service
- Schema import

### MariaDB does not start

Check:

    systemctl status mariadb
    journalctl -u mariadb

Verify:

- Disk space
- Existing MariaDB process
- Database directory
- Configuration files

### Apache does not start

Check:

    systemctl status httpd
    journalctl -u httpd

Verify:

- Port conflict
- PHP configuration
- Apache configuration

### Syslog is not received

Check:

    ss -lunp | grep 514

Then inspect:

    /var/log/network/

Verify:

- UDP 514 firewall rule
- rsyslog service
- Network device Syslog destination
- Network connectivity

### Telegram does not send

Verify:

- Bot token
- Chat ID
- Internet connectivity
- Zabbix media type
- Zabbix action
- Zabbix event permissions

Do not print production bot tokens during troubleshooting.

Không hiển thị production bot token khi troubleshooting.

---

## 32. Security Policy
## 32. Chính sách bảo mật

The deployment project must follow the principle of least privilege.

Project phải tuân theo nguyên tắc cấp quyền tối thiểu.

The installer should:

Installer nên:

- Restrict secret file permissions
- Avoid passwords in command history where possible
- Avoid printing secrets
- Avoid committing secrets
- Validate user input
- Avoid unnecessary open ports
- Enable only required services
- Use secure credentials
- Document privileged operations

---

## 33. Development Policy
## 33. Chính sách phát triển

All installation modules should be:

Các module cài đặt phải:

- Idempotent where practical
- Clearly documented
- Independently testable
- Safe to re-run where possible
- Fail clearly on critical errors
- Free of production secrets

Scripts should avoid destructive operations unless explicitly documented.

Script không được thực hiện thao tác phá hủy nếu chưa được ghi rõ trong
documentation.

---

## 34. Current Project Status
## 34. Trạng thái hiện tại

The repository is currently being rebuilt as a clean deployment project.

Repository hiện đang được xây dựng lại thành một project deployment sạch.

The production Zabbix server is used as the configuration reference.

Zabbix production được sử dụng làm nguồn tham khảo cấu hình.

Production secrets are intentionally excluded.

Production secrets được cố ý loại khỏi repository.

The initial development priority is:

Ưu tiên phát triển ban đầu:

1. Repository structure
2. README
3. Git ignore policy
4. Installation framework
5. System preparation
6. MariaDB
7. Zabbix
8. Apache/PHP
9. Centralized Syslog
10. Logrotate
11. Oxidized
12. Telegram
13. Verification
14. Backup/restore

---

## 35. Final Goal
## 35. Mục tiêu cuối cùng

The final goal is to allow a new Oracle Linux server to be deployed with a
simple workflow:

Mục tiêu cuối cùng là cho phép triển khai một Oracle Linux server mới bằng
quy trình đơn giản:

    git clone git@github.com:VuTruc/zabbix-monitoring-deploy.git

    cd zabbix-monitoring-deploy

    ./install.sh

The installer should collect the required server-specific information and
then automatically build the monitoring platform.

Installer sẽ hỏi các thông tin cần thiết của server và sau đó tự động xây
dựng toàn bộ nền tảng monitoring.

After installation, network devices can be added separately.

Sau khi cài đặt, các thiết bị mạng sẽ được thêm riêng.

The deployment project is intended to make rebuilding the monitoring server
repeatable, documented and maintainable.

Project này nhằm biến việc dựng lại monitoring server thành một quy trình
có thể lặp lại, có tài liệu và dễ bảo trì.

---

## 36. TACACS+ Integration
## 36. Tích hợp TACACS+

The deployment workflow includes an optional TACACS+ integration stage through
`install/11-tacacs.sh`. The stage is skipped safely when the TACACS+ daemon or
required configuration files are not present.

Quy trình triển khai có bước tích hợp TACACS+ tùy chọn tại
`install/11-tacacs.sh`. Bước này tự bỏ qua an toàn nếu daemon TACACS+ hoặc
các file cấu hình bắt buộc chưa tồn tại.

The integration includes:

Tích hợp bao gồm:

- A Zabbix frontend module for TACACS+ users, devices, policies and assignments.
- A MariaDB schema for TACACS+ data and audit records.
- A PHP configuration generator source file.
- Validation of the existing TACACS+ daemon configuration.

The installer backs up an existing Zabbix TACACS+ module before updating its
source files. It does not automatically run the configuration generator and
does not overwrite `/etc/tac_plus.conf` or `/etc/tacacs/tacacs-db.conf`.
Missing database tables are created only with `CREATE TABLE IF NOT EXISTS`;
existing records are not intentionally dropped or reset.

Cài đặt sẽ sao lưu module TACACS+ hiện có trước khi cập nhật source. Installer
không tự chạy trình tạo cấu hình và không ghi đè
`/etc/tac_plus.conf` hoặc `/etc/tacacs/tacacs-db.conf`. Các bảng còn thiếu
chỉ được tạo bằng `CREATE TABLE IF NOT EXISTS`; dữ liệu hiện có không bị
chủ động xóa hoặc khởi tạo lại.

After deployment, verify the TACACS+ module in the Zabbix frontend and test
authentication with a non-production account and device before using it in
production. Enabling the daemon at boot is a separate administrative action:

Sau khi triển khai, hãy kiểm tra module trong giao diện Zabbix và thử xác thực
bằng tài khoản cùng thiết bị thử nghiệm trước khi sử dụng trong môi trường
production. Bật daemon tự khởi động là thao tác quản trị riêng:

`systemctl enable tac_plus`

## TACACS+ Assignment v2 and audit automation

The Assignment model stores one access mode per User–Device pair:

- `access_mode='all'` with `policy_id=NULL` means **Allow All**. It is an Assignment mode, not a Policy row.
- `access_mode='policy'` with a non-NULL `policy_id` references an enabled Policy.
- The installer runs `install/tacacs/migrate-assignments-v2.php`. Migration creates a pre-migration table backup and stops before schema changes if a legacy User–Device pair has multiple Policy assignments; an administrator must resolve duplicates explicitly.
- The generator validates candidate config with `tac_plus -C <temporary-file> -P` before atomically replacing `/etc/tac_plus.conf`. It does not restart or reload the running daemon.
- The installer enables `tacacs-audit-collector.timer` when systemd is available. Audit rows still require device accounting to be configured on each network device.

**Device authorization limitation:** per-command Policy rules require the NAS to send command-authorization requests. Cisco Business 350/SG350 documentation describes authorization at login and accounting for login sessions; the generic command-rule generator cannot guarantee arbitrary per-command enforcement on that platform. FortiGate authorization requires Fortinet attributes such as `memberof` and `admin_prof`; mapping to FortiGate administrator profiles is device-specific and must be validated before claiming enforcement. Test target-device behavior before merging to production.

### tac_plus command-rule semantics

In `tac_plus`, `cmd = show` identifies the literal command name; the regex entries beneath it match command arguments. A stanza such as `cmd = .*` is not a wildcard for all command names. The generator therefore sets `default service = permit` for Allow All and `default service = deny` for Policy mode, explicitly authorizes the management EXEC service, and emits command-specific Policy blocks only. The EXEC block uses `default attribute = permit` so NAS-supplied service attributes can pass through without hard-coding vendor-specific privilege attributes. Unknown command names default to deny under Policy mode. This config-level behavior still depends on the network device requesting command authorization.
