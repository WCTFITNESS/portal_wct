<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;
use RuntimeException;
use Shuchkin\SimpleXLSXGen;

/**
 * Anuncios inativos / em revisao (migrado do WCT Code).
 */
class MlInactiveAdsService
{
    public function __construct(
        private TokenService $tokenService,
        private MercadoLivreClient $client,
        private SettingsRepository $settingsRepository
    ) {
    }

    /**
     * @return array{
     *   items: list<array<string, mixed>>,
     *   total: int,
     *   current_page: int,
     *   total_pages: int
     * }
     */
    public function listInactiveAds(int $page = 1, int $limit = 15): array
    {
        $page = max(1, $page);
        $limit = max(1, min(50, $limit));
        $accessToken = $this->tokenService->getValidAccessToken();
        $sellerId = $this->resolveSellerId();
        $offset = ($page - 1) * $limit;

        $path = '/users/' . rawurlencode($sellerId)
            . '/items/search?status=pending&limit=' . $limit . '&offset=' . $offset;
        $res = $this->client->get($path, $accessToken);
        if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
            throw new RuntimeException('Falha ao buscar anuncios. HTTP ' . (string) ($res['status'] ?? 0));
        }

        $ids = $res['body']['results'] ?? [];
        $total = (int) ($res['body']['paging']['total'] ?? 0);
        if (!is_array($ids) || $ids === []) {
            return [
                'items' => [],
                'total' => $total,
                'current_page' => $page,
                'total_pages' => max(1, (int) ceil($total / $limit)),
            ];
        }

        $items = $this->fetchItemsBatch($ids, $accessToken);
        $inactive = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $status = (string) ($item['status'] ?? '');
            if ($status === 'active') {
                continue;
            }

            $subStatus = is_array($item['sub_status'] ?? null) ? ($item['sub_status'][0] ?? '') : '';
            $mlb = (string) ($item['id'] ?? '');
            $motivo = $this->resolveInactiveReason($subStatus, $mlb, $sellerId, $accessToken);
            $statusLabel = match ($status) {
                'under_review' => 'Em Revisão',
                'paused' => 'Pausado',
                default => ucfirst($status),
            };

            $thumbnail = (string) ($item['thumbnail'] ?? '');
            if ($thumbnail !== '' && !str_starts_with($thumbnail, '//') && !str_starts_with($thumbnail, 'http')) {
                $thumbnail = '//' . ltrim($thumbnail, '/');
            }

