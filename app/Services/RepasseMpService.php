<?php

declare(strict_types=1);

namespace App\Services;

use Shuchkin\SimpleXLS;
use Shuchkin\SimpleXLSX;
use Shuchkin\SimpleXLSXGen;

class RepasseMpService
{
    private const PREVIEW_MAX = 30;

    public function __construct(private MercadoPagoPaymentService $paymentService)
    {
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

        if (!str_starts_with(strtolower($realFile), strtolower($realDir . DIRECTORY_SEPARATOR))) {
            return null;
        }

        return $realFile;
    }

    public function processUploadedFile(array $file): array
    {
        // Processamento pode envolver muitas consultas; evita fatal por tempo padrão do PHP.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(false);
        }

        if (($file['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('Nenhum arquivo enviado ou erro no upload.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new \InvalidArgumentException('Arquivo temporário inválido.');
        }

        $original = (string) ($file['name'] ?? 'repasse-mp');
        $ext = strtolower(pathinfo($original, \PATHINFO_EXTENSION));
        if (!in_array($ext, ['xls', 'xlsx', 'csv'], true)) {
            throw new \InvalidArgumentException('Formato não suportado. Use XLS, XLSX ou CSV.');
        }

        $rows = $this->readRows($tmpName, $ext);
        if ($rows === []) {
            throw new \RuntimeException('Planilha vazia ou ilegível.');
        }

        $valueByLine = [];
        $linesByValue = [];
        $header = null;
        $operationColumnIndex = 3; // fallback: coluna D
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($index === 0) {
                $header = $row;
                $operationColumnIndex = $this->detectOperationColumnIndex($header);
                continue;
            }
            $lineNumber = $index + 1;
            $raw = $this->getCellValueAt($row, $operationColumnIndex);
            $op = $this->normalizeOperationValue($raw);
            $valueByLine[$lineNumber] = $op;
            if ($op === '') {
                continue;
            }
            if (!isset($linesByValue[$op])) {
                $linesByValue[$op] = [];
            }
            $linesByValue[$op][] = $lineNumber;
        }

        if ($header === null) {
            throw new \RuntimeException('A planilha precisa ter cabeçalho na linha 1.');
        }

        $matchByValue = [];
        $found = 0;
        $notFound = 0;
        $errors = 0;
        $apiCalls = 0;

        try {
            $batch = $this->paymentService->findOrderIdsByOperationValues(array_map('strval', array_keys($linesByValue)));
            $apiCalls = (int) ($batch['api_calls'] ?? 0);
            $matchByValue = $batch['matches'] ?? [];
        } catch (\Throwable $e) {
            throw new \RuntimeException('Falha no processamento em lote: ' . $e->getMessage(), 0, $e);
        }

        foreach (array_keys($linesByValue) as $operationValue) {
            $this->assertClientConnected();
            $key = (string) $operationValue;
            if (!isset($matchByValue[$key])) {
                $matchByValue[$key] = ['order_id' => '', 'payment_id' => '', 'status' => 'Nao encontrado'];
            }
            if (($matchByValue[$key]['order_id'] ?? '') !== '') {
                $found++;
            } elseif (str_starts_with((string) ($matchByValue[$key]['status'] ?? ''), 'Erro:')) {
                $errors++;
            } else {
                $notFound++;
            }
        }

        $outRows = [];
        $header[] = 'order';
        $outRows[] = $header;
        $preview = [];
        $processed = 0;

        foreach ($rows as $index => $row) {
            $this->assertClientConnected();
            if (!is_array($row) || $index === 0) {
                continue;
            }
            $lineNumber = $index + 1;
            $op = $valueByLine[$lineNumber] ?? '';
            $match = $op !== '' ? ($matchByValue[(string) $op] ?? ['order_id' => '', 'payment_id' => '', 'status' => 'Nao encontrado']) : ['order_id' => '', 'payment_id' => '', 'status' => 'Ignorada (coluna D vazia)'];
            $row[] = (string) ($match['order_id'] ?? '');
            $outRows[] = $row;
            $processed++;

            if (count($preview) < self::PREVIEW_MAX) {
                $preview[] = [
                    'linha' => $lineNumber,
                    'coluna_d' => $op,
                    'order' => (string) ($match['order_id'] ?? ''),
                    'payment_id' => (string) ($match['payment_id'] ?? ''),
                    'status_consulta' => (string) ($match['status'] ?? ''),
                    'duplicadas' => $op !== '' ? count($linesByValue[$op] ?? []) : 0,
                ];
            }
        }

        $exportName = 'repasse_mp_' . bin2hex(random_bytes(8)) . '.xlsx';
        $fullPath = $this->exportDirectory() . DIRECTORY_SEPARATOR . $exportName;
        require_once __DIR__ . '/../Lib/SimpleXLSXGen.php';
        $xlsx = SimpleXLSXGen::fromArray($outRows, 'Repasse MP');
        if (!$xlsx->saveAs($fullPath)) {
            throw new \RuntimeException('Nao foi possivel gravar o arquivo de saida.');
        }

        return [
            'file_name' => $exportName,
            'processed' => $processed,
            'unique_operations' => count($linesByValue),
            'operation_column_index' => $operationColumnIndex,
            'operation_column_name' => $this->getHeaderNameAt($header, $operationColumnIndex),
            'found' => $found,
            'not_found' => $notFound,
            'errors' => $errors,
            'api_calls' => $apiCalls,
            'preview' => $preview,
        ];
    }

