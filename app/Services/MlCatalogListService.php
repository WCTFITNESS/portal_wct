<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;
use RuntimeException;
use Shuchkin\SimpleXLSXGen;

/**
 * Lista publicacoes de catalogo do vendedor (catalog_listing=true na API ML).
 */
class MlCatalogListService
{
    /** @var list<string> */
    private const EXCEL_HEADERS = [
        'MLB',
        'Catalog Product ID',
        'Titulo',
        'Status',
        'SKU',
        'Preco',
        'Moeda',
        'Estoque',
        'Vendidos',
        'Categoria ID',
        'Condicao',
        'Data Criacao',
        'Data Atualizacao',
        'Tags',
        'Permalink',
    ];

    public function __construct(
        private TokenService $tokenService,
        private MercadoLivreClient $client,
        private SettingsRepository $settingsRepository
    ) {
    }

    /**
     * @param array{status?: ?string, sku?: ?string, catalog_product_id?: ?string} $filters
     * @return array{
     *   rows: list<list<string>>,
     *   preview: list<array<string, string>>,
     *   total_ids: int,
     *   matched_rows: int,
     *   count_by_status: array<string, int>
     * }
     */
    public function collectCatalogRows(int $maxItems = 200, array $filters = []): array
    {
        $maxItems = $maxItems <= 0 ? 0 : max(1, min(5000, $maxItems));
        $statusFilter = $this->normalizeStatusFilter((string) ($filters['status'] ?? 'todos'));
        $skuFilter = trim((string) ($filters['sku'] ?? ''));
        $catalogProductFilter = trim((string) ($filters['catalog_product_id'] ?? ''));

        $apiConfig = $this->settingsRepository->getApiConfig();
        $sellerId = trim((string) ($apiConfig['seller_id'] ?? ''));
        if ($sellerId === '' || !ctype_digit($sellerId)) {
            throw new RuntimeException('Seller ID invalido. Configure o user_id numerico em Configuracao API.');
        }

        $accessToken = $this->tokenService->getValidAccessToken();
        $itemIds = $this->fetchCatalogItemIds($sellerId, $accessToken, $maxItems, $statusFilter);
        if ($itemIds === []) {
            return [
                'rows' => [self::EXCEL_HEADERS],
                'preview' => [],
                'total_ids' => 0,
                'matched_rows' => 0,
                'count_by_status' => [],
            ];
        }

        $items = $this->fetchItemsInBatch($itemIds, $accessToken);

        $rows = [self::EXCEL_HEADERS];
        $preview = [];
        $matchedRows = 0;
        $countByStatus = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $sku = $this->extractSku($item);
            $catalogProductId = trim((string) ($item['catalog_product_id'] ?? ''));

            if ($skuFilter !== '' && stripos($sku, $skuFilter) === false) {
                continue;
            }
            if ($catalogProductFilter !== '' && stripos($catalogProductId, $catalogProductFilter) === false) {
                continue;
            }

            $status = (string) ($item['status'] ?? '');
            $matchedRows++;
            $statusKey = $status !== '' ? $status : 'desconhecido';
            $countByStatus[$statusKey] = ($countByStatus[$statusKey] ?? 0) + 1;

            $fields = $this->buildCatalogFields($item, $sku, $catalogProductId);
            $rows[] = array_values($fields);
            $preview[] = $this->fieldsToPreview($fields);
        }

