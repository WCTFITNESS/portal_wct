<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

/**
 * Diagnóstico de expedição Lexos (transportadora ausente no checkout).
 */
final class LexosExpeditionDiagnosticService
{
    public function __construct(private LexosHubApiClient $lexosHubApiClient)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnose(string $orderQuery): array
    {
        $orderQuery = trim($orderQuery);
        if ($orderQuery === '') {
            throw new RuntimeException('Informe o código do pedido (ex.: 2000016479266634).');
        }

        $entrega = $this->fetchEntregaBySearch($orderQuery);
        $transportadoraLists = $this->fetchTransportadoraLists();
        $orderRow = $entrega['rows'][0] ?? null;

        return [
            'query' => $orderQuery,
            'entrega' => $entrega,
            'order_row' => $orderRow,
            'hints' => $this->buildHints($orderRow, $transportadoraLists),
            'transportadora_lists' => $transportadoraLists,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchEntregaBySearch(string $searchKey): array
    {
        $url = 'https://app-hub-webapi.lexos.com.br/api/Entrega/DataSourceTodos?transportadoraId=-1';
        $payload = [
            'requiresCounts' => true,
            'skip' => 0,
            'take' => 20,
            'search' => [[
                'fields' => ['Search'],
                'operator' => 'contains',
                'key' => $searchKey,
                'ignoreCase' => true,
            ]],
        ];

        try {
            $json = $this->lexosHubApiClient->postJson($url, $payload);

            return [
                'ok' => true,
                'url' => $url,
                'rows' => $this->normalizeRows($json),
                'raw_keys' => is_array($json) ? array_keys($json) : [],
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'url' => $url,
                'error' => $e->getMessage(),
                'rows' => [],
            ];
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fetchTransportadoraLists(): array
    {
        $endpoints = [
            'transportadora_datasource' => 'https://app-hub-webapi.lexos.com.br/api/Transportadora/DataSource',
            'transportadora_datasource_loja' => 'https://app-hub-webapi.lexos.com.br/api/Transportadora/DataSource?lojaId=-1',
        ];

        $payload = [
            'requiresCounts' => false,
            'skip' => 0,
            'take' => 500,
        ];

        $out = [];
        foreach ($endpoints as $key => $url) {
            try {
                $json = $this->lexosHubApiClient->postJson($url, $payload);
                $rows = $this->normalizeRows($json);
                $out[$key] = [
                    'ok' => true,
                    'url' => $url,
                    'count' => count($rows),
                    'names' => $this->extractTransportadoraNames($rows),
                    'sample' => array_slice($rows, 0, 3),
                ];
            } catch (Throwable $e) {
                $out[$key] = [
                    'ok' => false,
                    'url' => $url,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $out;
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
     * @return list<string>
     */
    private function extractTransportadoraNames(array $rows): array
    {
        $names = [];
        foreach ($rows as $row) {
            foreach (['Nome', 'RazaoSocial', 'Descricao', 'Transportadora', 'NomeFantasia'] as $field) {
                $v = trim((string) ($row[$field] ?? ''));
                if ($v !== '') {
                    $names[] = $v;
                }
            }
            if (isset($row['Transportadora']) && is_array($row['Transportadora'])) {
                $t = trim((string) ($row['Transportadora']['Nome'] ?? $row['Transportadora']['RazaoSocial'] ?? ''));
                if ($t !== '') {
                    $names[] = $t;
                }
            }
        }

        $names = array_values(array_unique($names));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    /**
     * @param array<string, mixed>|null $orderRow
     * @param array<string, array<string, mixed>> $transportadoraLists
     * @return list<string>
     */
    private function buildHints(?array $orderRow, array $transportadoraLists): array
    {
        $hints = [];

        if ($orderRow === null) {
            $hints[] = 'Pedido não encontrado na API de Expedição (Entrega/DataSourceTodos). Verifique o código ou se o pedido está na aba correta.';
            return $hints;
        }

        $nfTransport = trim((string) (
            $orderRow['TransportadoraNome']
            ?? $orderRow['Transportadora']
            ?? $orderRow['NomeTransportadora']
            ?? ''
        ));
        if (is_array($orderRow['Transportadora'] ?? null)) {
            $nfTransport = trim((string) (
                $orderRow['Transportadora']['Nome']
                ?? $orderRow['Transportadora']['RazaoSocial']
                ?? $nfTransport
            ));
        }

        $tid = trim((string) ($orderRow['TransportadoraId'] ?? $orderRow['IdTransportadora'] ?? ''));

        if ($nfTransport !== '') {
            $hints[] = 'Transportadora indicada no pedido/NF: «' . $nfTransport . '».';
        } else {
            $hints[] = 'A API não retornou nome de transportadora neste pedido; confira a NF-e (tag transporta/xNome) ou o Protheus.';
        }

        if ($tid === '' || $tid === '0') {
            $hints[] = 'TransportadoraId vazio no Lexos — é isso que gera o erro logístico no checkout.';
        } else {
            $hints[] = 'TransportadoraId no Lexos: ' . $tid . ' (pode estar inválida ou inativa).';
        }

        $allNames = [];
        foreach ($transportadoraLists as $list) {
            if (($list['ok'] ?? false) && is_array($list['names'] ?? null)) {
                $allNames = array_merge($allNames, $list['names']);
            }
        }
        $allNames = array_values(array_unique($allNames));

        if ($nfTransport !== '' && $allNames !== []) {
            $match = $this->findClosestName($nfTransport, $allNames);
            if ($match !== null) {
                $hints[] = 'Transportadora parecida já cadastrada no Lexos: «' . $match . '». Tente selecioná-la no dropdown.';
            } else {
                $hints[] = 'Nenhuma transportadora cadastrada no Lexos parece corresponder a «' . $nfTransport . '». É necessário cadastro no Lexos Hub (Configurações / Transportadoras) ou abrir chamado com o suporte Lexos.';
            }
        }

        $hints[] = 'Não use SQL injection nem alteração manual de banco do Lexos — peça cadastro urgente ao suporte com CNPJ e razão social da transportadora.';

        return $hints;
    }

    /**
     * @param list<string> $registered
     */
    private function findClosestName(string $needle, array $registered): ?string
    {
        $n = mb_strtoupper(preg_replace('/\s+/', ' ', $needle) ?? $needle);
        foreach ($registered as $name) {
            $r = mb_strtoupper($name);
            if ($n === $r || str_contains($r, $n) || str_contains($n, $r)) {
                return $name;
            }
        }

        $tokens = array_filter(explode(' ', $n), static fn (string $t) => strlen($t) >= 4);
        foreach ($registered as $name) {
            $r = mb_strtoupper($name);
            foreach ($tokens as $token) {
                if (str_contains($r, $token)) {
                    return $name;
                }
            }
        }

        return null;
    }
}
