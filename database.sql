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
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);
