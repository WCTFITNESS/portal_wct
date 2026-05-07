<?php

declare(strict_types=1);

namespace App\Services;

use Shuchkin\SimpleXLS;
use Shuchkin\SimpleXLSX;
use Shuchkin\SimpleXLSXGen;

class RepasseService
{
    private const PREVIEW_MAX = 30;

    public function __construct(
        private OrderService $orderService
    ) {
    }

    public function getExportFilePath(string $fileName): ?string
    {
        $base = basename($fileName);
        if ($base === '' || str_contains($base, '..')) {
            return null;
        }

        $dir = $this->exportDirectory();
        $path = $dir . DIRECTORY_SEPARATOR . $base;
        if (!is_file($path)) {
            return null;
        }

        $realDir = realpath($dir);
        $realFile = realpath($path);
        if ($realDir === false || $realFile === false) {
            return null;
        }

        $prefix = $realDir . DIRECTORY_SEPARATOR;
        if (!str_starts_with(strtolower($realFile), strtolower($prefix))) {
            return null;
        }

        return $realFile;
    }

    /**
     * @return array{
     *     file_name: string,
     *     processed: int,
     *     found: int,
     *     not_found: int,
     *     errors: int,
     *     cache_hits: int,
     *     duration_seconds: float,
     *     trace_enabled: bool,
     *     preview: list<array{linha: int, operacao_relacionada: string, numero_pedido_ml: string, id_pagamento_payments: string, status_consulta: string}>
     * }
     */
    public function processUploadedFile(array $file, bool $collectApiTrace = false): array
    {
        if (($file['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('Nenhum arquivo enviado ou erro no upload.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new \InvalidArgumentException('Arquivo temporário inválido.');
        }

        $original = (string) ($file['name'] ?? 'repasse');
        $ext = strtolower(pathinfo($original, \PATHINFO_EXTENSION));
        if (!in_array($ext, ['xls', 'xlsx', 'csv'], true)) {
            throw new \InvalidArgumentException('Formato não suportado. Use XLS, XLSX ou CSV.');
        }

        $rows = $this->readRows($tmpName, $ext);
        if ($rows === []) {
            throw new \RuntimeException('Planilha vazia ou ilegível.');
        }

        $found = 0;
        $notFound = 0;
        $errors = 0;
        $processed = 0;
        $cacheHits = 0;
        $preview = [];
        $outRows = [];
        $headerDone = false;
        $lookupCache = [];
        $startedAt = microtime(true);

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            if ($index === 0) {
                $headerRow = $row;
                $headerRow[] = 'Número pedido ML';
                $headerRow[] = 'ID pagamento (payments.id)';
                $outRows[] = $headerRow;
                $headerDone = true;
                continue;
            }

            $lineNumber = $index + 1;
            $opRaw = $row[3] ?? '';
            $op = is_scalar($opRaw) ? trim((string) $opRaw) : '';

            if ($op === '') {
                $extended = $row;
                $extended[] = '';
                $extended[] = '';
                $outRows[] = $extended;
                if (count($preview) < self::PREVIEW_MAX) {
                    $preview[] = [
                        'linha' => $lineNumber,
                        'operacao_relacionada' => '',
                        'numero_pedido_ml' => '',
                        'id_pagamento_payments' => '',
                        'status_consulta' => 'Ignorada (coluna D vazia)',
                        'api_trace_b64' => '',
                    ];
                }
                continue;
            }

            $processed++;
            $numeroPedido = '';
            $idPagamento = '';
            $status = '';
            $apiTraceB64 = '';

            $cacheKey = strtolower($op);
            if (isset($lookupCache[$cacheKey])) {
                $cacheHits++;
                $cached = $lookupCache[$cacheKey];
                $numeroPedido = $cached['numero_pedido_ml'];
                $idPagamento = $cached['id_pagamento_payments'];
                $status = $cached['status_consulta'] . ' (cache)';
                if ($collectApiTrace) {
                    $apiTraceB64 = $cached['api_trace_b64'];
                }
                if ($cached['result_type'] === 'found') {
                    $found++;
                } elseif ($cached['result_type'] === 'not_found') {
                    $notFound++;
                } else {
                    $errors++;
                }
            } else {
                $resultType = 'not_found';
                try {
                    $resolved = $collectApiTrace
                        ? $this->orderService->findRepasseMatchByOperationWithTrace($op)
                        : ['match' => $this->orderService->findRepasseMatchByOperation($op), 'trace' => []];
                    $match = $resolved['match'];
                    $trace = $resolved['trace'];
                    if ($collectApiTrace) {
                        $traceJson = json_encode($trace, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
                        if ($traceJson !== false) {
                            $apiTraceB64 = base64_encode($traceJson);
                        }
                    }

                    if ($match !== null) {
                        $found++;
                        $resultType = 'found';
                        $numeroPedido = $match['order_id'];
                        $idPagamento = $match['payment_id'] ?? '';
                        $status = $idPagamento !== '' ? 'Encontrado (payments.id)' : 'Encontrado (id do pedido)';
                    } else {
                        $notFound++;
                        $resultType = 'not_found';
                        $status = 'Não encontrado na API';
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    $resultType = 'error';
                    $status = 'Erro na consulta';
                    if ($collectApiTrace) {
                        $errPayload = [['erro_execucao' => $e->getMessage()]];
                        $errJson = json_encode($errPayload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
                        if ($errJson !== false) {
                            $apiTraceB64 = base64_encode($errJson);
                        }
                    }
                }

                $lookupCache[$cacheKey] = [
                    'numero_pedido_ml' => $numeroPedido,
                    'id_pagamento_payments' => $idPagamento,
                    'status_consulta' => $status,
                    'api_trace_b64' => $apiTraceB64,
                    'result_type' => $resultType,
                ];
            }

            $extended = $row;
            $extended[] = $numeroPedido;
            $extended[] = $idPagamento;
            $outRows[] = $extended;

            if (count($preview) < self::PREVIEW_MAX) {
                $preview[] = [
                    'linha' => $lineNumber,
                    'operacao_relacionada' => $op,
                    'numero_pedido_ml' => $numeroPedido,
                    'id_pagamento_payments' => $idPagamento,
                    'status_consulta' => $status,
                    'api_trace_b64' => $apiTraceB64,
                ];
            }
        }

        if (!$headerDone) {
            throw new \RuntimeException('A planilha precisa ter pelo menos uma linha de cabeçalho.');
        }

        $exportName = 'repasse_' . bin2hex(random_bytes(8)) . '.xlsx';
        $fullPath = $this->exportDirectory() . DIRECTORY_SEPARATOR . $exportName;

        require_once __DIR__ . '/../Lib/SimpleXLSXGen.php';
        $xlsx = SimpleXLSXGen::fromArray($outRows, 'Repasse');
        if (!$xlsx->saveAs($fullPath)) {
            throw new \RuntimeException('Não foi possível gravar o arquivo de saída.');
        }

        return [
            'file_name' => $exportName,
            'processed' => $processed,
            'found' => $found,
            'not_found' => $notFound,
            'errors' => $errors,
            'cache_hits' => $cacheHits,
            'duration_seconds' => round(max(0, microtime(true) - $startedAt), 2),
            'trace_enabled' => $collectApiTrace,
            'preview' => $preview,
        ];
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function readRows(string $path, string $ext): array
    {
        if ($ext === 'csv') {
            return $this->readCsvRows($path);
        }

        if ($ext === 'xlsx') {
            require_once __DIR__ . '/../Lib/SimpleXLSX.php';
            $xlsx = SimpleXLSX::parse($path);
            if (!$xlsx) {
                throw new \RuntimeException('Falha ao ler XLSX: ' . SimpleXLSX::parseError());
            }

            return array_values($xlsx->rows());
        }

        require_once __DIR__ . '/../Lib/SimpleXLS.php';
        $xls = SimpleXLS::parse($path);
        if (!$xls) {
            throw new \RuntimeException('Falha ao ler XLS: ' . SimpleXLS::parseError());
        }

        return array_values($xls->rows());
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function readCsvRows(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('Não foi possível ler o CSV.');
        }

        $content = str_replace("\r\n", "\n", $content);
        $content = str_replace("\r", "\n", $content);
        $lines = explode("\n", $content);
        $rows = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $rows[] = str_getcsv($line);
        }

        return $rows;
    }

    private function exportDirectory(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'portal_wct-repasse';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Não foi possível criar pasta temporária para exportação.');
        }

        return $dir;
    }
}
