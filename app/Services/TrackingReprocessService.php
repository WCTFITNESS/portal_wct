<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\TrackingDatabase;
use RuntimeException;
use Throwable;

/**
 * Reprocessa pedidos no Tracking WCT a partir do Portal.
 */
final class TrackingReprocessService
{
    public function __construct(
        private TrackingDatabase $trackingDatabase,
        private string $trackingApiBaseUrl,
        private ?LexosHubApiClient $lexosHubApiClient = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function reprocessByCodigo(string $codigo): array
    {
        $codigo = trim($codigo);
        if ($codigo === '') {
            throw new RuntimeException('Informe o código do pedido.');
        }

        if (!$this->trackingDatabase->isConfigured()) {
            return $this->reprocessViaTrackingApi($codigo);
        }

        $existente = $this->findPedidoIndexado($codigo);
        if ($existente !== null) {
            return [
                'action' => 'already_indexed',
                'codigo' => $codigo,
                'pedido' => $existente,
            ];
        }

        $pedidoIds = $this->resolvePedidoIds($codigo);
        if ($pedidoIds === []) {
            throw new RuntimeException(
                'PedidoId Lexos não encontrado em webhook_logs nem na API Lexos. '
                . 'Confirme se o webhook da Lexos aponta para o Tracking e se as credenciais Lexos estão configuradas.'
            );
        }

        $results = [];
        foreach ($pedidoIds as $pedidoIdLexos) {
            $results[] = $this->callTrackingWebhook($pedidoIdLexos);
        }

        $verificacao = $this->findPedidoIndexado($codigo);

        return [
            'action' => 'reprocessed',
            'codigo' => $codigo,
            'pedido_ids' => $pedidoIds,
            'results' => $results,
            'indexado' => $verificacao !== null,
            'pedido' => $verificacao,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findPedidoIndexado(string $codigo): ?array
    {
        $pdo = $this->trackingDatabase->pdo();
        $term = '%' . $codigo . '%';
        $termNoDash = '%' . str_replace('-', '', $codigo) . '%';

        $stmt = $pdo->prepare(
            'SELECT id, pedido_id_lexos, codigo_marketplace, codigo_plataforma, transportadora_nome, status, data_pedido
             FROM pedidos
             WHERE codigo_marketplace ILIKE :term
                OR codigo_plataforma ILIKE :term
                OR REPLACE(codigo_plataforma, \'-\', \'\') ILIKE :term_no_dash
                OR REPLACE(codigo_marketplace, \'-\', \'\') ILIKE :term_no_dash
             ORDER BY data_pedido DESC
             LIMIT 1'
        );
        $stmt->execute([
            ':term' => $term,
            ':term_no_dash' => $termNoDash,
        ]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<string>
     */
    private function resolvePedidoIds(string $codigo): array
    {
        $ids = $this->findPedidoIdsInWebhookLogs($codigo);

        if ($ids === [] && $this->lexosHubApiClient !== null) {
            $fromHub = $this->findPedidoIdViaLexosHub($codigo);
            if ($fromHub !== '') {
                $ids[] = $fromHub;
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (string $id): bool => $id !== '')));
    }

    /**
     * @return list<string>
     */
    private function findPedidoIdsInWebhookLogs(string $codigo): array
    {
        $pdo = $this->trackingDatabase->pdo();
        $term = '%' . $codigo . '%';
        $termNoDash = '%' . str_replace('-', '', $codigo) . '%';

        $stmt = $pdo->prepare(
            'SELECT DISTINCT pedido_id_lexos
             FROM webhook_logs
             WHERE pedido_id_lexos IS NOT NULL
               AND pedido_id_lexos <> \'\'
               AND (
                 pedido_id_lexos ILIKE :term
                 OR payload::text ILIKE :term
                 OR payload::text ILIKE :term_no_dash
               )
             ORDER BY pedido_id_lexos'
        );
        $stmt->execute([
            ':term' => $term,
            ':term_no_dash' => $termNoDash,
        ]);

        $ids = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $id = trim((string) ($row['pedido_id_lexos'] ?? ''));
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function findPedidoIdViaLexosHub(string $codigo): string
    {
        if ($this->lexosHubApiClient === null) {
            return '';
        }

        try {
            $url = 'https://app-hub-webapi.lexos.com.br/api/Entrega/DataSourceTodos?transportadoraId=-1';
            $payload = [
                'requiresCounts' => true,
                'skip' => 0,
                'take' => 20,
                'search' => [[
                    'fields' => ['Search'],
                    'operator' => 'contains',
                    'key' => $codigo,
                    'ignoreCase' => true,
                ]],
            ];

            $json = $this->lexosHubApiClient->postJson($url, $payload);
            $rows = $this->normalizeRows($json);

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                foreach (['PedidoId', 'IdPedido', 'PedidoID', 'pedidoId'] as $field) {
                    $value = trim((string) ($row[$field] ?? ''));
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        } catch (Throwable) {
        }

        return '';
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
     * Fallback: Portal sem TRACKING_DATABASE_URL delega ao Tracking (que já tem DATABASE_URL no Render).
     *
     * @return array<string, mixed>
     */
    private function reprocessViaTrackingApi(string $codigo): array
    {
        $base = rtrim($this->trackingApiBaseUrl, '/');
        $url = $base . '/api/webhook/reprocess-codigo';
        $payload = json_encode(['codigo' => $codigo], JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Falha ao iniciar requisição para o Tracking.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 180,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Falha ao chamar Tracking: ' . $error);
        }

        $decoded = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            $msg = is_array($decoded) ? (string) ($decoded['error'] ?? $raw) : (string) $raw;
            throw new RuntimeException('Tracking retornou HTTP ' . $status . ': ' . $msg);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Resposta inválida do Tracking.');
        }

        $decoded['via'] = 'tracking_api';

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function callTrackingWebhook(string $pedidoIdLexos): array
    {
        $base = rtrim($this->trackingApiBaseUrl, '/');
        $url = $base . '/api/webhook';
        $payload = json_encode([
            'pedidoId' => $pedidoIdLexos,
            'Evento' => 'ReprocessamentoPortal',
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Falha ao iniciar requisição para o Tracking.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 120,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Falha ao chamar Tracking: ' . $error);
        }

        $decoded = json_decode($raw, true);

        return [
            'pedido_id_lexos' => $pedidoIdLexos,
            'http_status' => $status,
            'ok' => $status >= 200 && $status < 300,
            'body' => is_array($decoded) ? $decoded : $raw,
        ];
    }
}
