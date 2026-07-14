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
    /** Colunas identicas ao relatorio Campanha_ML.xlsx (WCT Code). */
    private const EXPORT_HEADERS = [
        'SKU',
        'MLB',
        'SKU',
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
        'Type',
    ];

    /** @var list<string> Tipos criados pelo vendedor — ocultar da lista ML. */
    private const HIDDEN_CAMPAIGN_TYPES = ['SELLER_CAMPAIGN'];

    /** @var list<string> Status de campanha encerrada (nao exibir). */
    private const CLOSED_CAMPAIGN_STATUSES = ['finished', 'expired'];

    public function __construct(
        private TokenService $tokenService,
        private MercadoLivreClient $client,
        private SettingsRepository $settingsRepository
    ) {
    }

    /**
     * @return array{summaries: list<array{data: array<string, mixed>, total: int|null}>, meta: array<string, int|string>}
     */
    public function listCampaignSummaries(string $itemStatus): array
    {
        $itemStatus = $this->normalizeItemStatus($itemStatus);
        $accessToken = $this->tokenService->getValidAccessToken();
        $sellerId = $this->resolveSellerId();

        $allRows = $this->fetchAllUserPromotions($accessToken, $sellerId);
        $summaries = [];
        $skippedType = 0;
        $skippedClosed = 0;

        foreach ($allRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            $type = trim((string) ($row['type'] ?? ''));
            if ($id === '' || $type === '') {
                continue;
            }
            if (in_array($type, self::HIDDEN_CAMPAIGN_TYPES, true)) {
                $skippedType++;
                continue;
            }

            $campaignStatus = strtolower(trim((string) ($row['status'] ?? '')));
            if ($campaignStatus !== '' && in_array($campaignStatus, self::CLOSED_CAMPAIGN_STATUSES, true)) {
                $skippedClosed++;
                continue;
            }

            $itemTotal = $this->fetchPromotionItemTotal($accessToken, $id, $type, $itemStatus);

            $data = $this->normalizeCampaignDates($row);
            $data['status'] = $campaignStatus !== '' ? $campaignStatus : (string) ($row['status'] ?? '');
            $summaries[] = [
                'data' => $data,
                'total' => $itemTotal,
            ];
        }

        usort($summaries, static function (array $a, array $b): int {
            $ta = $a['total'] ?? -1;
            $tb = $b['total'] ?? -1;
            if ($ta === $tb) {
                return strcmp((string) ($a['data']['name'] ?? ''), (string) ($b['data']['name'] ?? ''));
            }
            if ($ta === null) {
                return 1;
            }
            if ($tb === null) {
                return -1;
            }

            return $tb <=> $ta;
        });

        return [
            'summaries' => $summaries,
            'meta' => [
                'seller_id' => $sellerId,
                'raw_total' => count($allRows),
                'shown' => count($summaries),
                'skipped_type' => $skippedType,
                'skipped_closed' => $skippedClosed,
                'item_status' => $itemStatus,
            ],
        ];
    }

    /**
     * @param list<array{id: string, type: string}> $selected
     */
    public function exportCampaignAnalytics(array $selected, string $itemStatus): string
    {
        @set_time_limit(600);

        $itemStatus = $this->normalizeItemStatus($itemStatus);
        if ($selected === []) {
            throw new RuntimeException('Selecione ao menos uma campanha.');
        }

        $accessToken = $this->tokenService->getValidAccessToken();
        $allCampaigns = $this->fetchAllCampaigns($accessToken);
        $campaignById = [];
        foreach ($allCampaigns as $campaign) {
            if (!is_array($campaign)) {
                continue;
            }
            $cid = trim((string) ($campaign['id'] ?? ''));
            if ($cid !== '') {
                $campaignById[$cid] = $campaign;
            }
        }

        $campaignResults = [];
        $fetchErrors = [];
        foreach ($selected as $sel) {
            if (!is_array($sel)) {
                continue;
            }
            $id = trim((string) ($sel['id'] ?? ''));
            $type = trim((string) ($sel['type'] ?? ''));
            if ($id === '' || $type === '') {
                continue;
            }

            $campaign = $campaignById[$id] ?? null;
            $name = is_array($campaign) ? trim((string) ($campaign['name'] ?? '')) : '';
            $status = is_array($campaign) ? (string) ($campaign['status'] ?? 'started') : 'started';
            $startDate = is_array($campaign) ? (string) ($campaign['start_date'] ?? '') : '';
            $finishDate = is_array($campaign)
                ? (string) ($campaign['finish_date'] ?? $campaign['end_date'] ?? '')
                : '';
            $benefits = is_array($campaign) && is_array($campaign['benefits'] ?? null)
                ? $campaign['benefits']
                : [];

            try {
                $promotions = $this->fetchPromotionItems(
                    $accessToken,
                    $id,
                    $type,
                    $itemStatus,
                    $name !== '' ? $name : $id,
                    $status,
                    $startDate,
                    $finishDate,
                    null,
                    true,
                    $benefits
                );
            } catch (RuntimeException $e) {
                $fetchErrors[] = $id . ': ' . $e->getMessage();
                continue;
            }

            foreach ($promotions as $promo) {
                if (is_array($promo)) {
                    $campaignResults[] = $promo;
                }
            }
        }

        if ($campaignResults === []) {
            $detail = $fetchErrors !== []
                ? implode(' | ', array_slice($fetchErrors, 0, 5))
                : 'Nenhum item retornado pela API para as campanhas selecionadas.';
            throw new RuntimeException('Relatorio vazio. ' . $detail);
        }

        $rows = [self::EXPORT_HEADERS];
        foreach ($this->buildExportRows($campaignResults, $accessToken) as $row) {
            $rows[] = $row;
        }

        if (count($rows) <= 1) {
            throw new RuntimeException(
                'Nenhuma linha gerada no relatorio. Verifique token ML e campanhas selecionadas.'
            );
        }

        $fileName = 'campanha.xlsx';
        $filePath = $this->exportDirectory() . DIRECTORY_SEPARATOR . $fileName . '_' . bin2hex(random_bytes(4)) . '.xlsx';

        require_once __DIR__ . '/../Lib/SimpleXLSXGen.php';
        $xlsx = SimpleXLSXGen::fromArray($rows, 'Vendas');
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
            $type = trim((string) ($row[$headerIndex['TYPE'] ?? $headerIndex['Type'] ?? -1] ?? ''));
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
     * Planilha de demonstração para upload (mesmas colunas esperadas pelo WCT Code / portal).
     */
    public function generateCampaignUploadDemo(): string
    {
        $rows = [
            ['MLB', 'TYPE', 'CODE', 'ID', 'PREÇO FINAL'],
            ['MLB1234567890', 'SMART', 'OFFER-EXEMPLO-001', 'PROMO-EXEMPLO-001', ''],
            ['MLB0987654321', 'MARKETPLACE_CAMPAIGN', '', 'PROMO-EXEMPLO-002', ''],
            ['MLB1122334455', 'PRICE_MATCHING', 'OFFER-EXEMPLO-003', 'PROMO-EXEMPLO-003', ''],
            ['MLB5566778899', 'UNHEALTHY_STOCK', 'OFFER-EXEMPLO-004', 'PROMO-EXEMPLO-004', ''],
            ['MLB9988776655', 'SELLER_CAMPAIGN', '', 'PROMO-EXEMPLO-005', '89,90'],
        ];

        $filePath = $this->exportDirectory() . DIRECTORY_SEPARATOR . 'campanha_upload_demo_' . bin2hex(random_bytes(4)) . '.xlsx';
        require_once __DIR__ . '/../Lib/SimpleXLSXGen.php';
        $xlsx = SimpleXLSXGen::fromArray($rows, 'Upload');
        if (!$xlsx->saveAs($filePath)) {
            throw new RuntimeException('Nao foi possivel gerar planilha de demonstracao.');
        }

        return $filePath;
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

        return $this->fetchAllUserPromotions($accessToken, $sellerId);
    }

    /**
     * Lista todas as promocoes do vendedor (com paginacao offset/limit).
     *
     * @return list<array<string, mixed>>
     */
    private function fetchAllUserPromotions(string $accessToken, string $sellerId): array
    {
        $all = [];
        $offset = 0;
        $limit = 50;
        $total = null;

        do {
            $path = '/seller-promotions/users/' . rawurlencode($sellerId)
                . '?app_version=v2&limit=' . $limit . '&offset=' . $offset;
            $res = $this->client->get($path, $accessToken);
            if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
                $msg = is_array($res['body'] ?? null)
                    ? (string) (($res['body']['message'] ?? $res['body']['error'] ?? '') ?: json_encode($res['body']))
                    : (string) ($res['raw'] ?? '');
                throw new RuntimeException(
                    'Falha ao listar promocoes ML. HTTP ' . (string) ($res['status'] ?? 0)
                    . ($msg !== '' ? ' — ' . $msg : '')
                );
            }

            $body = is_array($res['body'] ?? null) ? $res['body'] : [];
            $chunk = $body['results'] ?? [];
            if (!is_array($chunk) || $chunk === []) {
                break;
            }

            foreach ($chunk as $row) {
                if (is_array($row)) {
                    $all[] = $row;
                }
            }

            $paging = is_array($body['paging'] ?? null) ? $body['paging'] : [];
            $total = (int) ($paging['total'] ?? count($all));
            $offset += $limit;
        } while ($offset < $total);

        return $all;
    }

    private function fetchPromotionItemTotal(
        string $accessToken,
        string $promotionId,
        string $promotionType,
        string $itemStatus
    ): ?int {
        $path = '/seller-promotions/promotions/' . rawurlencode($promotionId)
            . '/items?promotion_type=' . rawurlencode($promotionType)
            . '&app_version=v2&limit=1&status=' . rawurlencode($itemStatus);

        $res = $this->client->get($path, $accessToken);
        if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
            return null;
        }

        $paging = is_array($res['body']['paging'] ?? null) ? $res['body']['paging'] : [];

        return (int) ($paging['total'] ?? 0);
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
        ?string $searchAfter = null,
        bool $strict = false,
        array $campaignBenefits = []
    ): array {
        $path = '/seller-promotions/promotions/' . rawurlencode($promotionCode)
            . '/items?promotion_type=' . rawurlencode($promotionType)
            . '&app_version=v2&limit=50&status=' . rawurlencode($status);
        if ($searchAfter !== null && $searchAfter !== '') {
            $path .= '&search_after=' . rawurlencode($searchAfter);
        }

        $res = $this->client->get($path, $accessToken);
        if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
            if ($strict) {
                $msg = is_array($res['body'] ?? null)
                    ? (string) (($res['body']['message'] ?? $res['body']['error'] ?? '') ?: json_encode($res['body']))
                    : (string) ($res['raw'] ?? '');
                throw new RuntimeException(
                    'HTTP ' . (string) ($res['status'] ?? 0) . ($msg !== '' ? ' — ' . $msg : '')
                );
            }

            return [];
        }

        $body = is_array($res['body'] ?? null) ? $res['body'] : [];
        $results = $body['results'] ?? [];
        $paging = is_array($body['paging'] ?? null) ? $body['paging'] : [];
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

            if ($campaignBenefits !== []) {
                $itemBenefits = is_array($result['benefits'] ?? null) ? $result['benefits'] : [];
                $result['benefits'] = array_merge($campaignBenefits, $itemBenefits);
            }

            $this->applyPromotionItemPercentages($result);

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

        $next = (string) ($paging['searchAfter'] ?? $paging['search_after'] ?? '');
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
                    $next,
                    $strict,
                    $campaignBenefits
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
        $mlbIds = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $mlb = trim((string) ($item['id'] ?? ''));
            if ($mlb !== '') {
                $mlbIds[] = $mlb;
            }
        }

        $adsMap = $this->fetchItemsBatchMap(array_values(array_unique($mlbIds)), $accessToken);

        $rows = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $mlb = trim((string) ($item['id'] ?? ''));
            if ($mlb === '') {
                continue;
            }
            $rows[] = $this->buildExportRow($item, $accessToken, $adsMap);
        }

        return $rows;
    }

    /**
     * @param array<string, array<string, mixed>> $adsMap
     * @return list<string|int|float>
     */
    private function buildExportRow(array $item, string $accessToken, array $adsMap = []): array
    {
        $mlb = (string) ($item['id'] ?? '');
        $ads = $mlb !== '' ? ($adsMap[$mlb] ?? null) : null;

        $sku = '';
        $stock = 0;
        $sold = 0;
        $adsStatus = '';
        if (is_array($ads)) {
            $sku = $this->extractExportSkuFast($ads);
            $stock = (int) ($ads['available_quantity'] ?? 0);
            $sold = (int) ($ads['sold_quantity'] ?? 0);
            $adsStatus = (string) ($ads['status'] ?? '');
        }

        $meliPct = $this->readPercentage($item, 'meli');
        $sellerPct = $this->readPercentage($item, 'seller');
        $originalPrice = (float) ($item['original_price'] ?? 0);
        if ($originalPrice <= 0) {
            $originalPrice = (float) ($item['price'] ?? 0);
        }
        $price = (float) ($item['price'] ?? 0);
        $type = (string) ($item['type'] ?? '');

        $meliMoeda = 0.0;
        $vendedorMoeda = 0.0;

        if ($meliPct > 0 && $price > 0) {
            $meliMoeda = round($price * (ceil($meliPct) / 100), 2);
        }
        if ($sellerPct > 0 && $originalPrice > 0) {
            $vendedorMoeda = round($originalPrice * ($sellerPct / 100), 2);
        }

        if ($type === 'VOLUME') {
            $discountPct = (float) ($item['discount_percentage'] ?? 0);
            if ($discountPct > 0) {
                $vendedorMoeda = round($originalPrice * ($discountPct / 100), 2);
            }
        }

        $finalPrice = '';
        if (in_array($type, ['DOD', 'LIGHTNING'], true)) {
            $finalPrice = '';
        } elseif ($type === 'SELLER_CAMPAIGN') {
            $finalPrice = number_format($originalPrice - $vendedorMoeda, 2, ',', '');
        } elseif ($price > 0) {
            $finalPrice = $this->formatExportNumber($price);
        }

        return [
            $sku,
            $mlb,
            '',
            '',
            $this->formatExportNumber($originalPrice),
            $stock,
            (string) ($item['title'] ?? ''),
            $meliMoeda > 0 ? $this->formatExportNumber($meliMoeda) : '',
            $meliPct > 0 ? (string) (int) ceil($meliPct) : '',
            $vendedorMoeda > 0 ? $this->formatExportNumber($vendedorMoeda) : '',
            $sellerPct > 0 ? $this->formatExportNumber($sellerPct) : '',
            '',
            '',
            '',
            '',
            $finalPrice,
            $this->formatDate((string) ($item['start_date'] ?? '')),
            $this->formatDate((string) ($item['end_date'] ?? '')),
            (string) ($item['status'] ?? ''),
            (string) ($item['status_campaing'] ?? ''),
            $sold,
            $adsStatus,
            (string) ($item['offer_id'] ?? ''),
            (string) ($item['id_campaign'] ?? ''),
            $type,
        ];
    }

    /**
     * @param list<string> $ids
     * @return array<string, array<string, mixed>>
     */
    private function fetchItemsBatchMap(array $ids, string $accessToken): array
    {
        $map = [];
        if ($ids === []) {
            return $map;
        }

        foreach (array_chunk($ids, 20) as $chunk) {
            $path = '/items?ids=' . rawurlencode(implode(',', $chunk));
            $res = $this->client->get($path, $accessToken);
            if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
                continue;
            }
            $body = $res['body'] ?? [];
            if (!is_array($body)) {
                continue;
            }
            foreach ($body as $entry) {
                if (!is_array($entry) || (int) ($entry['code'] ?? 0) !== 200) {
                    continue;
                }
                $itemBody = $entry['body'] ?? null;
                if (!is_array($itemBody)) {
                    continue;
                }
                $id = trim((string) ($itemBody['id'] ?? ''));
                if ($id !== '') {
                    $map[$id] = $itemBody;
                }
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function applyPromotionItemPercentages(array &$item): void
    {
        $benefits = is_array($item['benefits'] ?? null) ? $item['benefits'] : [];

        if (!isset($item['meli_percentage']) || (float) $item['meli_percentage'] <= 0) {
            $item['meli_percentage'] = (float) (
                $item['meli_percent']
                ?? $benefits['meli_percent']
                ?? $benefits['meli_percentage']
                ?? 0
            );
        }
        if (!isset($item['seller_percentage']) || (float) $item['seller_percentage'] <= 0) {
            $item['seller_percentage'] = (float) (
                $item['seller_percent']
                ?? $benefits['seller_percent']
                ?? $benefits['seller_percentage']
                ?? 0
            );
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function readPercentage(array $item, string $side): float
    {
        if ($side === 'meli') {
            return (float) ($item['meli_percentage'] ?? $item['meli_percent'] ?? 0);
        }

        return (float) ($item['seller_percentage'] ?? $item['seller_percent'] ?? 0);
    }

    private function formatExportNumber(float|int|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $n = (float) $value;
        if (abs($n) < 0.00001) {
            return '0';
        }
        if (abs($n - round($n)) < 0.00001) {
            return (string) ((int) round($n));
        }

        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    /**
     * SKU rapido para export (sem chamadas extras a API de variacoes).
     *
     * @param array<string, mixed> $item
     */
    private function extractExportSkuFast(array $item): string
    {
        $fromAttr = $this->pickWctSkuFromAttributes($item['attributes'] ?? []);
        if ($fromAttr !== null) {
            return $fromAttr;
        }

        $scf = trim((string) ($item['seller_custom_field'] ?? ''));
        if ($this->isValidWctSku($scf)) {
            return $scf;
        }

        return '';
    }

    /**
     * SKU no padrao WCT (8 digitos numericos) — inclui busca em variacoes (uso pontual).
     *
     * @param array<string, mixed> $item
     */
    private function extractExportSku(array $item, string $accessToken): ?string
    {
        $sku = $this->pickWctSkuFromAttributes($item['attributes'] ?? []);
        if ($sku !== null) {
            return $sku;
        }

        $variations = $item['variations'] ?? [];
        if (!is_array($variations)) {
            return null;
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
            $variationItem = $this->fetchItem($variationId, $accessToken);
            if ($variationItem === null) {
                continue;
            }
            $sku = $this->pickWctSkuFromAttributes($variationItem['attributes'] ?? []);
            if ($sku !== null) {
                return $sku;
            }
        }

        return null;
    }

    /**
     * @param mixed $attributes
     */
    private function pickWctSkuFromAttributes(mixed $attributes): ?string
    {
        if (!is_array($attributes)) {
            return null;
        }

        foreach (['SELLER_SKU', 'MODEL'] as $attrId) {
            foreach ($attributes as $attr) {
                if (!is_array($attr)) {
                    continue;
                }
                if (strtoupper((string) ($attr['id'] ?? '')) !== $attrId) {
                    continue;
                }
                $value = trim((string) ($attr['value_name'] ?? ''));
                if ($this->isValidWctSku($value)) {
                    return $value;
                }
            }
        }

        return null;
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
