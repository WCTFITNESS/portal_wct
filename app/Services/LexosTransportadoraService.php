<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

/**
 * Lista e tentativa de cadastro de transportadoras no Lexos Hub WebAPI.
 */
final class LexosTransportadoraService
{
    private const HUB_BASE = 'https://app-hub-webapi.lexos.com.br/api/Transportadora';

    /** @var list<array{codigo: string, razao: string, fantasia: string, site: string}> */
    public const PRESETS = [
        ['codigo' => '000001', 'razao' => 'ALFA TRANSPORTES LTDA', 'fantasia' => 'ALFA', 'site' => 'https://www.alfatransportes.com.br/'],
        ['codigo' => '000003', 'razao' => 'JADLOG LOGISTICA LTDA', 'fantasia' => 'JADLOG', 'site' => 'https://www.jadlog.com.br/'],
        ['codigo' => '000406', 'razao' => 'TRD TRANSPORTE RODOVIARIO DALFAN LTDA', 'fantasia' => 'TRD TRANSPORTE RODOV', 'site' => 'https://www.trdtransportes.com/'],
        ['codigo' => '000480', 'razao' => 'AZURELOG TRANSPORTES LTDA', 'fantasia' => 'AZURELOG TRANSPORTES LTDA', 'site' => 'https://azurelog.com.br/'],
        ['codigo' => '000604', 'razao' => 'LOGTEMPO LOGISTICA INTELIGENTE', 'fantasia' => 'LOGTEMPO LOGISTICA I', 'site' => 'https://www.plav.com.br/'],
        ['codigo' => '000671', 'razao' => 'ARLETE TRANSPORTES LTDA', 'fantasia' => 'ARLETE TRANSPORTES LTDA', 'site' => 'https://www.arletetransportes.com.br/rastreamento'],
        ['codigo' => '000721', 'razao' => 'RPJ LOGISTICA E TRANSPORTES', 'fantasia' => 'RPJ LOGISTICA E TRANSPORTES', 'site' => 'https://www.rpjtransportes.com.br/'],
    ];

    public function __construct(private LexosHubApiClient $lexosHubApiClient)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRegistered(): array
    {
        $url = self::HUB_BASE . '/DataSource?lojaId=-1';
        $payload = [
            'requiresCounts' => false,
            'skip' => 0,
            'take' => 500,
        ];

        $json = $this->lexosHubApiClient->postJson($url, $payload);

        return $this->normalizeRows($json);
    }

    /**
     * @param array<string, string> $input
     * @return array{success: bool, message: string, attempts: list<array<string, mixed>>, row: array<string, mixed>}
     */
    public function register(array $input): array
    {
        $row = $this->buildRowFromInput($input);
        $payloadVariants = $this->buildPayloadVariants($row);
        $endpoints = $this->buildEndpointCandidates();

        $attempts = [];
        foreach ($endpoints as $endpoint) {
            foreach ($payloadVariants as $idx => $payload) {
                $label = $endpoint['label'] . ' #' . ($idx + 1);
                try {
                    $result = $this->lexosHubApiClient->request($endpoint['method'], $endpoint['url'], $payload);
                    $attempts[] = [
                        'label' => $label,
                        'url' => $endpoint['url'],
                        'method' => $endpoint['method'],
                        'ok' => $result['ok'],
                        'status' => $result['status'],
                        'auth' => $result['auth'],
                        'body' => $result['body'],
                    ];

                    if ($result['ok'] && $this->looksLikeSuccess($result['body'])) {
                        return [
                            'success' => true,
                            'message' => 'Transportadora cadastrada ou atualizada via ' . $label . ' (HTTP ' . $result['status'] . ').',
                            'attempts' => $attempts,
                            'row' => $row,
                        ];
                    }
                } catch (Throwable $e) {
                    $attempts[] = [
                        'label' => $label,
                        'url' => $endpoint['url'],
                        'method' => $endpoint['method'],
                        'ok' => false,
                        'status' => 0,
                        'auth' => '',
                        'body' => $e->getMessage(),
                    ];
                }
            }
        }

        return [
            'success' => false,
            'message' => 'Nenhum endpoint público aceitou o cadastro. Use o passo a passo no Hub (abaixo) ou abra chamado Lexos com CNPJ e razão social.',
            'attempts' => $attempts,
            'row' => $row,
        ];
    }

