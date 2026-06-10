CREATE DATABASE IF NOT EXISTS portal_wct CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE portal_wct;

CREATE TABLE IF NOT EXISTS api_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    app_id VARCHAR(80) NOT NULL,
    client_secret VARCHAR(200) NOT NULL,
    redirect_uri VARCHAR(255) DEFAULT NULL,
    seller_id VARCHAR(80) NOT NULL,
    oauth_code VARCHAR(255) DEFAULT NULL,
    lexos_code TEXT DEFAULT NULL,
    lexos_token TEXT DEFAULT NULL,
    lexos_refresh_token TEXT DEFAULT NULL,
    lexos_integration_key TEXT DEFAULT NULL,
    lexos_integration_header_name VARCHAR(120) DEFAULT NULL,
    tracking_database_url TEXT DEFAULT NULL,
    lexos_credentials_mode VARCHAR(20) DEFAULT 'auto',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS oauth_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    access_token TEXT NOT NULL,
    refresh_token TEXT NOT NULL,
    token_type VARCHAR(30) DEFAULT 'Bearer',
    scope TEXT DEFAULT NULL,
    expires_in INT NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS message_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(120) NOT NULL,
    body TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS message_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id VARCHAR(80) NOT NULL,
    receiver_id VARCHAR(80) NOT NULL,
    message_body TEXT NOT NULL,
    status VARCHAR(20) NOT NULL,
    api_response TEXT DEFAULT NULL,
    sent_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_message_order (order_id)
);

CREATE TABLE IF NOT EXISTS manual_message_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id VARCHAR(80) NOT NULL,
    sender_id VARCHAR(80) NOT NULL,
    message_body TEXT NOT NULL,
    status VARCHAR(20) NOT NULL,
    api_response TEXT DEFAULT NULL,
    sent_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS mercadopago_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    access_token TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS request_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    method VARCHAR(10) NOT NULL,
    path VARCHAR(255) NOT NULL,
    http_status INT DEFAULT NULL,
    request_payload TEXT DEFAULT NULL,
    response_body TEXT DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS repasse_mp_jobs (
    job_id VARCHAR(32) PRIMARY KEY,
    status VARCHAR(30) NOT NULL,
    meta_json LONGTEXT NOT NULL,
    source_rows_json LONGTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS lexos_order_webhook_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id VARCHAR(120) NOT NULL,
    status VARCHAR(255) NOT NULL,
    event_type VARCHAR(120) DEFAULT NULL,
    event_date DATETIME DEFAULT NULL,
    payload_json TEXT NOT NULL,
    event_key VARCHAR(64) NOT NULL,
    received_at DATETIME NOT NULL,
    UNIQUE KEY uq_lexos_order_webhook_event_key (event_key),
    KEY idx_lexos_order_webhook_order (order_id),
    KEY idx_lexos_order_webhook_event_date (event_date),
    KEY idx_lexos_order_webhook_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS protheus_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    host VARCHAR(255) NOT NULL,
    database_name VARCHAR(120) NOT NULL,
    port INT NOT NULL DEFAULT 1433,
    username VARCHAR(120) NOT NULL,
    password TEXT NOT NULL,
    data_corte DATE NOT NULL DEFAULT '2026-04-01',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS protheus_sql_query_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    table_name VARCHAR(64) NOT NULL,
    columns_expr VARCHAR(4000) NOT NULL DEFAULT '*',
    where_clause TEXT NOT NULL,
    order_by_clause TEXT NOT NULL,
    top_limit INT NOT NULL DEFAULT 200,
    sql_text TEXT NOT NULL,
    row_count INT NOT NULL DEFAULT 0,
    elapsed_ms INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    KEY idx_protheus_sql_hist_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS protheus_sql_saved_queries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(120) NOT NULL,
    sql_text MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_sql_saved_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
