-- Execute no banco portal_wct se a instalação for anterior à tabela mercadopago_settings.

USE portal_wct;

CREATE TABLE IF NOT EXISTS mercadopago_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    access_token TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
