<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;
use RuntimeException;
use Shuchkin\SimpleXLSXGen;

class MlAdsReportService
{
    public function __construct(
        private TokenService $tokenService,
        private MercadoLivreClient $client,
        private SettingsRepository $settingsRepository
    ) {
    }

    /**
     * @param array{date_from?: ?string, date_to?: ?string, sku?: ?string, tipo?: ?string} $filters
     */
    public function generateReport(int $maxItems = 200, array $filters = []): array
    {
        $maxItems = max(1, min(1000, $maxItems));
        $skuFilter = trim((string) ($filters['sku'] ?? ''));
        $tipoFilter = $this->normalizeTypeFilter((string) ($filters['tipo'] ?? ''));
        $dateFrom = $this->normalizeDateInput($filters['date_from'] ?? null, false);
        $dateTo = $this->normalizeDateInput($filters['date_to'] ?? null, true);

        $apiConfig = $this->settingsRepository->getApiConfig();
        $sellerId = trim((string) ($apiConfig['seller_id'] ?? ''));
        if ($sellerId === '' || !ctype_digit($sellerId)) {
            throw new RuntimeException('Seller ID invalido. Configure o user_id numerico em Configuracao API.');
        }

        $accessToken = $this->tokenService->getValidAccessToken();
        $itemIds = $this->fetchItemIds($sellerId, $accessToken, $maxItems);
        if ($itemIds === []) {
            throw new RuntimeException('Nenhum anuncio encontrado para este seller.');
        }

        $items = $this->fetchItemsInBatch($itemIds, $accessToken);

        $rows = [[
            'MLB',
            'Titulo',
            'Preco De',
            'Preco Por',
            'Modo',
            'Full',
            'Status',
            'SKU',
            'Custo',
            'Frete',
            'Frete Gratis',
            'Estoque',
            'Vendas',
            'Visitas',
            'Peso',
            'Altura',
            'Largura',
            'Comprimento',
            'tipo',
        ]];

        $matchedRows = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $shipping = is_array($item['shipping'] ?? null) ? $item['shipping'] : [];
            $sku = $this->extractSku($item);
            $typeLabel = $this->listingTypeLabel((string) ($item['listing_type_id'] ?? ''));

            if ($skuFilter !== '' && stripos($sku, $skuFilter) === false) {
                continue;
            }
            if (!$this->matchTypeFilter($tipoFilter, $typeLabel)) {
                continue;
            }
            if (!$this->matchDateFilter((string) ($item['date_created'] ?? ''), (string) ($item['last_updated'] ?? ''), $dateFrom, $dateTo)) {
                continue;
            }

            $matchedRows++;
            $dimensions = $this->parseDimensions($item['dimensions'] ?? null);
            $rows[] = [
                (string) ($item['id'] ?? ''),
                (string) ($item['title'] ?? ''),
                (string) ($item['original_price'] ?? ''),
                (string) ($item['price'] ?? ''),
                (string) ($item['currency_id'] ?? ''),
                ((string) ($shipping['logistic_type'] ?? '') === 'fulfillment') ? 'SIM' : 'NAO',
                (string) ($item['status'] ?? ''),
                $sku,
                '',
                (string) ($shipping['cost'] ?? ''),
                !empty($shipping['free_shipping']) ? 'sim' : 'nao',
                (string) ($item['available_quantity'] ?? ''),
                (string) ($item['sold_quantity'] ?? ''),
                (string) ($item['initial_quantity'] ?? ''),
                $dimensions['peso'],
                $dimensions['altura'],
                $dimensions['largura'],
                $dimensions['comprimento'],
                $typeLabel,
            ];
        }

        $fileName = 'ml_anuncios_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.xlsx';
        $filePath = $this->exportDirectory() . DIRECTORY_SEPARATOR . $fileName;

        require_once __DIR__ . '/../Lib/SimpleXLSXGen.php';
        $xlsx = SimpleXLSXGen::fromArray($rows, 'Anuncios ML');
        if (!$xlsx->saveAs($filePath)) {
            throw new RuntimeException('Nao foi possivel salvar o arquivo de relatorio.');
        }

