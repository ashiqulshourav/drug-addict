-- Madok admin authentication / authorization schema.
-- Apply this AFTER database.example.sql on the same database.

CREATE TABLE IF NOT EXISTS admin_roles (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    slug VARCHAR(80) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_permissions (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_role_permissions (
    role_id SMALLINT UNSIGNED NOT NULL,
    permission_id SMALLINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_admin_rp_role FOREIGN KEY (role_id)
        REFERENCES admin_roles(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_admin_rp_permission FOREIGN KEY (permission_id)
        REFERENCES admin_permissions(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id SMALLINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('active','disabled') NOT NULL DEFAULT 'active',
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_admin_user_role FOREIGN KEY (role_id)
        REFERENCES admin_roles(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_admin_users_role (role_id),
    INDEX idx_admin_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_settings (
    setting_key VARCHAR(120) PRIMARY KEY,
    setting_value TEXT NULL,
    value_type ENUM('string','boolean','integer','json') NOT NULL DEFAULT 'string',
    description VARCHAR(255) NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_admin_setting_user FOREIGN KEY (updated_by)
        REFERENCES admin_users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(80) NULL,
    entity_id BIGINT UNSIGNED NULL,
    description VARCHAR(500) NULL,
    ip_hash CHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_admin_audit_user FOREIGN KEY (admin_user_id)
        REFERENCES admin_users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_admin_audit_user (admin_user_id),
    INDEX idx_admin_audit_entity (entity_type, entity_id),
    INDEX idx_admin_audit_action (action),
    INDEX idx_admin_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO admin_roles (name, slug, description, is_system) VALUES
('Super Admin', 'super-admin', 'Full access to the Madok administration panel.', 1),
('Manager', 'manager', 'Operational management access.', 1),
('Moderator', 'moderator', 'Moderation and review access.', 1);

INSERT IGNORE INTO admin_permissions (name, description) VALUES
('dashboard.view', 'View the admin dashboard.'),
('reports.view', 'View reports.'),
('reports.create', 'Create reports from the admin panel.'),
('reports.edit', 'Edit reports.'),
('reports.delete', 'Soft-delete reports.'),
('reports.permanent_delete', 'Permanently delete reports.'),
('reports.restore', 'Restore deleted reports.'),
('locations.view', 'View locations.'),
('locations.edit', 'Edit locations.'),
('locations.delete', 'Soft-delete locations.'),
('locations.permanent_delete', 'Permanently delete locations.'),
('locations.restore', 'Restore deleted locations.'),
('users.view', 'View admin users.'),
('users.create', 'Create admin users.'),
('users.edit', 'Edit admin users.'),
('users.delete', 'Disable admin users.'),
('roles.manage', 'Manage roles and permissions.'),
('settings.view', 'View admin settings.'),
('settings.manage', 'Change admin settings.'),
('audit.view', 'View audit logs.'),
('statistics.view', 'View statistics.');

-- Super Admin receives every permission.
INSERT IGNORE INTO admin_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM admin_roles r
CROSS JOIN admin_permissions p
WHERE r.slug = 'super-admin';

-- Manager receives operational permissions but not role management.
INSERT IGNORE INTO admin_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM admin_roles r
JOIN admin_permissions p ON p.name IN (
    'dashboard.view','reports.view','reports.edit','reports.delete','reports.restore',
    'locations.view','locations.edit','locations.delete','locations.restore',
    'statistics.view','audit.view','settings.view'
)
WHERE r.slug = 'manager';

-- Moderator can review/edit but cannot delete or manage users/settings.
INSERT IGNORE INTO admin_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM admin_roles r
JOIN admin_permissions p ON p.name IN (
    'dashboard.view','reports.view','reports.edit',
    'locations.view','locations.edit','statistics.view'
)
WHERE r.slug = 'moderator';

-- Deletion can be disabled globally without changing role definitions.
INSERT IGNORE INTO admin_settings
(setting_key, setting_value, value_type, description)
VALUES
('reports.allow_delete', 'true', 'boolean', 'Allow users with reports.delete to soft-delete reports.'),
('locations.allow_delete', 'true', 'boolean', 'Allow users with locations.delete to soft-delete locations.'),
('deletion.require_confirmation', 'true', 'boolean', 'Require a confirmation dialog before deletion.'),
('deletion.soft_delete', 'true', 'boolean', 'Use recoverable soft deletion instead of immediate permanent deletion.');
