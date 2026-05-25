<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;
use RuntimeException;
use Shuchkin\SimpleXLSXGen;

class MlAdsReportService
{
    /** @var list<string> */
    private const PREMIUM_LISTING_TYPES = ['gold_pro', 'gold_premium', 'gold'];

    /** @var list<string> */
    private const CLASSICO_LISTING_TYPES = ['gold_special', 'silver', 'bronze', 'free'];

    public function __construct(
        private TokenService $tokenService,
        private MercadoLivreClient $client,
        private SettingsRepository $settingsRepository
    ) {
    }

    /**
     * @param array{date_from?: ?string, date_to?: ?string, sku?: ?string, tipo?: ?string} $filters
     */
    /**
     * @param array{date_from?: ?string, date_to?: ?string, sku?: ?string, tipo?: ?string} $filters
     * @return array{
     *   header: list<string>,
     *   rows: list<list<string>>,
     *   preview: list<array<string, string>>,
     *   total_ids: int,
     *   matched_rows: int,
     *   count_by_tipo: array<string, int>
     * }
     */
    public function collectReportRows(int $maxItems = 200, array $filters = []): array
    {
        $maxItems = $maxItems <= 0 ? 0 : max(1, min(5000, $maxItems));
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

        $header = [
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
            'listing_type_id',
        ];

        $rows = [$header];
        $preview = [];
        $matchedRows = 0;
        $countByTipo = ['Premium' => 0, 'Classico' => 0, 'Outros' => 0];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $shipping = is_array($item['shipping'] ?? null) ? $item['shipping'] : [];
            $sku = $this->extractSku($item);
            $listingTypeId = (string) ($item['listing_type_id'] ?? '');
            $typeLabel = $this->listingTypeLabel($listingTypeId);

            if ($skuFilter !== '' && stripos($sku, $skuFilter) === false) {
                continue;
            }
            if (!$this->matchTypeFilter($tipoFilter, $listingTypeId)) {
                continue;
            }
            if (!$this->matchDateFilter((string) ($item['date_created'] ?? ''), (string) ($item['last_updated'] ?? ''), $dateFrom, $dateTo)) {
                continue;
            }

            $matchedRows++;
            if ($typeLabel === 'Premium') {
                $countByTipo['Premium']++;
            } elseif ($typeLabel === 'Classico') {
                $countByTipo['Classico']++;
            } else {
                $countByTipo['Outros']++;
            }

            $dimensions = $this->extractDimensions($item, $shipping);
            $row = [
                (string) ($item['id'] ?? ''),
                (string) ($item['title'] ?? ''),
                (string) ($item['original_price'] ?? ''),
                (string) ($item['price'] ?? ''),
                (string) ($shipping['mode'] ?? ''),
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
                $listingTypeId,
            ];
            $rows[] = $row;
            $preview[] = [
                'mlb' => (string) ($item['id'] ?? ''),
                'titulo' => (string) ($item['title'] ?? ''),
                'preco_de' => (string) ($item['original_price'] ?? ''),
                'preco_por' => (string) ($item['price'] ?? ''),
                'status' => (string) ($item['status'] ?? ''),
                'sku' => $sku,
                'tipo' => $typeLabel,
                'listing_type_id' => $listingTypeId,
                'full' => ((string) ($shipping['logistic_type'] ?? '') === 'fulfillment') ? 'SIM' : 'NAO',
                'estoque' => (string) ($item['available_quantity'] ?? ''),
                'vendas' => (string) ($item['sold_quantity'] ?? ''),
            ];
        }