        return [
            'rows' => $rows,
            'preview' => $preview,
            'total_ids' => count($itemIds),
            'matched_rows' => $matchedRows,
            'count_by_status' => $countByStatus,
        ];
    }

    /**
     * @param array{status?: ?string, sku?: ?string, catalog_product_id?: ?string} $filters
     * @return array{
     *   file_name: string,
     *   total_ids: int,
     *   total_rows: int,
     *   matched_rows: int,
     *   count_by_status: array<string, int>,
     *   preview: list<array<string, string>>
     * }
     */
    public function generateReport(int $maxItems = 200, array $filters = []): array
    {
        $data = $this->collectCatalogRows($maxItems, $filters);

        $fileName = 'ml_catalogos_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.xlsx';
        $filePath = $this->exportDirectory() . DIRECTORY_SEPARATOR . $fileName;

        require_once __DIR__ . '/../Lib/SimpleXLSXGen.php';
        $xlsx = SimpleXLSXGen::fromArray($data['rows'], 'Catalogos ML');
        if (!$xlsx->saveAs($filePath)) {
            throw new RuntimeException('Nao foi possivel salvar o arquivo de exportacao.');
        }

        return [
            'file_name' => $fileName,
            'total_ids' => $data['total_ids'],
            'total_rows' => max(0, count($data['rows']) - 1),
            'matched_rows' => $data['matched_rows'],
            'count_by_status' => $data['count_by_status'],
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

    private function normalizeStatusFilter(string $status): string
    {
        $status = strtolower(trim($status));
        $allowed = ['todos', 'active', 'paused', 'closed', 'under_review', 'inactive'];

        return in_array($status, $allowed, true) ? $status : 'todos';
    }

    /**
     * @return list<string>
     */
    private function fetchCatalogItemIds(
        string $sellerId,
        string $accessToken,
        int $maxItems,
        string $statusFilter
    ): array {
        if ($maxItems === 0 || $maxItems > 1000) {
            return $this->fetchCatalogItemIdsByScan($sellerId, $accessToken, $maxItems, $statusFilter);
        }

        $limit = 50;
        $offset = 0;
        $ids = [];
        $unlimited = $maxItems <= 0;

        while ($unlimited || count($ids) < $maxItems) {
            $path = '/users/' . rawurlencode($sellerId) . '/items/search?'
                . $this->catalogSearchQuery($statusFilter)
                . '&limit=' . $limit . '&offset=' . $offset;
            $res = $this->client->get($path, $accessToken);
            if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
                throw new RuntimeException('Falha ao listar catalogos. HTTP: ' . (string) ($res['status'] ?? 0));
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

    /**
     * @return list<string>
     */
    private function fetchCatalogItemIdsByScan(
        string $sellerId,
        string $accessToken,
        int $maxItems,
        string $statusFilter
    ): array {
        $ids = [];
        $unlimited = $maxItems <= 0;

        $initPath = '/users/' . rawurlencode($sellerId) . '/items/search?'
            . $this->catalogSearchQuery($statusFilter)
            . '&search_type=scan';
        $res = $this->client->get($initPath, $accessToken);
        if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
            throw new RuntimeException('Falha ao iniciar scan de catalogos. HTTP: ' . (string) ($res['status'] ?? 0));
        }

        $scrollId = (string) ($res['body']['scroll_id'] ?? '');
        if ($scrollId === '') {
            throw new RuntimeException('Scan de catalogos nao retornou scroll_id.');
        }

        while ($scrollId !== '' && ($unlimited || count($ids) < $maxItems)) {
            $path = '/users/' . rawurlencode($sellerId) . '/items/search?'
                . $this->catalogSearchQuery($statusFilter)
                . '&search_type=scan&scroll_id=' . rawurlencode($scrollId);
            $chunkRes = $this->client->get($path, $accessToken);
            if (($chunkRes['status'] ?? 0) < 200 || ($chunkRes['status'] ?? 0) >= 300) {
                throw new RuntimeException('Falha ao continuar scan de catalogos. HTTP: ' . (string) ($chunkRes['status'] ?? 0));
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

    private function catalogSearchQuery(string $statusFilter): string
    {
        $params = ['catalog_listing' => 'true'];
        if ($statusFilter !== 'todos') {
            $params['status'] = $statusFilter;
        }

        return http_build_query($params);
    }

    /**
     * @param list<string> $itemIds
     * @return list<array<string, mixed>>
     */
    private function fetchItemsInBatch(array $itemIds, string $accessToken): array
    {
        $items = [];
        foreach (array_chunk($itemIds, 20) as $chunk) {
            $path = '/items?ids=' . rawurlencode(implode(',', array_map('strval', $chunk)));
            $res = $this->client->get($path, $accessToken);
            if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300 || !is_array($res['body'] ?? null)) {
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

    /**
     * @param array<string, mixed> $item
     * @return array<string, string>
     */
    private function buildCatalogFields(array $item, string $sku, string $catalogProductId): array
    {
        $itemId = (string) ($item['id'] ?? '');
        $siteId = (string) ($item['site_id'] ?? '');
        $permalink = $this->resolvePublicPermalink(
            trim((string) ($item['permalink'] ?? '')),
            $itemId,
            $siteId
        );

        return [
            'MLB' => $itemId,
            'Catalog Product ID' => $catalogProductId,
            'Titulo' => (string) ($item['title'] ?? ''),
            'Status' => (string) ($item['status'] ?? ''),
            'SKU' => $sku,
            'Preco' => $this->formatMoney($item['price'] ?? null),
            'Moeda' => (string) ($item['currency_id'] ?? ''),
            'Estoque' => (string) ($item['available_quantity'] ?? ''),
            'Vendidos' => (string) ($item['sold_quantity'] ?? ''),
            'Categoria ID' => (string) ($item['category_id'] ?? ''),
            'Condicao' => (string) ($item['condition'] ?? ''),
            'Data Criacao' => $this->formatApiDateTime($item['date_created'] ?? null),
            'Data Atualizacao' => $this->formatApiDateTime($item['last_updated'] ?? null),
            'Tags' => $this->formatTags($item['tags'] ?? null),
            'Permalink' => $permalink,
        ];
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, string>
     */
    private function fieldsToPreview(array $fields): array
    {
        return [
            'mlb' => $fields['MLB'],
            'catalog_product_id' => $fields['Catalog Product ID'],
            'titulo' => $fields['Titulo'],
            'status' => $fields['Status'],
            'sku' => $fields['SKU'],
            'preco' => $fields['Preco'],
            'estoque' => $fields['Estoque'],
            'vendidos' => $fields['Vendidos'],
            'categoria_id' => $fields['Categoria ID'],
            'data_criacao' => $fields['Data Criacao'],
            'data_atualizacao' => $fields['Data Atualizacao'],
            'tags' => $fields['Tags'],
            'permalink' => $fields['Permalink'],
        ];
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
            $v = trim((string) ($attr['value_name'] ?? $attr['value_id'] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        return '';
    }

    private function formatApiDateTime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        try {
            $dt = new \DateTimeImmutable((string) $value);
        } catch (\Exception) {
            return (string) $value;
        }

        return $dt->format('d/m/Y H:i');
    }

    private function formatMoney(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_numeric($value)) {
            return number_format((float) $value, 2, ',', '.');
        }

        return (string) $value;
    }

    private function formatTags(mixed $tags): string
    {
        if (!is_array($tags)) {
            return '';
        }
        $parts = [];
        foreach ($tags as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                $parts[] = trim($tag);
            }
        }

        return implode(', ', $parts);
    }

    private function resolvePublicPermalink(string $permalink, string $itemId, string $siteId): string
    {
        if ($permalink === '') {
            return $this->buildFallbackPermalink($itemId, $siteId);
        }

        $host = strtolower((string) parse_url($permalink, PHP_URL_HOST));
        if ($host === 'internal-shop.mercadoshops.com.br') {
            $fixed = preg_replace(
                '#^https?://internal-shop\.mercadoshops\.com\.br#i',
                'https://www.mercadoshops.com.br',
                $permalink
            );

            return is_string($fixed) ? $fixed : $permalink;
        }

        return $permalink;
    }

    private function buildFallbackPermalink(string $itemId, string $siteId): string
    {
        if ($itemId === '') {
            return '';
        }

        $bases = [
            'MLB' => 'https://www.mercadolivre.com.br',
            'MLA' => 'https://www.mercadolivre.com.ar',
            'MLM' => 'https://www.mercadolivre.com.mx',
        ];
        $base = $bases[strtoupper($siteId)] ?? 'https://www.mercadolivre.com.br';

        return rtrim($base, '/') . '/' . rawurlencode($itemId);
    }

    /**
     * Detalhes da pagina de produto (catalogo ML) e vendedores concorrentes.
     *
     * @return array{
     *   ok: true,
     *   product: array<string, mixed>,
     *   competitors: list<array<string, mixed>>,
     *   competitor_total: int,
     *   competitors_warning: ?string
     * }
     */
    public function fetchCatalogDetail(string $catalogProductId, string $ourItemMlb = ''): array
    {
        $catalogProductId = trim($catalogProductId);
        if ($catalogProductId === '') {
            throw new RuntimeException('Catalog Product ID nao informado.');
        }

        $accessToken = $this->tokenService->getValidAccessToken();
        $apiConfig = $this->settingsRepository->getApiConfig();
        $ourSellerId = trim((string) ($apiConfig['seller_id'] ?? ''));

        $productBody = $this->apiGetBody('/products/' . rawurlencode($catalogProductId), $accessToken);

        $competitorResult = $this->fetchCompetingItems($catalogProductId, $accessToken, 200);
        $rawCompetitors = $competitorResult['items'];
        $competitorsWarning = $competitorResult['warning'];

        $sellerIds = [];
        foreach ($rawCompetitors as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sid = (string) ($row['seller_id'] ?? '');
            if ($sid !== '') {
                $sellerIds[$sid] = true;
            }
        }

        $buyBox = is_array($productBody['buy_box_winner'] ?? null) ? $productBody['buy_box_winner'] : [];
        $winnerItemId = (string) ($buyBox['item_id'] ?? '');
        $winnerSellerId = (string) ($buyBox['seller_id'] ?? '');

        $sellerInfo = $this->fetchSellerInfo(array_keys($sellerIds), $accessToken);

        $competitors = [];
        foreach ($rawCompetitors as $row) {
            if (!is_array($row)) {
                continue;
            }
            $competitors[] = $this->normalizeCompetitorRow(
                $row,
                $sellerInfo,
                $ourSellerId,
                $ourItemMlb,
                $winnerItemId,
                $winnerSellerId
            );
        }

        usort($competitors, static function (array $a, array $b): int {
            $pa = (float) ($a['price_raw'] ?? 0);
            $pb = (float) ($b['price_raw'] ?? 0);
            if ($pa === $pb) {
                return strcmp((string) ($a['seller_name'] ?? ''), (string) ($b['seller_name'] ?? ''));
            }

            return $pa <=> $pb;
        });

        return [
            'ok' => true,
            'product' => $this->normalizeProductDetail($productBody, $ourItemMlb),
            'competitors' => $competitors,
            'competitor_total' => count($competitors),
            'competitors_warning' => $competitorsWarning,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function apiGetBody(string $path, string $accessToken): array
    {
        $res = $this->client->get($path, $accessToken);
        $status = (int) ($res['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            $msg = is_array($res['body'] ?? null)
                ? (string) (($res['body']['message'] ?? $res['body']['error'] ?? '') ?: json_encode($res['body']))
                : (string) ($res['raw'] ?? '');
            throw new RuntimeException('Falha na API Mercado Livre (' . $status . '): ' . $path . ($msg !== '' ? ' — ' . $msg : ''));
        }
        $body = $res['body'] ?? null;

        return is_array($body) ? $body : [];
    }

    /**
     * @return array{items: list<array<string, mixed>>, warning: ?string}
     */
    private function fetchCompetingItems(string $catalogProductId, string $accessToken, int $maxItems): array
    {
        $maxItems = max(1, min(500, $maxItems));
        $all = [];
        $offset = 0;
        $limit = 50;
        $warning = null;

        while (count($all) < $maxItems) {
            $path = '/products/' . rawurlencode($catalogProductId)
                . '/items?limit=' . $limit . '&offset=' . $offset;
            $res = $this->client->get($path, $accessToken);
            $status = (int) ($res['status'] ?? 0);

            if ($status < 200 || $status >= 300) {
                $warning = 'Nao foi possivel listar vendedores concorrentes (HTTP ' . $status . '). '
                    . 'A API /products/{id}/items pode estar indisponivel para esta conta.';
                break;
            }

            $body = $res['body'] ?? null;
            if (!is_array($body)) {
                $warning = 'Resposta invalida ao listar concorrentes.';
                break;
            }

            $chunk = $body['results'] ?? [];
            if (!is_array($chunk) || $chunk === []) {
                break;
            }

            foreach ($chunk as $row) {
                if (is_array($row)) {
                    $all[] = $row;
                    if (count($all) >= $maxItems) {
                        break 2;
                    }
                }
            }

            $total = (int) ($body['paging']['total'] ?? 0);
            $offset += $limit;
            if ($offset >= $total) {
                break;
            }
        }

        return ['items' => $all, 'warning' => $warning];
    }

    /**
     * @param list<string> $sellerIds
     * @return array<string, array{nickname: string, company: string}>
     */
    private function fetchSellerInfo(array $sellerIds, string $accessToken): array
    {
        $info = [];
        $fetched = 0;
        foreach ($sellerIds as $sellerId) {
            if ($fetched >= 80) {
                break;
            }
            $sellerId = trim($sellerId);
            if ($sellerId === '' || isset($info[$sellerId])) {
                continue;
            }

            try {
                $body = $this->apiGetBody('/users/' . rawurlencode($sellerId), $accessToken);
                $company = '';
                if (is_array($body['company'] ?? null)) {
                    $company = trim((string) (($body['company']['brand_name'] ?? $body['company']['corporate_name'] ?? '')));
                }
                if ($company === '') {
                    $company = trim((string) ($body['business_name'] ?? ''));
                }
                $info[$sellerId] = [
                    'nickname' => trim((string) ($body['nickname'] ?? '')),
                    'company' => $company,
                ];
            } catch (\Throwable) {
                $info[$sellerId] = ['nickname' => '', 'company' => ''];
            }

            $fetched++;
        }

        return $info;
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function normalizeProductDetail(array $product, string $ourItemMlb): array
    {
        $buyBox = is_array($product['buy_box_winner'] ?? null) ? $product['buy_box_winner'] : [];
        $priceRange = is_array($product['buy_box_winner_price_range'] ?? null)
            ? $product['buy_box_winner_price_range']
            : [];

        $pictureUrl = '';
        $pictures = $product['pictures'] ?? null;
        if (is_array($pictures) && isset($pictures[0]) && is_array($pictures[0])) {
            $pictureUrl = (string) ($pictures[0]['url'] ?? $pictures[0]['secure_url'] ?? '');
        }

        $attributes = [];
        foreach (['attributes', 'main_features'] as $key) {
            $list = $product[$key] ?? null;
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $attr) {
                if (!is_array($attr)) {
                    continue;
                }
                $name = trim((string) ($attr['name'] ?? $attr['id'] ?? ''));
                $value = trim((string) ($attr['value_name'] ?? $attr['value'] ?? ''));
                if ($name !== '' && $value !== '') {
                    $attributes[] = ['name' => $name, 'value' => $value];
                }
            }
        }

        $shortDesc = '';
        if (is_array($product['short_description'] ?? null)) {
            $shortDesc = trim((string) ($product['short_description']['content'] ?? $product['short_description']['text'] ?? ''));
        }

        return [
            'id' => (string) ($product['id'] ?? ''),
            'name' => (string) ($product['name'] ?? $product['family_name'] ?? ''),
            'status' => (string) ($product['status'] ?? ''),
            'domain_id' => (string) ($product['domain_id'] ?? ''),
            'permalink' => (string) ($product['permalink'] ?? ''),
            'sold_quantity' => (string) ($product['sold_quantity'] ?? ''),
            'short_description' => $shortDesc,
            'picture_url' => $pictureUrl,
            'price_min' => $this->formatMoney($priceRange['min']['price'] ?? null),
            'price_max' => $this->formatMoney($priceRange['max']['price'] ?? null),
            'currency_id' => (string) ($priceRange['min']['currency_id'] ?? $buyBox['currency_id'] ?? ''),
            'buy_box_winner' => [
                'item_id' => (string) ($buyBox['item_id'] ?? ''),
                'seller_id' => (string) ($buyBox['seller_id'] ?? ''),
                'price' => $this->formatMoney($buyBox['price'] ?? null),
                'available_quantity' => (string) ($buyBox['available_quantity'] ?? ''),
                'condition' => (string) ($buyBox['condition'] ?? ''),
                'listing_type_id' => (string) ($buyBox['listing_type_id'] ?? ''),
            ],
            'attributes' => $attributes,
            'our_item_mlb' => trim($ourItemMlb),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array{nickname: string, company: string}> $sellerInfo
     * @return array<string, mixed>
     */
    private function normalizeCompetitorRow(
        array $row,
        array $sellerInfo,
        string $ourSellerId,
        string $ourItemMlb,
        string $winnerItemId,
        string $winnerSellerId
    ): array {
        $sellerId = (string) ($row['seller_id'] ?? '');
        $itemId = (string) ($row['item_id'] ?? '');
        $seller = $sellerInfo[$sellerId] ?? ['nickname' => '', 'company' => ''];
        $nickname = $seller['nickname'];
        $company = $seller['company'];
        $sellerLabel = $company !== '' ? $company : ($nickname !== '' ? $nickname : ('Vendedor ' . $sellerId));

        $shipping = is_array($row['shipping'] ?? null) ? $row['shipping'] : [];
        $priceRaw = is_numeric($row['price'] ?? null) ? (float) $row['price'] : 0.0;

        $isOurSeller = $ourSellerId !== '' && $sellerId === $ourSellerId;
        $isOurItem = $ourItemMlb !== '' && strtoupper($itemId) === strtoupper($ourItemMlb);
        $isWinner = ($winnerItemId !== '' && strtoupper($itemId) === strtoupper($winnerItemId))
            || ($winnerSellerId !== '' && $sellerId === $winnerSellerId && $winnerItemId === '');

        return [
            'seller_id' => $sellerId,
            'seller_nickname' => $nickname,
            'seller_company' => $company,
            'seller_name' => $sellerLabel,
            'item_id' => $itemId,
            'price' => $this->formatMoney($row['price'] ?? null),
            'price_raw' => $priceRaw,
            'currency_id' => (string) ($row['currency_id'] ?? ''),
            'available_quantity' => (string) ($row['available_quantity'] ?? ''),
            'sold_quantity' => (string) ($row['sold_quantity'] ?? ''),
            'condition' => (string) ($row['condition'] ?? ''),
            'listing_type_id' => (string) ($row['listing_type_id'] ?? ''),
            'free_shipping' => !empty($shipping['free_shipping']) ? 'sim' : 'nao',
            'logistic_type' => (string) ($shipping['logistic_type'] ?? $shipping['mode'] ?? ''),
            'official_store_id' => (string) ($row['official_store_id'] ?? ''),
            'is_our_seller' => $isOurSeller,
            'is_our_item' => $isOurItem,
            'is_buy_box_winner' => $isWinner,
        ];
    }

    private function exportDirectory(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ml-portal-ml-catalogos';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Nao foi possivel criar pasta temporaria para exportacao.');
        }

        return $dir;
    }
}
