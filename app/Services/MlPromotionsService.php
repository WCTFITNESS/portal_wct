<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;
use RuntimeException;
use Shuchkin\SimpleXLSXGen;

/**
 * Campanhas / promocoes do Mercado Livre (migrado do WCT Code).
 */
class MlPromotionsService
{
    /** @var list<string> */
    private const EXPORT_HEADERS = [
        'SKU',
        'MLB',
        'SKU_2',
        'VERDADEIRO',
        'Preço de',
        'Estoque',
        'Tipo Campanha',
        'Desconto ML R$',
        'Desconto ML %',
        'Nosso desconto R$',
        'Nosso desconto %',
        'Nosso desconto R$ Meli+',
        'Nosso desconto % Meli+',
        'ML Desconto R$ Meli+',
        'ML Desconto % Meli+',
        'Preço final',
        'Data Inicial',
        'Data Final',
        'Status do Item',
        'Status Campanha',
        'Quantidade Vendida',
        'Anúncio Status',
        'CODE',
        'ID',
        'TYPE',
    ];

    /** @var list<string> */
    private const HIDDEN_CAMPAIGN_TYPES = ['DEAL', 'SELLER_CAMPAIGN'];

    public function __construct(
        private TokenService $tokenService,
        private MercadoLivreClient $client,
        private SettingsRepository $settingsRepository
    ) {
    }

    /**
     * @return list<array{data: array<string, mixed>, total: int|null}>
     */
    public function listCampaignSummaries(string $itemStatus): array
    {
        $itemStatus = $this->normalizeItemStatus($itemStatus);
        $accessToken = $this->tokenService->getValidAccessToken();
        $sellerId = $this->resolveSellerId();

        $res = $this->client->get(
            '/seller-promotions/users/' . rawurlencode($sellerId) . '?app_version=v2',
            $accessToken
        );
        if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
            throw new RuntimeException(
                'Falha ao listar promocoes ML. HTTP ' . (string) ($res['status'] ?? 0)
            );
        }

        $results = $res['body']['results'] ?? [];
        if (!is_array($results)) {
            return [];
        }

        $summaries = [];
        foreach ($results as $row) {
            if (!is_array($row) || empty($row['id']) || empty($row['type'])) {
                continue;
            }
            $type = (string) $row['type'];
            if (in_array($type, self::HIDDEN_CAMPAIGN_TYPES, true)) {
                continue;
            }

            $data = $this->normalizeCampaignDates($row);
            $summaries[] = [
                'data' => $data,
                'total' => null,
            ];
        }