    private function readRows(string $path, string $ext): array
    {
        if ($ext === 'csv') {
            return $this->readCsvRows($path);
        }

        if ($ext === 'xlsx') {
            require_once __DIR__ . '/../Lib/SimpleXLSX.php';
            $xlsx = SimpleXLSX::parse($path);
            if ($xlsx) {
                return array_values($xlsx->rows());
            }

            // Fallback: alguns arquivos chegam com extensao .xlsx, mas conteudo real em outro formato.
            require_once __DIR__ . '/../Lib/SimpleXLS.php';
            $xls = SimpleXLS::parse($path);
            if ($xls) {
                return array_values($xls->rows());
            }

            try {
                $csvRows = $this->readCsvRows($path);
                if ($csvRows !== []) {
                    return $csvRows;
                }
            } catch (\Throwable) {
                // ignora para retornar erro principal mais claro abaixo
            }

            throw new \RuntimeException(
                'Falha ao ler XLSX: ' . SimpleXLSX::parseError() . '. ' .
                'Verifique se o arquivo eh um XLSX valido (nao renomeado de outro formato).'
            );
        }

        require_once __DIR__ . '/../Lib/SimpleXLS.php';
        $xls = SimpleXLS::parse($path);
        if (!$xls) {
            throw new \RuntimeException('Falha ao ler XLS: ' . SimpleXLS::parseError());
        }

        return array_values($xls->rows());
    }

    private function readCsvRows(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('Nao foi possivel ler o CSV.');
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
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'portal_wct-repasse-mp';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Nao foi possivel criar pasta temporaria para exportacao.');
        }

        return $dir;
    }

    private function assertClientConnected(): void
    {
        if (function_exists('connection_aborted') && connection_aborted() !== 0) {
            throw new \RuntimeException('Processamento cancelado: conexao do cliente encerrada (F5/saida da pagina).');
        }
    }

    /**
     * Detecta a coluna de operacao pelo cabecalho "Operacao relacionada".
     * Se nao encontrar, usa fallback da coluna D (indice 3).
     */
    private function detectOperationColumnIndex(array $headerRow): int
    {
        foreach ($headerRow as $idx => $cell) {
            $label = $this->normalizeHeader((string) (is_scalar($cell) ? $cell : ''));
            if ($label === 'operacao relacionada') {
                return is_int($idx) ? $idx : (int) $idx;
            }
        }

        return 3;
    }

    private function getCellValueAt(array $row, int $index): mixed
    {
        if (array_key_exists($index, $row)) {
            return $row[$index];
        }

        $idxAsString = (string) $index;
        if (array_key_exists($idxAsString, $row)) {
            return $row[$idxAsString];
        }

        return '';
    }

    private function normalizeHeader(string $value): string
    {
        $v = trim(mb_strtolower($value));
        $v = str_replace(
            ['á', 'à', 'â', 'ã', 'é', 'ê', 'í', 'ó', 'ô', 'õ', 'ú', 'ç'],
            ['a', 'a', 'a', 'a', 'e', 'e', 'i', 'o', 'o', 'o', 'u', 'c'],
            $v
        );
        $v = preg_replace('/\s+/', ' ', $v) ?? $v;

        return $v;
    }

    private function normalizeOperationValue(mixed $raw): string
    {
        if ($raw === null) {
            return '';
        }

        if (is_int($raw)) {
            return (string) $raw;
        }

        if (is_float($raw)) {
            if (!is_finite($raw)) {
                return '';
            }
            // Evita notacao cientifica para IDs numericos.
            return sprintf('%.0f', $raw);
        }

        if (!is_scalar($raw)) {
            return '';
        }

        $value = trim((string) $raw);
        if ($value === '') {
            return '';
        }

        $value = str_replace(["\xc2\xa0", ' '], '', $value);

        // Ex.: 148328824516.0 -> 148328824516
        if (preg_match('/^\d+\.0+$/', $value) === 1) {
            $value = preg_replace('/\.0+$/', '', $value) ?? $value;
        }

        // Ex.: 1.48328824516E+11
        if (preg_match('/^\d+(?:\.\d+)?e[+\-]?\d+$/i', $value) === 1) {
            $floatValue = (float) $value;
            if (is_finite($floatValue)) {
                $value = sprintf('%.0f', $floatValue);
            }
        }

        return trim($value);
    }

    private function getHeaderNameAt(array $header, int $index): string
    {
        $value = $this->getCellValueAt($header, $index);

        return is_scalar($value) ? (string) $value : '';
    }
}