        return [
            'header' => $header,
            'rows' => $rows,
            'preview' => $preview,
            'total_ids' => count($itemIds),
            'matched_rows' => $matchedRows,
            'count_by_tipo' => $countByTipo,
        ];
    }

    /**
     * @param array{date_from?: ?string, date_to?: ?string, sku?: ?string, tipo?: ?string} $filters
     */
    public function generateReport(int $maxItems = 200, array $filters = []): array
    {
        $data = $this->collectReportRows($maxItems, $filters);

        $fileName = 'ml_anuncios_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.xlsx';
        $filePath = $this->exportDirectory() . DIRECTORY_SEPARATOR . $fileName;

        require_once __DIR__ . '/../Lib/SimpleXLSXGen.php';
        $xlsx = SimpleXLSXGen::fromArray($data['rows'], 'Anuncios ML');
        if (!$xlsx->saveAs($filePath)) {
            throw new RuntimeException('Nao foi possivel salvar o arquivo de relatorio.');
        }

        return [
            'file_name' => $fileName,
            'total_ids' => $data['total_ids'],
            'total_rows' => max(0, count($data['rows']) - 1),
            'matched_rows' => $data['matched_rows'],
            'count_by_tipo' => $data['count_by_tipo'],
            'preview' => $data['preview'],
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
        // Para volumes maiores, a API de search com offset pode retornar 400.
        // Usa scan/scroll para paginação robusta em listas grandes.
        if ($maxItems === 0 || $maxItems > 1000) {
            return $this->fetchItemIdsByScan($sellerId, $accessToken, $maxItems);
        }

        $limit = 50;
        $offset = 0;
        $ids = [];
        $unlimited = $maxItems <= 0;

        while ($unlimited || count($ids) < $maxItems) {
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
                if (!$unlimited && count($ids) >= $maxItems) {
                    break 2;
                }
            }

            $offset += $limit;
        }

        return $ids;
    }

    private function fetchItemIdsByScan(string $sellerId, string $accessToken, int $maxItems): array
    {
        $ids = [];
        $unlimited = $maxItems <= 0;

        $initPath = '/users/' . rawurlencode($sellerId) . '/items/search?search_type=scan';
        $res = $this->client->get($initPath, $accessToken);
        if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
            throw new RuntimeException('Falha ao iniciar scan de anuncios. HTTP: ' . (string) ($res['status'] ?? 0));
        }

        $scrollId = (string) ($res['body']['scroll_id'] ?? '');
        if ($scrollId === '') {
            throw new RuntimeException('Scan de anuncios nao retornou scroll_id.');
        }

        while ($scrollId !== '' && ($unlimited || count($ids) < $maxItems)) {
            $path = '/users/' . rawurlencode($sellerId) . '/items/search?search_type=scan&scroll_id=' . rawurlencode($scrollId);
            $chunkRes = $this->client->get($path, $accessToken);
            if (($chunkRes['status'] ?? 0) < 200 || ($chunkRes['status'] ?? 0) >= 300) {
                throw new RuntimeException('Falha ao continuar scan de anuncios. HTTP: ' . (string) ($chunkRes['status'] ?? 0));
            }

            $resultIds = $chunkRes['body']['results'] ?? [];
            if (!is_array($resultIds) || $resultIds === []) {
                break;
            }

            foreach ($resultIds as $id) {
                $ids[] = (string) $id;
                if (!$unlimited && count($ids) >= $maxItems) {
                    break 2;
                }
            }

            $scrollId = (string) ($chunkRes['body']['scroll_id'] ?? '');
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

    private function matchTypeFilter(string $filter, string $listingTypeId): bool
    {
        if ($filter === 'todos') {
            return true;
        }
        if ($filter === 'premium') {
            return $this->isPremiumListingType($listingTypeId);
        }
        if ($filter === 'classico') {
            return $this->isClassicoListingType($listingTypeId);
        }

        return true;
    }

    private function isPremiumListingType(string $listingTypeId): bool
    {
        $id = mb_strtolower(trim($listingTypeId));

        return in_array($id, self::PREMIUM_LISTING_TYPES, true);
    }

    private function isClassicoListingType(string $listingTypeId): bool
    {
        $id = mb_strtolower(trim($listingTypeId));

        return in_array($id, self::CLASSICO_LISTING_TYPES, true);
    }

    private function listingTypeLabel(string $listingTypeId): string
    {
        if ($this->isPremiumListingType($listingTypeId)) {
            return 'Premium';
        }
        if ($this->isClassicoListingType($listingTypeId)) {
            return 'Classico';
        }
        $id = trim($listingTypeId);
        if ($id === '') {
            return '';
        }

        return 'Outros';
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $shipping
     * @return array{peso:string,altura:string,largura:string,comprimento:string}
     */
    private function extractDimensions(array $item, array $shipping): array
    {
        $result = ['peso' => '', 'altura' => '', 'largura' => '', 'comprimento' => ''];

        // 1) Formato mais comum da API: shipping.dimensions => "16x11x28,500"
        $shippingDims = is_string($shipping['dimensions'] ?? null) ? (string) $shipping['dimensions'] : '';
        $parsed = $this->parseDimensionsString($shippingDims);
        if ($this->hasAnyDimension($parsed)) {
            return $parsed;
        }

        // 2) Alguns anúncios trazem em item.dimensions
        $itemDims = is_string($item['dimensions'] ?? null) ? (string) $item['dimensions'] : '';
        $parsed = $this->parseDimensionsString($itemDims);
        if ($this->hasAnyDimension($parsed)) {
            return $parsed;
        }

        // 3) Fallback por atributos do item
        $attrs = $item['attributes'] ?? [];
        if (!is_array($attrs)) {
            return $result;
        }
        foreach ($attrs as $attr) {
            if (!is_array($attr)) {
                continue;
            }
            $id = strtoupper((string) ($attr['id'] ?? ''));
            $value = trim((string) ($attr['value_name'] ?? $attr['value_id'] ?? ''));
            if ($value === '') {
                continue;
            }
            $num = preg_replace('/[^0-9.,]/', '', $value) ?? '';
            $num = str_replace(',', '.', $num);
            if ($num === '') {
                continue;
            }

            if ($id === 'PACKAGE_HEIGHT') {
                $result['altura'] = $num;
            } elseif ($id === 'PACKAGE_WIDTH') {
                $result['largura'] = $num;
            } elseif ($id === 'PACKAGE_LENGTH') {
                $result['comprimento'] = $num;
            } elseif ($id === 'PACKAGE_WEIGHT') {
                $result['peso'] = $num;
            }
        }

        return $result;
    }

    /**
     * @return array{peso:string,altura:string,largura:string,comprimento:string}
     */
    private function parseDimensionsString(string $raw): array
    {
        $result = ['peso' => '', 'altura' => '', 'largura' => '', 'comprimento' => ''];
        $str = trim(mb_strtolower($raw));
        if ($str === '') {
            return $result;
        }
        $parts = array_map('trim', explode('x', str_replace(',', '.', $str)));
        if (count($parts) < 4) {
            return $result;
        }
        $result['altura'] = preg_replace('/[^0-9.]/', '', $parts[0]) ?? '';
        $result['largura'] = preg_replace('/[^0-9.]/', '', $parts[1]) ?? '';
        $result['comprimento'] = preg_replace('/[^0-9.]/', '', $parts[2]) ?? '';
        $result['peso'] = preg_replace('/[^0-9.]/', '', $parts[3]) ?? '';

        return $result;
    }

    /**
     * @param array{peso:string,altura:string,largura:string,comprimento:string} $dims
     */
    private function hasAnyDimension(array $dims): bool
    {
        return $dims['peso'] !== '' || $dims['altura'] !== '' || $dims['largura'] !== '' || $dims['comprimento'] !== '';
    }
}

