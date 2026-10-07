<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;
use RuntimeException;

/**
 * Download em lote (ZIP) das notas emitidas pelo Faturador do Mercado Livre: vendas, devoluções,
 * notas do Full (remessa ao armazém, retorno simbólico, retirada), CT-e e cartas de correção.
 * Endpoint: /users/{id}/invoices/sites/MLB/batch_request/period/stream
 */
class MlInvoiceBatchService
{
    private const MAX_DIAS = 92;

    /** @var array<string, array{label: string, param: string, value: string}> */
    public const TIPOS = [
        'full_inbound' => ['label' => 'Full — remessa para o armazém do ML', 'param' => 'full', 'value' => 'inbound'],
        'full_retorno' => ['label' => 'Full — retorno simbólico (vendas, perdas)', 'param' => 'full', 'value' => 'symbolic_inbound_return'],
        'full_retirada' => ['label' => 'Full — retirada de estoque', 'param' => 'full', 'value' => 'removal'],
        'venda' => ['label' => 'Vendas', 'param' => 'sale', 'value' => 'all'],
        'devolucao' => ['label' => 'Devoluções', 'param' => 'return', 'value' => 'all'],
        'cte' => ['label' => 'CT-e', 'param' => 'others', 'value' => 'cte'],
        'cce' => ['label' => 'Cartas de correção', 'param' => 'others', 'value' => 'correction_letter'],
    ];

    public function __construct(
        private TokenService $tokenService,
        private SettingsRepository $settingsRepository
    ) {
    }

    /**
     * Envia o ZIP do ML direto para o navegador. Só emite headers quando o ML começa a mandar o arquivo;
     * em caso de erro lança exceção sem ter escrito nada.
     *
     * @param list<string> $tipos chaves de TIPOS
     */
    public function streamZip(string $de, string $ate, array $tipos, bool $incluirPdf): void
    {
        $start = $this->parseDate($de, 'inicial');
        $end = $this->parseDate($ate, 'final');
        if ($end < $start) {
            throw new RuntimeException('A data final é anterior à inicial.');
        }
        if ($start->diff($end)->days > self::MAX_DIAS) {
            throw new RuntimeException('Escolha um período de no máximo ' . self::MAX_DIAS . ' dias.');
        }

        $tipos = array_values(array_intersect(array_keys(self::TIPOS), $tipos));
        if ($tipos === []) {
            throw new RuntimeException('Marque pelo menos um tipo de nota.');
        }

        $sellerId = trim((string) (($this->settingsRepository->getApiConfig() ?? [])['seller_id'] ?? ''));
        if ($sellerId === '' || !ctype_digit($sellerId)) {
            throw new RuntimeException('Seller ID inválido. Configure em Mercado Livre → Configuração API.');
        }
        $token = $this->tokenService->getValidAccessToken();

        $filters = [];
        foreach ($tipos as $tipo) {
            $filters[self::TIPOS[$tipo]['param']][] = self::TIPOS[$tipo]['value'];
        }
        $base = [
            'start' => $start->format('Ymd'),
            'end' => $end->format('Ymd'),
        ];
        $tail = [
            'file_types' => $incluirPdf ? 'xml,pdf' : 'xml',
            'simple_folder' => 'false',
        ];

        $selected = array_map(static fn (array $values): string => implode(',', array_unique($values)), $filters);
        $attempts = [$base + $selected + $tail];
        $missing = array_diff(['sale', 'return', 'full', 'others'], array_keys($selected));
        if ($missing !== []) {
            // A doc do ML diz que os 4 filtros são obrigatórios; se recusar sem eles, baixa tudo (separado em pastas por tipo).
            $attempts[] = $base + $selected + array_fill_keys($missing, 'all') + $tail;
        }

        $fileName = 'notas-ml-' . $start->format('Ymd') . '-' . $end->format('Ymd') . '.zip';
        $lastError = '';
        foreach ($attempts as $query) {
            $url = 'https://api.mercadolibre.com/users/' . $sellerId . '/invoices/sites/MLB/batch_request/period/stream?'
                . http_build_query($query);
            $result = $this->tryStream($url, $token, $fileName);
            if ($result === true) {
                return;
            }
            $lastError = $this->describeError($result['status'], $result['body']);
            if ($result['status'] !== 400) {
                break;
            }
        }

        throw new RuntimeException($lastError);
    }

    /** @return true|array{status: int, body: string} */
    private function tryStream(string $url, string $token, string $fileName): bool|array
    {
        $status = 0;
        $contentType = '';
        $streaming = false;
        $errorBody = '';

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Falha ao iniciar a conexão com o Mercado Livre.');
        }
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 1800,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$status, &$contentType): int {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $status = (int) $m[1];
                    $contentType = '';
                } elseif (stripos($line, 'content-type:') === 0) {
                    $contentType = strtolower(trim(substr($line, 13)));
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$status, &$contentType, &$streaming, &$errorBody, $fileName): int {
                if (!$streaming && $status === 200 && !str_contains($contentType, 'json') && !str_contains($contentType, 'html')) {
                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="' . $fileName . '"');
                    header('Cache-Control: no-store');
                    $streaming = true;
                }
                if ($streaming) {
                    echo $chunk;
                    flush();
                } elseif (strlen($errorBody) < 8000) {
                    $errorBody .= $chunk;
                }

                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($streaming) {
            return true;
        }

        return ['status' => $status, 'body' => $errorBody !== '' ? $errorBody : $curlError];
    }

    private function describeError(int $status, string $body): string
    {
        $json = json_decode($body, true);
        $detail = is_array($json)
            ? trim((string) ($json['message'] ?? $json['error'] ?? '') . ' ' . (is_string($json['cause'] ?? null) ? $json['cause'] : ''))
            : trim(mb_substr(strip_tags($body), 0, 300));

        return match (true) {
            $status === 0 => 'Sem resposta do Mercado Livre: ' . ($detail !== '' ? $detail : 'tempo esgotado.'),
            $status === 401 => 'O token do Mercado Livre expirou ou foi revogado. Reconecte em Mercado Livre → Configuração API.',
            $status === 403 => 'O Mercado Livre negou acesso às notas fiscais (403)' . ($detail !== '' ? ': ' . $detail : '.')
                . ' O aplicativo precisa de permissão de faturamento/notas fiscais.',
            $status === 404 => 'Nenhuma nota encontrada nesse período para os tipos escolhidos.',
            $status === 200 => 'O Mercado Livre não devolveu arquivo' . ($detail !== '' ? ': ' . $detail : '.'),
            default => 'Mercado Livre respondeu HTTP ' . $status . ($detail !== '' ? ': ' . $detail : '.'),
        };
    }

    private function parseDate(string $value, string $label): \DateTimeImmutable
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        if ($dt === false) {
            throw new RuntimeException('Informe a data ' . $label . '.');
        }

        return $dt;
    }
}
