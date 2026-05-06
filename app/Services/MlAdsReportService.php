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

    public function generateReport(int $maxItems = 200): array
    {
        $maxItems = max(1, min(1000, $maxItems));

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
            'item_id',
            'titulo',
            'status',
            'condicao',
            'preco',
            'preco_original',
            'moeda',
            'estoque_disponivel',
            'vendidos',
            'tipo_anuncio',
            'categoria_id',
            'catalogo',
            'frete_gratis',
            'link',
            'data_criacao',
            'ultima_atualizacao',
        ]];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $shipping = is_array($item['shipping'] ?? null) ? $item['shipping'] : [];
            $rows[] = [
                (string) ($item['id'] ?? ''),
                (string) ($item['title'] ?? ''),
                (string) ($item['status'] ?? ''),
                (string) ($item['condition'] ?? ''),
                (string) ($item['price'] ?? ''),
                (string) ($item['original_price'] ?? ''),
                (string) ($item['currency_id'] ?? ''),
                (string) ($item['available_quantity'] ?? ''),
                (string) ($item['sold_quantity'] ?? ''),
                (string) ($item['listing_type_id'] ?? ''),
                (string) ($item['category_id'] ?? ''),
                !empty($item['catalog_listing']) ? 'sim' : 'nao',
                !empty($shipping['free_shipping']) ? 'sim' : 'nao',
                (string) ($item['permalink'] ?? ''),
                (string) ($item['date_created'] ?? ''),
                (string) ($item['last_updated'] ?? ''),
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
}