    /**
     * @param array<string, string> $input
     * @return array<string, mixed>
     */
    private function buildRowFromInput(array $input): array
    {
        $cnpj = preg_replace('/\D+/', '', (string) ($input['cnpj'] ?? '')) ?? '';
        $codigo = trim((string) ($input['codigo'] ?? ''));
        $razao = trim((string) ($input['razao_social'] ?? ''));
        $fantasia = trim((string) ($input['nome_fantasia'] ?? ''));
        if ($fantasia === '') {
            $fantasia = $razao;
        }

        if ($razao === '') {
            throw new RuntimeException('Informe a razão social.');
        }

        if (strlen($cnpj) !== 14) {
            throw new RuntimeException('CNPJ deve ter 14 dígitos.');
        }

        return [
            'Id' => 0,
            'Codigo' => $codigo,
            'CodigoTransportadora' => $codigo,
            'RazaoSocial' => $razao,
            'Nome' => $fantasia,
            'NomeFantasia' => $fantasia,
            'Cnpj' => $cnpj,
            'CpfCnpj' => $cnpj,
            'Telefone' => trim((string) ($input['telefone'] ?? '')),
            'Email' => trim((string) ($input['email'] ?? '')),
            'Site' => trim((string) ($input['site'] ?? '')),
            'Ativo' => true,
            'Habilitado' => true,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    private function buildPayloadVariants(array $row): array
    {
        return [
            $row,
            ['value' => $row, 'action' => 'insert'],
            ['value' => $row],
            ['transportadora' => $row],
            ['model' => $row],
            ['data' => $row],
            [
                'added' => [$row],
                'changed' => [],
                'deleted' => [],
            ],
        ];
    }

    /**
     * @return list<array{method: string, url: string, label: string}>
     */
    private function buildEndpointCandidates(): array
    {
        $paths = [
            'Insert',
            'Save',
            'Create',
            'Add',
            'Upsert',
            'Update',
            'Post',
            '',
            'DataSource/Insert',
            'DataSource/Save',
        ];

        $out = [];
        foreach ($paths as $path) {
            $url = $path === '' ? rtrim(self::HUB_BASE, '/') : self::HUB_BASE . '/' . $path;
            $out[] = ['method' => 'POST', 'url' => $url, 'label' => 'POST ' . ($path === '' ? '/Transportadora' : $path)];
        }

        return $out;
    }

    private function looksLikeSuccess(mixed $body): bool
    {
        if (!is_array($body)) {
            return false;
        }

        if (isset($body['success']) && $body['success'] === false) {
            return false;
        }

        if (isset($body['Success']) && $body['Success'] === false) {
            return false;
        }

        if (isset($body['error']) && is_string($body['error']) && $body['error'] !== '') {
            return false;
        }

        if (isset($body['Id']) && (int) $body['Id'] > 0) {
            return true;
        }

        if (isset($body['TransportadoraId']) && (int) $body['TransportadoraId'] > 0) {
            return true;
        }

        return true;
    }

    /**
     * @param mixed $json
     * @return list<array<string, mixed>>
     */
    private function normalizeRows(mixed $json): array
    {
        if (!is_array($json)) {
            return [];
        }

        if (isset($json['result']) && is_array($json['result'])) {
            return array_values(array_filter($json['result'], 'is_array'));
        }

        if (isset($json['data']) && is_array($json['data'])) {
            return array_values(array_filter($json['data'], 'is_array'));
        }

        return [];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{id: string, codigo: string, nome: string, cnpj: string}>
     */
    public function summarizeRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $nome = trim((string) (
                $row['RazaoSocial']
                ?? $row['Nome']
                ?? $row['NomeFantasia']
                ?? $row['Descricao']
                ?? ''
            ));
            if ($nome === '') {
                continue;
            }

            $out[] = [
                'id' => trim((string) ($row['Id'] ?? $row['TransportadoraId'] ?? '')),
                'codigo' => trim((string) ($row['Codigo'] ?? $row['CodigoTransportadora'] ?? '')),
                'nome' => $nome,
                'cnpj' => trim((string) ($row['Cnpj'] ?? $row['CpfCnpj'] ?? '')),
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcasecmp($a['nome'], $b['nome']));

        return $out;
    }
}
