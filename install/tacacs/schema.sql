CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(128) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_users_username (username),
    KEY idx_users_enabled (enabled)
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS devices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(128) NOT NULL,
    address VARCHAR(255) NOT NULL,
    device_type VARCHAR(64) NOT NULL DEFAULT 'network',
    shared_secret VARCHAR(255) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    description VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_devices_name (name),
    UNIQUE KEY uk_devices_address (address),
    KEY idx_devices_enabled (enabled)
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS policies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(128) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_policies_name (name),
    KEY idx_policies_enabled (enabled)
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS policy_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    policy_id BIGINT UNSIGNED NOT NULL,
    command VARCHAR(128) NOT NULL,
    command_args VARCHAR(512) NOT NULL DEFAULT '',
    action ENUM('permit', 'deny') NOT NULL DEFAULT 'deny',
    rule_order INT NOT NULL DEFAULT 100,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_policy_rules_policy (policy_id),
    KEY idx_policy_rules_order (policy_id, rule_order),
    CONSTRAINT fk_policy_rules_policy
        FOREIGN KEY (policy_id)
        REFERENCES policies(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS user_devices (
    user_id BIGINT UNSIGNED NOT NULL,
    device_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, device_id),
    CONSTRAINT fk_user_devices_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_user_devices_device
        FOREIGN KEY (device_id)
        REFERENCES devices(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS user_policies (
    user_id BIGINT UNSIGNED NOT NULL,
    policy_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, policy_id),
    CONSTRAINT fk_user_policies_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_user_policies_policy
        FOREIGN KEY (policy_id)
        REFERENCES policies(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS device_policies (
    device_id BIGINT UNSIGNED NOT NULL,
    policy_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (device_id, policy_id),
    CONSTRAINT fk_device_policies_device
        FOREIGN KEY (device_id)
        REFERENCES devices(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_device_policies_policy
        FOREIGN KEY (policy_id)
        REFERENCES policies(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    username VARCHAR(128) DEFAULT NULL,
    device_address VARCHAR(255) DEFAULT NULL,
    device_name VARCHAR(128) DEFAULT NULL,
    event_type ENUM(
        'authentication',
        'authorization',
        'accounting',
        'config',
        'system'
    ) NOT NULL,
    action VARCHAR(128) DEFAULT NULL,
    command VARCHAR(128) DEFAULT NULL,
    command_args VARCHAR(512) DEFAULT NULL,
    result ENUM(
        'success',
        'denied',
        'failed',
        'info'
    ) NOT NULL,
    message VARCHAR(1000) DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_audit_time (event_time),
    KEY idx_audit_user (username),
    KEY idx_audit_device (device_address),
    KEY idx_audit_result (result),
    KEY idx_audit_event_type (event_type)
) ENGINE=InnoDB;

-- One access mode per user/device pair.
-- Allow All is an Assignment mode, not a Policy row.
CREATE TABLE IF NOT EXISTS assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    device_id BIGINT UNSIGNED NOT NULL,
    policy_id BIGINT UNSIGNED DEFAULT NULL,
    access_mode ENUM('policy','all') NOT NULL DEFAULT 'policy',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_assignments_user_device (user_id, device_id),
    KEY idx_assignments_device_policy (device_id, policy_id),
    CONSTRAINT fk_assignments_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_assignments_device
        FOREIGN KEY (device_id) REFERENCES devices(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_assignments_policy
        FOREIGN KEY (policy_id) REFERENCES policies(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;


-- Separate password protection for the TACACS+ Assignments page.
CREATE TABLE IF NOT EXISTS assignment_page_security (
    id TINYINT UNSIGNED NOT NULL,
    password_hash VARCHAR(255) DEFAULT NULL,
    recovery_hash VARCHAR(255) DEFAULT NULL,
    failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME DEFAULT NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB;
