-- Schema MySQL para persistência de sessões, histórico, tool calls, checkpoints e configurações

CREATE DATABASE IF NOT EXISTS claude_code
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE claude_code;

CREATE TABLE IF NOT EXISTS sessions (
    id CHAR(36) NOT NULL PRIMARY KEY,
    workspace_root VARCHAR(512) NOT NULL,
    model VARCHAR(128) NOT NULL,
    permission_mode VARCHAR(32) NOT NULL DEFAULT 'ask',
    title VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    session_id CHAR(36) NOT NULL,
    role ENUM('user', 'assistant') NOT NULL,
    content_json LONGTEXT NOT NULL,
    input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_messages_session (session_id, id),
    CONSTRAINT fk_messages_session
        FOREIGN KEY (session_id) REFERENCES sessions(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tool_calls (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    session_id CHAR(36) NOT NULL,
    tool_use_id VARCHAR(128) NOT NULL,
    tool_name VARCHAR(64) NOT NULL,
    input_json LONGTEXT NOT NULL,
    output_text LONGTEXT NULL,
    status ENUM('pending', 'approved', 'denied', 'executed', 'error') NOT NULL DEFAULT 'pending',
    duration_ms INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tool_calls_session (session_id, id),
    CONSTRAINT fk_tool_calls_session
        FOREIGN KEY (session_id) REFERENCES sessions(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS checkpoints (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    session_id CHAR(36) NOT NULL,
    message_id BIGINT UNSIGNED NULL,
    file_path VARCHAR(512) NOT NULL,
    previous_content LONGTEXT NULL,
    file_existed TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_checkpoints_session (session_id, id),
    CONSTRAINT fk_checkpoints_session
        FOREIGN KEY (session_id) REFERENCES sessions(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(128) NOT NULL PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
