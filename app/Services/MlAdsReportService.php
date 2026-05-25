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

    /** Colunas do Excel (ordem fixa). */
    private const EXCEL_HEADERS = [
        'MLB',
        'Titulo',
        'Data Criacao',
        'Data Atualizacao',
        'Data Inicio',
        'Data Termino',
        'Status',
        'Substatus',
        'Condicao',
        'SKU',
        'Preco De',
        'Preco Por',
        'Moeda',
        'Modo Compra',
        'Tipo Anuncio',
        'listing_type_id',
        'Categoria ID',
        'Catalog Product ID',
        'Site ID',
        'Permalink',
        'Permalink API',
        'Link Ajustado',
        'Full',
        'Modo Envio',
        'Tipo Logistica',
        'Frete',
        'Frete Gratis',
        'Retira Loja',
        'Envio Local',
        'Estoque Disponivel',
        'Vendidos',
        'Qtd Inicial',
        'Peso kg',
        'Altura cm',
        'Largura cm',
        'Comprimento cm',
        'Aceita Mercado Pago',
        'Tags',
        'Qtd Variacoes',
        'Qtd Fotos',
        'Garantia',
        'Video ID',
        'Item Pai',
        'Marca',
        'Modelo',
        'EAN/GTIN',
    ];

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

        $rows = [self::EXCEL_HEADERS];
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

            $fields = $this->buildItemFields($item, $shipping, $sku, $typeLabel, $listingTypeId);
            $rows[] = $this->fieldsToExcelRow($fields);
            $preview[] = $this->fieldsToPreview($fields);
        }

        return [
            'header' => self::EXCEL_HEADERS,
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

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $shipping
     * @return array<string, string>
     */
    private function buildItemFields(
        array $item,
        array $shipping,
        string $sku,
        string $typeLabel,
        string $listingTypeId
    ): array {
        $dimensions = $this->extractDimensions($item, $shipping);
        $brandModel = $this->extractAttributeValues($item, ['BRAND', 'MODEL', 'GTIN', 'EAN']);
        $itemId = (string) ($item['id'] ?? '');
        $siteId = (string) ($item['site_id'] ?? '');
        $permalinkRaw = trim((string) ($item['permalink'] ?? ''));
        [$permalinkPublic, $permalinkAdjusted] = $this->resolvePublicPermalink($permalinkRaw, $itemId, $siteId);

        return [
            'MLB' => $itemId,
            'Titulo' => (string) ($item['title'] ?? ''),
            'Data Criacao' => $this->formatApiDateTime($item['date_created'] ?? null),
            'Data Atualizacao' => $this->formatApiDateTime($item['last_updated'] ?? null),
            'Data Inicio' => $this->formatApiDateTime($item['start_time'] ?? null),
            'Data Termino' => $this->formatApiDateTime($item['stop_time'] ?? null),
            'Status' => (string) ($item['status'] ?? ''),
            'Substatus' => $this->formatSubStatus($item['sub_status'] ?? null),
            'Condicao' => (string) ($item['condition'] ?? ''),
            'SKU' => $sku,
            'Preco De' => $this->formatMoney($item['original_price'] ?? null),
            'Preco Por' => $this->formatMoney($item['price'] ?? null),
            'Moeda' => (string) ($item['currency_id'] ?? ''),
            'Modo Compra' => (string) ($item['buying_mode'] ?? ''),
            'Tipo Anuncio' => $typeLabel,
            'listing_type_id' => $listingTypeId,
            'Categoria ID' => (string) ($item['category_id'] ?? ''),
            'Catalog Product ID' => (string) ($item['catalog_product_id'] ?? ''),
            'Site ID' => $siteId,
            'Permalink' => $permalinkPublic,
            'Permalink API' => $permalinkRaw,
            'Link Ajustado' => $permalinkAdjusted ? 'sim' : 'nao',
            'Full' => ((string) ($shipping['logistic_type'] ?? '') === 'fulfillment') ? 'SIM' : 'NAO',
            'Modo Envio' => (string) ($shipping['mode'] ?? ''),
            'Tipo Logistica' => (string) ($shipping['logistic_type'] ?? ''),
            'Frete' => $this->formatMoney($shipping['cost'] ?? null),
            'Frete Gratis' => $this->formatBool($shipping['free_shipping'] ?? false),
            'Retira Loja' => $this->formatBool($shipping['store_pick_up'] ?? false),
            'Envio Local' => $this->formatBool($shipping['local_pick_up'] ?? false),
            'Estoque Disponivel' => (string) ($item['available_quantity'] ?? ''),
            'Vendidos' => (string) ($item['sold_quantity'] ?? ''),
            'Qtd Inicial' => (string) ($item['initial_quantity'] ?? ''),
            'Peso kg' => $dimensions['peso'],
            'Altura cm' => $dimensions['altura'],
            'Largura cm' => $dimensions['largura'],
            'Comprimento cm' => $dimensions['comprimento'],
            'Aceita Mercado Pago' => $this->formatBool($item['accepts_mercadopago'] ?? false),
            'Tags' => $this->formatTags($item['tags'] ?? null),
            'Qtd Variacoes' => (string) $this->countVariations($item),
            'Qtd Fotos' => (string) $this->countPictures($item),
            'Garantia' => (string) ($item['warranty'] ?? ''),
            'Video ID' => (string) ($item['video_id'] ?? ''),
            'Item Pai' => (string) ($item['parent_item_id'] ?? ''),
            'Marca' => $brandModel['BRAND'] ?? '',
            'Modelo' => $brandModel['MODEL'] ?? '',
            'EAN/GTIN' => $brandModel['GTIN'] ?? $brandModel['EAN'] ?? '',
        ];
    }

    /**
     * @param array<string, string> $fields
     * @return list<string>
     */
    private function fieldsToExcelRow(array $fields): array
    {
        $row = [];
        foreach (self::EXCEL_HEADERS as $header) {
            $row[] = (string) ($fields[$header] ?? '');
        }

        return $row;
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, string>
     */
    private function fieldsToPreview(array $fields): array
    {
        return [
            'mlb' => $fields['MLB'],
            'titulo' => $fields['Titulo'],
            'data_criacao' => $fields['Data Criacao'],
            'data_atualizacao' => $fields['Data Atualizacao'],
            'data_inicio' => $fields['Data Inicio'],
            'data_termino' => $fields['Data Termino'],
            'status' => $fields['Status'],
            'substatus' => $fields['Substatus'],
            'condicao' => $fields['Condicao'],
            'sku' => $fields['SKU'],
            'preco_de' => $fields['Preco De'],
            'preco_por' => $fields['Preco Por'],
            'moeda' => $fields['Moeda'],
            'modo_compra' => $fields['Modo Compra'],
            'tipo' => $fields['Tipo Anuncio'],
            'listing_type_id' => $fields['listing_type_id'],
            'categoria_id' => $fields['Categoria ID'],
            'catalog_product_id' => $fields['Catalog Product ID'],
            'permalink' => $fields['Permalink'],
            'permalink_original' => $fields['Permalink API'],
            'permalink_ajustado' => $fields['Link Ajustado'],
            'full' => $fields['Full'],
            'modo_envio' => $fields['Modo Envio'],
            'tipo_logistica' => $fields['Tipo Logistica'],
            'frete' => $fields['Frete'],
            'frete_gratis' => $fields['Frete Gratis'],
            'estoque' => $fields['Estoque Disponivel'],
            'vendas' => $fields['Vendidos'],
            'qtd_inicial' => $fields['Qtd Inicial'],
            'tags' => $fields['Tags'],
            'variacoes' => $fields['Qtd Variacoes'],
            'fotos' => $fields['Qtd Fotos'],
            'garantia' => $fields['Garantia'],
            'marca' => $fields['Marca'],
            'modelo' => $fields['Modelo'],
            'ean' => $fields['EAN/GTIN'],
        ];
    }

    private function formatApiDateTime(mixed $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }
        try {
            $dt = new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            return $raw;
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

    private function formatBool(mixed $value): string
    {
        return !empty($value) ? 'sim' : 'nao';
    }

    private function formatSubStatus(mixed $subStatus): string
    {
        if (!is_array($subStatus)) {
            return '';
        }
        $parts = [];
        foreach ($subStatus as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $parts[] = trim($entry);
            }
        }

        return implode(', ', $parts);
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

    /**
     * @param array<string, mixed> $item
     * @param list<string> $ids
     * @return array<string, string>
     */
    private function extractAttributeValues(array $item, array $ids): array
    {
        $wanted = [];
        foreach ($ids as $id) {
            $wanted[strtoupper($id)] = '';
        }
        $attrs = $item['attributes'] ?? [];
        if (!is_array($attrs)) {
            return $wanted;
        }
        foreach ($attrs as $attr) {
            if (!is_array($attr)) {
                continue;
            }
            $attrId = strtoupper((string) ($attr['id'] ?? ''));
            if (!array_key_exists($attrId, $wanted)) {
                continue;
            }
            $value = trim((string) ($attr['value_name'] ?? $attr['value_id'] ?? ''));
            if ($value !== '') {
                $wanted[$attrId] = $value;
            }
        }

        return $wanted;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function countVariations(array $item): int
    {
        $variations = $item['variations'] ?? null;

        return is_array($variations) ? count($variations) : 0;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function countPictures(array $item): int
    {
        $pictures = $item['pictures'] ?? null;

        return is_array($pictures) ? count($pictures) : 0;
    }

    /**
     * Converte links internos do ML/Mercado Shops (nao abrem no navegador) para URL publica.
     *
     * @return array{0: string, 1: bool} [url publica, foi ajustado]
     */
    private function resolvePublicPermalink(string $permalink, string $itemId, string $siteId): array
    {
        if ($permalink === '') {
            $fallback = $this->buildFallbackPermalink($itemId, $siteId);

            return [$fallback, $fallback !== ''];
        }

        $host = strtolower((string) parse_url($permalink, PHP_URL_HOST));
        if ($host === 'internal-shop.mercadoshops.com.br') {
            $fixed = preg_replace(
                '#^https?://internal-shop\.mercadoshops\.com\.br#i',
                'https://www.mercadoshops.com.br',
                $permalink
            ) ?? '';

            return [$fixed !== '' ? $fixed : $this->buildFallbackPermalink($itemId, $siteId), true];
        }

        if ($this->isInternalPermalinkHost($host)) {
            $fallback = $this->buildFallbackPermalink($itemId, $siteId);

            return [$fallback !== '' ? $fallback : $permalink, true];
        }

        return [$permalink, false];
    }

    private function isInternalPermalinkHost(string $host): bool
    {
        if ($host === '') {
            return true;
        }
        $blocked = [
            'internal-shop.mercadoshops.com.br',
            'internal-shop.mercadolivre.com',
            'internal.mercadolibre.com',
        ];
        foreach ($blocked as $needle) {
            if ($host === $needle || str_ends_with($host, '.' . $needle)) {
                return true;
            }
        }
        if (str_starts_with($host, 'internal-') || str_contains($host, 'internal-shop')) {
            return true;
        }

        return false;
    }

    private function buildFallbackPermalink(string $itemId, string $siteId): string
    {
        $itemId = trim($itemId);
        if ($itemId === '') {
            return '';
        }

        $siteId = strtoupper(trim($siteId) !== '' ? trim($siteId) : 'MLB');
        /** @var array<string, string> $bases */
        $bases = [
            'MLB' => 'https://www.mercadolivre.com.br',
            'MLA' => 'https://www.mercadolivre.com.ar',
            'MLM' => 'https://www.mercadolivre.com.mx',
            'MLC' => 'https://www.mercadolivre.cl',
            'MLU' => 'https://www.mercadolivre.com.uy',
            'MCO' => 'https://www.mercadolivre.com.co',
            'MPE' => 'https://www.mercadolivre.com.pe',
        ];
        $base = $bases[$siteId] ?? $bases['MLB'];

        return $base . '/anuncios/' . rawurlencode($itemId);
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

