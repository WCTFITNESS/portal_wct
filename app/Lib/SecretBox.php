<?php

declare(strict_types=1);

namespace App\Lib;

use RuntimeException;

/**
 * Cifra segredos guardados no banco (certificados, senhas de API) com AES-256-GCM.
 */
class SecretBox
{
    public function __construct(private string $key, private bool $keyFromEnv)
    {
    }

    public function isKeyFromEnv(): bool
    {
        return $this->keyFromEnv;
    }

    public function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->binaryKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Falha ao proteger o dado sigiloso.');
        }

        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $blob): string
    {
        if (!str_starts_with($blob, 'v1:')) {
            throw new RuntimeException('Formato do dado sigiloso desconhecido. Cadastre novamente.');
        }
        $raw = base64_decode(substr($blob, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('Dado sigiloso corrompido. Cadastre novamente.');
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->binaryKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            throw new RuntimeException('Não foi possível abrir o dado salvo (a chave de criptografia do portal mudou). Cadastre novamente.');
        }

        return $plain;
    }

    private function binaryKey(): string
    {
        return hash('sha256', $this->key, true);
    }
}