        return $summaries;
    }

    /**
     * @param list<array{id: string, type: string}> $selected
     */
    public function exportCampaignAnalytics(array $selected, string $itemStatus): string
    {
        $itemStatus = $this->normalizeItemStatus($itemStatus);
        if ($selected === []) {
            throw new RuntimeException('Selecione ao menos uma campanha.');
        }

        $accessToken = $this->tokenService->getValidAccessToken();
        $allCampaigns = $this->fetchAllCampaigns($accessToken);
        $selectedMap = [];
        foreach ($selected as $item) {
            $id = trim((string) ($item['id'] ?? ''));
            if ($id !== '') {
                $selectedMap[$id] = true;
            }
        }

        $campaignResults = [];
        foreach ($allCampaigns as $campaign) {
            if (!isset($selectedMap[(string) ($campaign['id'] ?? '')])) {
                continue;
            }

            $id = (string) $campaign['id'];
            $type = (string) ($campaign['type'] ?? '');
            $name = (string) ($campaign['name'] ?? '');
            $status = (string) ($campaign['status'] ?? '');
            $startDate = (string) ($campaign['start_date'] ?? '');
            $finishDate = (string) ($campaign['finish_date'] ?? $campaign['end_date'] ?? '');

            $promotions = $this->fetchPromotionItems(
                $accessToken,
                $id,
                $type,
                $itemStatus,
                $name,
                $status,
                $startDate,
                $finishDate
            );
            foreach ($promotions as $promo) {
                if (is_array($promo)) {
                    $campaignResults[] = $promo;
                }
            }
        }

        $rows = [self::EXPORT_HEADERS];
        foreach ($this->buildExportRows($campaignResults, $accessToken) as $row) {
            $rows[] = $row;
        }

        $fileName = 'ml_campanhas_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.xlsx';
        $filePath = $this->exportDirectory() . DIRECTORY_SEPARATOR . $fileName;

        require_once __DIR__ . '/../Lib/SimpleXLSXGen.php';
        $xlsx = SimpleXLSXGen::fromArray($rows, 'Campanhas');
        if (!$xlsx->saveAs($filePath)) {
            throw new RuntimeException('Nao foi possivel salvar o relatorio de campanhas.');
        }

        return $filePath;
    }

    /**
     * @return array{ok: int, errors: int, messages: list<string>}
     */
    public function processCampaignSpreadsheet(string $filePath): array
    {
        if (!is_file($filePath)) {
            throw new RuntimeException('Arquivo de planilha nao encontrado.');
        }

        require_once __DIR__ . '/../Lib/SimpleXLSX.php';
        $xlsx = \Shuchkin\SimpleXLSX::parse($filePath);
        if ($xlsx === false) {
            throw new RuntimeException('Nao foi possivel ler a planilha: ' . \Shuchkin\SimpleXLSX::parseError());
        }

        $sheetRows = $xlsx->rows();
        if ($sheetRows === [] || count($sheetRows) < 2) {
            throw new RuntimeException('Planilha vazia ou sem dados.');
        }

        $headers = array_map(static fn ($h) => trim((string) $h), $sheetRows[0]);
        $headerIndex = [];
        foreach ($headers as $idx => $header) {
            if ($header !== '') {
                $headerIndex[strtoupper($header)] = $idx;
            }
        }

        $accessToken = $this->tokenService->getValidAccessToken();
        $ok = 0;
        $errors = 0;
        $messages = [];

        for ($i = 1, $c = count($sheetRows); $i < $c; $i++) {
            $row = $sheetRows[$i];
            if (!is_array($row)) {
                continue;
            }

            $mlb = trim((string) ($row[$headerIndex['MLB'] ?? -1] ?? ''));
            $type = trim((string) ($row[$headerIndex['TYPE'] ?? -1] ?? ''));
            $code = trim((string) ($row[$headerIndex['CODE'] ?? -1] ?? ''));
            $id = trim((string) ($row[$headerIndex['ID'] ?? -1] ?? ''));
            $price = trim((string) ($row[$headerIndex['PREÇO FINAL'] ?? $headerIndex['PRECO FINAL'] ?? -1] ?? ''));

            if ($mlb === '' || $type === '' || $id === '') {
                continue;
            }

            $payload = $this->buildEnrollmentPayload($type, $id, $code, $price);
            if ($payload === null) {
                $errors++;
                $messages[] = "Linha {$i}: tipo de campanha nao suportado ({$type}).";
                continue;
            }

            $path = '/seller-promotions/items/' . rawurlencode($mlb) . '?app_version=v2';
            $res = $this->client->post($path, $payload, $accessToken);
            if (($res['status'] ?? 0) >= 200 && ($res['status'] ?? 0) < 300) {
                $ok++;
            } else {
                $errors++;
                $msg = is_array($res['body'] ?? null)
                    ? (string) (($res['body']['message'] ?? $res['body']['error'] ?? '') ?: json_encode($res['body']))
                    : (string) ($res['raw'] ?? '');
                $messages[] = "MLB {$mlb}: HTTP " . (string) ($res['status'] ?? 0) . ($msg !== '' ? " — {$msg}" : '');
            }

            usleep(500000);
        }

        return ['ok' => $ok, 'errors' => $errors, 'messages' => $messages];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildEnrollmentPayload(string $type, string $id, string $code, string $price): ?array
    {
        return match ($type) {
            'MARKETPLACE_CAMPAIGN' => [
                'promotion_id' => $id,
                'promotion_type' => $type,
            ],
            'SMART', 'PRICE_MATCHING' => [
                'promotion_id' => $id,
                'promotion_type' => $type,
                'offer_id' => $code,
            ],
            'UNHEALTHY_STOCK' => [
                'promotion_id' => $id,
                'offer_id' => $code,
                'promotion_type' => $type,
            ],
            'SELLER_CAMPAIGN' => [
                'promotion_id' => $id,
                'promotion_type' => $type,
                'deal_price' => $this->parsePrice($price),
            ],
            default => null,
        };
    }

    private function parsePrice(string $price): float
    {
        $clean = str_replace(['.', ','], ['', '.'], trim($price));
        if (!is_numeric($clean)) {
            return 0.0;
        }

        return (float) $clean;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAllCampaigns(string $accessToken): array
    {
        $sellerId = $this->resolveSellerId();
        $res = $this->client->get(
            '/seller-promotions/users/' . rawurlencode($sellerId) . '?app_version=v2',
            $accessToken
        );
        if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
            throw new RuntimeException('Falha ao buscar campanhas.');
        }

        $results = $res['body']['results'] ?? [];

        return is_array($results) ? $results : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchPromotionItems(
        string $accessToken,
        string $promotionCode,
        string $promotionType,
        string $status,
        string $title,
        string $campaignStatus,
        string $startDate,
        string $finishDate,
        ?string $searchAfter = null
    ): array {
        $path = '/seller-promotions/promotions/' . rawurlencode($promotionCode)
            . '/items?promotion_type=' . rawurlencode($promotionType)
            . '&app_version=v2&limit=100&status=' . rawurlencode($status);
        if ($searchAfter !== null && $searchAfter !== '') {
            $path .= '&searchAfter=' . rawurlencode($searchAfter);
        }

        $res = $this->client->get($path, $accessToken);
        if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
            return [];
        }

        $results = $res['body']['results'] ?? [];
        $paging = is_array($res['body']['paging'] ?? null) ? $res['body']['paging'] : [];
        if (!is_array($results)) {
            return [];
        }

        $items = [];
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }
            $result['title'] = $title;
            $result['type'] = $promotionType;
            $result['status_campaing'] = $campaignStatus;
            $result['id_campaign'] = $promotionCode;

            if ($promotionType === 'DEAL') {
                $result['start_date'] = $startDate;
                $result['end_date'] = $finishDate;
            }
            if (in_array($promotionType, ['DOD', 'LIGHTNING'], true)) {
                $today = (new \DateTimeImmutable('now'))->format('Y-m-d') . 'T03:00:00Z';
                $result['start_date'] = $today;
                $result['end_date'] = $today;
            }
            if (in_array($promotionType, ['DEAL', 'SELLER_CAMPAIGN'], true)) {
                $result['meli_percentage'] = 0;
                $result['seller_percentage'] = 0;
            }
            if ($promotionCode === 'C-MLB1522238') {
                $result['seller_percentage'] = 5;
            }
            if ($promotionType === 'DOD') {
                $result['title'] = 'Oferta do Dia';
            }
            if ($promotionType === 'LIGHTNING') {
                $result['title'] = 'Oferta Relâmpago';
            }

            $items[] = $result;
        }

        $next = (string) ($paging['searchAfter'] ?? '');
        if ($next !== '') {
            $items = array_merge(
                $items,
                $this->fetchPromotionItems(
                    $accessToken,
                    $promotionCode,
                    $promotionType,
                    $status,
                    $title,
                    $campaignStatus,
                    $startDate,
                    $finishDate,
                    $next
                )
            );
        }

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<list<string|int|float>>
     */
    private function buildExportRows(array $items, string $accessToken): array
    {
        $rows = [];
        foreach ($items as $item) {
            $mlb = (string) ($item['id'] ?? '');
            if ($mlb === '') {
                continue;
            }

            $ads = $this->fetchItem($mlb, $accessToken);
            if ($ads === null) {
                continue;
            }

            $sku = $this->extractSku($ads);
            $meliMoeda = 0.0;
            $vendedorMoeda = 0.0;
            $meliPct = (float) ($item['meli_percentage'] ?? 0);
            $sellerPct = (float) ($item['seller_percentage'] ?? 0);
            $originalPrice = (float) ($item['original_price'] ?? $item['price'] ?? 0);
            $price = (float) ($item['price'] ?? 0);
            $type = (string) ($item['type'] ?? '');

            if ($meliPct > 0) {
                $meliMoeda = round($price * (ceil($meliPct) / 100), 2);
            }
            if ($sellerPct > 0) {
                $vendedorMoeda = round($originalPrice * ($sellerPct / 100), 2);
            }

            $finalPrice = (string) $price;
            if ($type === 'SELLER_CAMPAIGN') {
                $finalPrice = number_format($originalPrice - $vendedorMoeda, 2, ',', '');
            }
            if ($type === 'VOLUME') {
                $discountPct = (float) ($item['discount_percentage'] ?? 0);
                $vendedorMoeda = $originalPrice * ($discountPct / 100);
            }
            if (in_array($type, ['DOD', 'LIGHTNING'], true)) {
                $finalPrice = '';
            }

            $rows[] = [
                $sku,
                $mlb,
                '-',
                'verdadeiro',
                $originalPrice,
                (int) ($ads['available_quantity'] ?? 0),
                (string) ($item['title'] ?? ''),
                $meliMoeda,
                $meliPct > 0 ? (int) ceil($meliPct) : 0,
                $vendedorMoeda,
                $sellerPct,
                '',
                '',
                '',
                '',
                $finalPrice,
                $this->formatDate((string) ($item['start_date'] ?? '')),
                $this->formatDate((string) ($item['end_date'] ?? '')),
                (string) ($item['status'] ?? ''),
                (string) ($item['status_campaing'] ?? ''),
                (int) ($ads['sold_quantity'] ?? 0),
                (string) ($ads['status'] ?? ''),
                (string) ($item['offer_id'] ?? ''),
                (string) ($item['id_campaign'] ?? ''),
                $type,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchItem(string $mlb, string $accessToken): ?array
    {
        $res = $this->client->get('/items/' . rawurlencode($mlb), $accessToken);
        if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
            return null;
        }
        $body = $res['body'] ?? null;

        return is_array($body) ? $body : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeCampaignDates(array $row): array
    {
        $startRaw = $row['start_date'] ?? null;
        $finishRaw = $row['finish_date'] ?? $row['end_date'] ?? $row['deadline_date'] ?? null;

        if ($startRaw === null && $finishRaw === null) {
            $row['start_date'] = '—';
            $row['end_date'] = '—';

            return $row;
        }

        if ($startRaw === null || $finishRaw === null) {
            $row['start_date'] = $startRaw !== null ? substr((string) $startRaw, 0, 10) : '—';
            $row['end_date'] = $finishRaw !== null ? substr((string) $finishRaw, 0, 10) : '—';

            return $row;
        }

        try {
            $start = new \DateTimeImmutable(preg_replace('/\.\d{3,}/', '', (string) $startRaw) ?: (string) $startRaw);
            $finish = new \DateTimeImmutable(preg_replace('/\.\d{3,}/', '', (string) $finishRaw) ?: (string) $finishRaw);
            $row['start_date'] = $start->modify('+7 days')->format('d/m/Y');
            $row['end_date'] = $finish->modify('+7 days')->format('d/m/Y');
        } catch (\Throwable) {
            $row['start_date'] = substr((string) $startRaw, 0, 10);
            $row['end_date'] = substr((string) $finishRaw, 0, 10);
        }

        return $row;
    }

    private function formatDate(string $iso): string
    {
        if (trim($iso) === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($iso))->format('d-m-Y');
        } catch (\Throwable) {
            return substr($iso, 0, 10);
        }
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
            $id = strtoupper((string) ($attr['id'] ?? ''));
            if ($id !== 'SELLER_SKU' && $id !== 'MODEL') {
                continue;
            }
            $value = trim((string) ($attr['value_name'] ?? ''));
            if ($this->isValidWctSku($value)) {
                return $value;
            }
        }

        $variations = $item['variations'] ?? [];
        if (!is_array($variations)) {
            return '';
        }

        foreach ($variations as $variation) {
            if (!is_array($variation)) {
                continue;
            }
            $relations = $variation['item_relations'] ?? [];
            if (!is_array($relations) || $relations === []) {
                continue;
            }
            $variationId = (string) ($relations[0]['id'] ?? '');
            if ($variationId === '') {
                continue;
            }
            // Variations resolved in export loop only when needed — skip deep fetch for performance
        }

        return '';
    }

    private function isValidWctSku(string $value): bool
    {
        return $value !== ''
            && preg_match('/^\d+$/', $value) === 1
            && strlen($value) === 8
            && !str_starts_with($value, '5');
    }

    private function normalizeItemStatus(string $status): string
    {
        $status = strtolower(trim($status));
        $allowed = ['candidate', 'pending', 'started'];

        return in_array($status, $allowed, true) ? $status : 'candidate';
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