        return [
            'file_name' => $fileName,
            'total_ids' => count($itemIds),
            'total_rows' => max(0, count($rows) - 1),
            'matched_rows' => $matchedRows,
        ];
    }

    public function getExportFilePath(string $fileName): ?string
    {
        $base = basename($fileName);
        if ($base === '' || str_contains($base, '..')) {
            return null;
        }
        $path = $this->exportDirectory() . DIRECTORY_SEPARATOR . $base;
        if (!is_file($path)) {
            return null;
        }

        return $path;
    }

    private function fetchItemIds(string $sellerId, string $accessToken, int $maxItems): array
    {
        $limit = 50;
        $offset = 0;
        $ids = [];

        while (count($ids) < $maxItems) {
            $path = '/users/' . rawurlencode($sellerId) . '/items/search?limit=' . $limit . '&offset=' . $offset;
            $res = $this->client->get($path, $accessToken);
            if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
                throw new RuntimeException('Falha ao listar anuncios. HTTP: ' . (string) ($res['status'] ?? 0));
            }
            $resultIds = $res['body']['results'] ?? [];
            if (!is_array($resultIds) || $resultIds === []) {
                break;
            }

            foreach ($resultIds as $id) {
                $ids[] = (string) $id;
                if (count($ids) >= $maxItems) {
                    break 2;
                }
            }

            $offset += $limit;
        }

        return $ids;
    }

    private function fetchItemsInBatch(array $itemIds, string $accessToken): array
    {
        $items = [];
        foreach (array_chunk($itemIds, 20) as $chunk) {
            $path = '/items?ids=' . rawurlencode(implode(',', array_map('strval', $chunk)));
            $res = $this->client->get($path, $accessToken);
            if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300 || !is_array($res['body'] ?? null)) {
                // fallback item a item caso multi-get falhe
                foreach ($chunk as $id) {
                    $single = $this->client->get('/items/' . rawurlencode((string) $id), $accessToken);
                    if (($single['status'] ?? 0) >= 200 && ($single['status'] ?? 0) < 300 && is_array($single['body'] ?? null)) {
                        $items[] = $single['body'];
                    }
                }
                continue;
            }

            foreach ($res['body'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $body = $entry['body'] ?? null;
                if (($entry['code'] ?? 0) === 200 && is_array($body)) {
                    $items[] = $body;
                }
            }
        }

        return $items;
    }

    private function exportDirectory(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'portal_wct-ml-reports';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Nao foi possivel criar pasta de exportacao dos relatorios.');
        }

        return $dir;
    }

    private function normalizeDateInput(?string $value, bool $endOfDay): ?\DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if (!$dt || $dt->format('Y-m-d') !== $value) {
            throw new RuntimeException('Data invalida. Use YYYY-MM-DD.');
        }

        return $endOfDay ? $dt->setTime(23, 59, 59) : $dt->setTime(0, 0, 0);
    }

    private function matchDateFilter(string $dateCreated, string $lastUpdated, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): bool
    {
        if ($from === null && $to === null) {
            return true;
        }
        $ref = trim($lastUpdated) !== '' ? $lastUpdated : $dateCreated;
        if (trim($ref) === '') {
            return false;
        }
        try {
            $dt = new \DateTimeImmutable($ref);
        } catch (\Throwable) {
            return false;
        }
        if ($from !== null && $dt < $from) {
            return false;
        }
        if ($to !== null && $dt > $to) {
            return false;
        }

        return true;
    }

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
            $v = trim((string) ($attr['value_name'] ?? $attr['value_id'] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        return '';
    }

    private function normalizeTypeFilter(string $tipo): string
    {
        $v = mb_strtolower(trim($tipo));
        if ($v === '' || $v === 'todos') {
            return 'todos';
        }
        if (str_contains($v, 'premium')) {
            return 'premium';
        }
        if (str_contains($v, 'class')) {
            return 'classico';
        }

        return 'todos';
    }

    private function matchTypeFilter(string $filter, string $label): bool
    {
        if ($filter === 'todos') {
            return true;
        }
        $v = mb_strtolower($label);
        if ($filter === 'premium') {
            return str_contains($v, 'premium');
        }
        if ($filter === 'classico') {
            return str_contains($v, 'classico');
        }

        return true;
    }

    private function listingTypeLabel(string $listingTypeId): string
    {
        $id = mb_strtolower(trim($listingTypeId));
        if ($id === '') {
            return '';
        }
        if (str_contains($id, 'gold') || str_contains($id, 'premium')) {
            return 'Premium';
        }

        return 'Classico';
    }

    /**
     * @param mixed $raw
     * @return array{peso:string,altura:string,largura:string,comprimento:string}
     */
    private function parseDimensions(mixed $raw): array
    {
        $result = ['peso' => '', 'altura' => '', 'largura' => '', 'comprimento' => ''];
        $str = is_string($raw) ? $raw : '';
        if ($str === '') {
            return $result;
        }
        $parts = array_map('trim', explode('x', str_replace(',', '.', mb_strtolower($str))));
        if (count($parts) < 4) {
            return $result;
        }
        $result['altura'] = preg_replace('/[^0-9.]/', '', $parts[0]) ?? '';
        $result['largura'] = preg_replace('/[^0-9.]/', '', $parts[1]) ?? '';
        $result['comprimento'] = preg_replace('/[^0-9.]/', '', $parts[2]) ?? '';
        $result['peso'] = preg_replace('/[^0-9.]/', '', $parts[3]) ?? '';

        return $result;
    }
}