            $inactive[] = [
                'id' => $mlb,
                'sku' => $this->extractSku($item),
                'title' => (string) ($item['title'] ?? ''),
                'permalink' => (string) ($item['permalink'] ?? ''),
                'thumbnail' => $thumbnail,
                'status' => $statusLabel,
                'status_detail' => $motivo,
                'stock' => (int) ($item['available_quantity'] ?? 0),
                'shipping' => (string) (($item['shipping']['mode'] ?? '') ?: ''),
                'fulfillment' => (($item['shipping']['logistic_type'] ?? '') === 'fulfillment') ? 'Sim' : 'Não',
                'sold_quantity' => (int) ($item['sold_quantity'] ?? 0),
            ];
        }

        return [
            'items' => $inactive,
            'total' => $total,
            'current_page' => $page,
            'total_pages' => max(1, (int) ceil($total / $limit)),
        ];
    }

    public function exportAllInactiveAds(): string
    {
        $accessToken = $this->tokenService->getValidAccessToken();
        $sellerId = $this->resolveSellerId();
        $allIds = [];
        $offset = 0;
        $limit = 50;

        while (true) {
            $path = '/users/' . rawurlencode($sellerId)
                . '/items/search?status=pending&limit=' . $limit . '&offset=' . $offset;
            $res = $this->client->get($path, $accessToken);
            if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
                break;
            }
            $chunk = $res['body']['results'] ?? [];
            if (!is_array($chunk) || $chunk === []) {
                break;
            }
            foreach ($chunk as $id) {
                $allIds[] = (string) $id;
            }
            $total = (int) ($res['body']['paging']['total'] ?? 0);
            $offset += $limit;
            if ($offset >= $total) {
                break;
            }
        }

        $headers = ['MLB', 'SKU', 'Titulo', 'Status', 'Motivo', 'Estoque', 'Full', 'Vendidos', 'Link'];
        $rows = [$headers];

        foreach (array_chunk($allIds, 20) as $batch) {
            $items = $this->fetchItemsBatch($batch, $accessToken);
            foreach ($items as $item) {
                if (!is_array($item) || ($item['status'] ?? '') === 'active') {
                    continue;
                }
                $subStatus = is_array($item['sub_status'] ?? null) ? ($item['sub_status'][0] ?? '') : '';
                $mlb = (string) ($item['id'] ?? '');
                $rows[] = [
                    $mlb,
                    $this->extractSku($item),
                    (string) ($item['title'] ?? ''),
                    (string) ($item['status'] ?? ''),
                    $this->resolveInactiveReason($subStatus, $mlb, $sellerId, $accessToken),
                    (int) ($item['available_quantity'] ?? 0),
                    (($item['shipping']['logistic_type'] ?? '') === 'fulfillment') ? 'Sim' : 'Não',
                    (int) ($item['sold_quantity'] ?? 0),
                    (string) ($item['permalink'] ?? ''),
                ];
            }
        }

        $fileName = 'ml_inativos_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.xlsx';
        $filePath = $this->exportDirectory() . DIRECTORY_SEPARATOR . $fileName;

        require_once __DIR__ . '/../Lib/SimpleXLSXGen.php';
        $xlsx = SimpleXLSXGen::fromArray($rows, 'Inativos');
        if (!$xlsx->saveAs($filePath)) {
            throw new RuntimeException('Nao foi possivel salvar exportacao.');
        }

        return $filePath;
    }

    /**
     * @param list<string> $ids
     * @return list<array<string, mixed>>
     */
    private function fetchItemsBatch(array $ids, string $accessToken): array
    {
        if ($ids === []) {
            return [];
        }

        $path = '/items?ids=' . rawurlencode(implode(',', $ids));
        $res = $this->client->get($path, $accessToken);
        if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
            return [];
        }

        $body = $res['body'] ?? [];
        if (!is_array($body)) {
            return [];
        }

        $items = [];
        foreach ($body as $entry) {
            if (!is_array($entry) || ($entry['code'] ?? 0) !== 200) {
                continue;
            }
            $item = $entry['body'] ?? null;
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function resolveInactiveReason(
        string $subStatus,
        string $mlb,
        string $sellerId,
        string $accessToken
    ): string {
        return match ($subStatus) {
            'waiting_for_patch' => 'Inativo para revisar',
            'out_of_stock' => 'Sem estoque',
            'forbidden' => $this->fetchModerationReason($sellerId, $mlb, $accessToken) ?? 'Usuário precisa trocar categoria',
            default => 'Pausado pelo usuário',
        };
    }

    private function fetchModerationReason(string $sellerId, string $mlb, string $accessToken): ?string
    {
        $path = '/moderations/infractions/' . rawurlencode($sellerId)
            . '?related_item_id=' . rawurlencode($mlb);
        $res = $this->client->get($path, $accessToken);
        if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
            return null;
        }

        $infractions = $res['body']['infractions'] ?? [];
        if (!is_array($infractions) || $infractions === []) {
            return null;
        }

        return (string) ($infractions[0]['reason'] ?? null);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function extractSku(array $item): string
    {
        $sku = trim((string) ($item['seller_custom_field'] ?? ''));
        if ($sku !== '') {
            return $sku;
        }

        $attrs = $item['attributes'] ?? [];
        if (!is_array($attrs)) {
            return '';
        }

        foreach ($attrs as $attr) {
            if (!is_array($attr)) {
                continue;
            }
            if (strtoupper((string) ($attr['id'] ?? '')) !== 'SELLER_SKU') {
                continue;
            }
            $v = trim((string) ($attr['value_name'] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        return '';
    }

    private function resolveSellerId(): string
    {
        $apiConfig = $this->settingsRepository->getApiConfig();
        $sellerId = trim((string) ($apiConfig['seller_id'] ?? ''));
        if ($sellerId === '' || !ctype_digit($sellerId)) {
            throw new RuntimeException('Seller ID invalido. Configure em Configuracao API.');
        }

        return $sellerId;
    }

    private function exportDirectory(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'portal_wct-ml-reports';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Nao foi possivel criar pasta de exportacao.');
        }

        return $dir;
    }
}
