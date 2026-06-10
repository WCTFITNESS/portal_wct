<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ProtheusSettingsRepository;
use PDO;

class ProtheusConnectionService
{
    public function __construct(
        private ProtheusSettingsRepository $settingsRepository
    ) {
    }

    public function getConfiguredSettings(): ?array
    {
        return $this->settingsRepository->getSettings();
    }

    public function isDriverAvailable(): bool
    {
        return extension_loaded('pdo_sqlsrv') || extension_loaded('pdo_dblib');
    }

    public function availableDrivers(): string
    {
        $drivers = [];
        if (extension_loaded('pdo_sqlsrv')) {
            $drivers[] = 'pdo_sqlsrv';
        }
        if (extension_loaded('pdo_dblib')) {
            $drivers[] = 'pdo_dblib';
        }

        return $drivers === [] ? 'nenhum' : implode(', ', $drivers);
    }

    public function connect(?array $settings = null): PDO
    {
        $settings ??= $this->settingsRepository->getSettings();
        if ($settings === null) {
            throw new \RuntimeException('Configure o Protheus em Config Protheus antes de consultar.');
        }

        if (!$this->isDriverAvailable()) {
            throw new \RuntimeException(
                'PHP sem driver SQL Server (pdo_sqlsrv ou pdo_dblib). Instale a extensao no servidor.'
            );
        }

        $host = trim((string) ($settings['host'] ?? ''));
        $database = trim((string) ($settings['database_name'] ?? ''));
        $port = (int) ($settings['port'] ?? 1433);
        $username = trim((string) ($settings['username'] ?? ''));
        $password = (string) ($settings['password'] ?? '');

        if ($host === '' || $database === '' || $username === '') {
            throw new \RuntimeException('Configuracao Protheus incompleta (host, banco ou usuario).');
        }

        if ($port <= 0) {
            $port = 1433;
        }

        $dsn = $this->buildDsn($host, $port, $database);
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        return $pdo;
    }

    public function testConnection(?array $settings = null): string
    {
        $pdo = $this->connect($settings);
        $stmt = $pdo->query('SELECT 1 AS ok');
        $row = $stmt->fetch();

        return is_array($row) && (string) ($row['ok'] ?? '') === '1'
            ? 'Conexao com o SQL Server estabelecida com sucesso.'
            : 'Conexao aberta, mas resposta inesperada no teste.';
    }

    private function buildDsn(string $host, int $port, string $database): string
    {
        if (extension_loaded('pdo_sqlsrv')) {
            return 'sqlsrv:Server=' . $host . ',' . $port
                . ';Database=' . $database
                . ';TrustServerCertificate=yes;Encrypt=no'
                . ';LoginTimeout=15';
        }

        return 'dblib:host=' . $host . ':' . $port . ';dbname=' . $database . ';charset=UTF-8';
    }
}
