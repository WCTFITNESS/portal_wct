<?php

declare(strict_types=1);

namespace App\Services;

use JsonException;
use Shuchkin\SimpleXLS;
use Shuchkin\SimpleXLSX;
use Shuchkin\SimpleXLSXGen;

class RepasseMpService
{
    private const PREVIEW_MAX = 30;

    /** Quantidade de operacoes unicas por requisicao (evita timeout do proxy em Render etc.). */
    private const JOB_CHUNK_UNIQUE_OPS = 18;

    public function __construct(private MercadoPagoPaymentService $paymentService)
    {
    }

    /**
     * Resolve caminho seguro para download. Se $jobId for informado, exige que o arquivo pertenca ao job.
     */
    public function getExportFilePathForDownload(string $fileName, ?string $jobId = null): ?string
    {
        if ($jobId !== null && $jobId !== '') {
            if (!$this->isValidJobId($jobId)) {
                return null;
            }
            $meta = $this->loadJobMeta($jobId);
            if ($meta === null || ($meta['status'] ?? '') !== 'complete') {
                return null;
            }
            $expected = basename((string) ($meta['result_file'] ?? ''));
            if ($expected === '' || $expected !== basename($fileName)) {
                return null;
            }
        }

        return $this->getExportFilePath($fileName);
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

    /**
     * Copia upload e prepara job para processamento em chunks via HTTP.
     *
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public function createJobFromUpload(array $file): string
    {
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

        $jobId = $this->newJobId();
        $jobDir = $this->jobDirectory($jobId);
        if (!is_dir($jobDir) && !mkdir($jobDir, 0755, true) && !is_dir($jobDir)) {
            throw new \RuntimeException('Nao foi possivel criar pasta do job.');
        }

        $destName = 'source.' . $ext;
        $destPath = $jobDir . DIRECTORY_SEPARATOR . $destName;
        if (!copy($tmpName, $destPath)) {
            throw new \RuntimeException('Nao foi possivel gravar copia do arquivo.');
        }

        $rows = $this->readRows($destPath, $ext);
        if ($rows === []) {
            throw new \RuntimeException('Planilha vazia ou ilegível.');
        }

        $uniqueOps = [];
        $linesByOp = [];
        $seen = [];
        $header = null;
        $operationColumnIndex = 3;
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($index === 0) {
                $header = $row;
                $operationColumnIndex = $this->detectOperationColumnIndex($header);
                continue;
            }
            $raw = $this->getCellValueAt($row, $operationColumnIndex);
            $op = $this->normalizeOperationValue($raw);
            if ($op === '') {
                continue;
            }
            if (!isset($seen[$op])) {
                $seen[$op] = true;
                $uniqueOps[] = $op;
            }
            $linesByOp[$op] = ($linesByOp[$op] ?? 0) + 1;
        }

        if ($header === null) {
            throw new \RuntimeException('A planilha precisa ter cabeçalho na linha 1.');
        }

        $meta = [
            'status' => 'matching',
            'created_at' => time(),
            'ext' => $ext,
            'source_name' => $destName,
            'operation_column_index' => $operationColumnIndex,
            'unique_ops' => $uniqueOps,
            'lines_by_op' => $linesByOp,
            'cursor' => 0,
            'chunk_size' => self::JOB_CHUNK_UNIQUE_OPS,
            'matches' => [],
            'api_calls_total' => 0,
            'error_message' => null,
            'result_file' => null,
        ];

        $this->saveJobMeta($jobId, $meta);

        return $jobId;
    }

    /**
     * Processa um lote de operacoes unicas (chamada curta para o proxy).
     *
     * @return array{ok: bool, phase?: string, progress?: float, cursor?: int, total?: int, error?: string}
     */
    public function processJobChunk(string $jobId): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        if (!$this->isValidJobId($jobId)) {
            return ['ok' => false, 'error' => 'Job invalido.'];
        }

        $meta = $this->loadJobMeta($jobId);
        if ($meta === null) {
            return ['ok' => false, 'error' => 'Job nao encontrado.'];
        }

        if (($meta['status'] ?? '') === 'error') {
            return ['ok' => false, 'error' => (string) ($meta['error_message'] ?? 'Erro no job.')];
        }

        if (($meta['status'] ?? '') === 'complete') {
            return [
                'ok' => true,
                'phase' => 'complete',
                'progress' => 100.0,
                'result_file' => (string) ($meta['result_file'] ?? ''),
            ];
        }

        if (($meta['status'] ?? '') === 'matching_done') {
            return ['ok' => true, 'phase' => 'matching_done', 'progress' => 100.0];
        }

        $uniqueOps = $meta['unique_ops'] ?? [];
        if (!is_array($uniqueOps)) {
            $uniqueOps = [];
        }
        $total = count($uniqueOps);
        $cursor = (int) ($meta['cursor'] ?? 0);

        if ($cursor >= $total) {
            $meta['status'] = 'matching_done';
            $this->saveJobMeta($jobId, $meta);

            return ['ok' => true, 'phase' => 'matching_done', 'progress' => 100.0, 'cursor' => $total, 'total' => $total];
        }

        $chunkSize = max(1, (int) ($meta['chunk_size'] ?? self::JOB_CHUNK_UNIQUE_OPS));
        $slice = array_slice($uniqueOps, $cursor, $chunkSize);
        $keys = array_map('strval', $slice);

        try {
            $batch = $this->paymentService->findOrderIdsByOperationValues($keys);
        } catch (\Throwable $e) {
            $meta['status'] = 'error';
            $meta['error_message'] = 'Falha na API: ' . $e->getMessage();
            $this->saveJobMeta($jobId, $meta);

            return ['ok' => false, 'error' => $meta['error_message']];
        }

        $matches = $meta['matches'] ?? [];
        if (!is_array($matches)) {
            $matches = [];
        }
        $batchMatches = $batch['matches'] ?? [];
        if (is_array($batchMatches)) {
            foreach ($batchMatches as $k => $v) {
                $sk = (string) $k;
                if (is_array($v)) {
                    $matches[$sk] = $v;
                }
            }
        }

        $meta['matches'] = $matches;
        $meta['api_calls_total'] = (int) ($meta['api_calls_total'] ?? 0) + (int) ($batch['api_calls'] ?? 0);
        $meta['cursor'] = $cursor + count($slice);

        $newCursor = (int) $meta['cursor'];
        $progress = $total > 0 ? round(min(100.0, ($newCursor / $total) * 100.0), 1) : 100.0;

        if ($newCursor >= $total) {
            $meta['status'] = 'matching_done';
        }

        $this->saveJobMeta($jobId, $meta);

        return [
            'ok' => true,
            'phase' => ($meta['status'] ?? '') === 'matching_done' ? 'matching_done' : 'matching',
            'progress' => $progress,
            'cursor' => $newCursor,
            'total' => $total,
            'api_calls_total' => (int) ($meta['api_calls_total'] ?? 0),
        ];
    }

    /**
     * Gera o XLSX final apos todas as consultas (requisicao unica; leitura local da planilha).
     *
     * @return array{ok: bool, result?: array, error?: string}
     */
    public function finalizeJob(string $jobId): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(false);
        }

        if (!$this->isValidJobId($jobId)) {
            return ['ok' => false, 'error' => 'Job invalido.'];
        }

        $meta = $this->loadJobMeta($jobId);
        if ($meta === null) {
            return ['ok' => false, 'error' => 'Job nao encontrado.'];
        }

        if (($meta['status'] ?? '') === 'error') {
            return ['ok' => false, 'error' => (string) ($meta['error_message'] ?? 'Erro no job.')];
        }

        if (($meta['status'] ?? '') === 'complete') {
            $snapshot = $meta['result_snapshot'] ?? null;
            if (is_string($snapshot) && $snapshot !== '') {
                try {
                    $decoded = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        return ['ok' => true, 'result' => $decoded];
                    }
                } catch (JsonException) {
                    // continua
                }
            }
            $fileName = (string) ($meta['result_file'] ?? '');
            if ($fileName !== '' && $this->getExportFilePath($fileName)) {
                $linesByOp = $meta['lines_by_op'] ?? [];

                return [
                    'ok' => true,
                    'result' => [
                        'file_name' => $fileName,
                        'processed' => (int) ($meta['last_processed'] ?? 0),
                        'unique_operations' => is_array($linesByOp) ? count($linesByOp) : 0,
                        'reused' => true,
                    ],
                ];
            }
        }

        if (($meta['status'] ?? '') !== 'matching_done') {
            return ['ok' => false, 'error' => 'Processamento da API ainda nao terminou.'];
        }

        $ext = (string) ($meta['ext'] ?? 'xlsx');
        $jobDir = $this->jobDirectory($jobId);
        $sourcePath = $jobDir . DIRECTORY_SEPARATOR . (string) ($meta['source_name'] ?? ('source.' . $ext));
        if (!is_file($sourcePath)) {
            return ['ok' => false, 'error' => 'Arquivo fonte do job nao encontrado.'];
        }

        $rows = $this->readRows($sourcePath, $ext);
        $valueByLine = [];
        $linesByValue = [];
        $header = null;
        $operationColumnIndex = (int) ($meta['operation_column_index'] ?? 3);

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($index === 0) {
                $header = $row;
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
            return ['ok' => false, 'error' => 'Cabecalho invalido.'];
        }

        $matchByValue = [];
        $stored = $meta['matches'] ?? [];
        if (is_array($stored)) {
            foreach ($stored as $k => $v) {
                if (is_array($v)) {
                    $matchByValue[(string) $k] = $v;
                }
            }
        }

        $found = 0;
        $notFound = 0;
        $errors = 0;

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
        $headerOut = $header;
        $headerOut[] = 'order';
        $outRows[] = $headerOut;
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
            return ['ok' => false, 'error' => 'Nao foi possivel gravar o arquivo de saida.'];
        }

        $linesByOp = $meta['lines_by_op'] ?? [];
        $uniqueCount = is_array($linesByOp) ? count($linesByOp) : 0;

        $result = [
            'file_name' => $exportName,
            'processed' => $processed,
            'unique_operations' => $uniqueCount,
            'operation_column_index' => $operationColumnIndex,
            'operation_column_name' => $this->getHeaderNameAt($header, $operationColumnIndex),
            'found' => $found,
            'not_found' => $notFound,
            'errors' => $errors,
            'api_calls' => (int) ($meta['api_calls_total'] ?? 0),
            'preview' => $preview,
        ];

        $meta['status'] = 'complete';
        $meta['result_file'] = $exportName;
        $meta['last_processed'] = $processed;
        $meta['result_snapshot'] = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->saveJobMeta($jobId, $meta);

        return ['ok' => true, 'result' => $result];
    }

    private function newJobId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function isValidJobId(string $jobId): bool
    {
        return strlen($jobId) === 32 && ctype_xdigit($jobId);
    }

    private function jobsDirectory(): string
    {
        $root = dirname(__DIR__, 2);
        $dir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'repasse_mp_jobs';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'portal_wct-repasse-mp-jobs';
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new \RuntimeException('Nao foi possivel criar pasta de jobs.');
            }
        }

        return $dir;
    }

    private function jobDirectory(string $jobId): string
    {
        return $this->jobsDirectory() . DIRECTORY_SEPARATOR . $jobId;
    }

    private function metaPath(string $jobId): string
    {
        return $this->jobDirectory($jobId) . DIRECTORY_SEPARATOR . 'meta.json';
    }

    /** @return array<string, mixed>|null */
    private function loadJobMeta(string $jobId): ?array
    {
        $path = $this->metaPath($jobId);
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $meta */
    private function saveJobMeta(string $jobId, array $meta): void
    {
        $path = $this->metaPath($jobId);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Nao foi possivel gravar metadados do job.');
        }
        $fp = fopen($path, 'c+');
        if ($fp === false) {
            throw new \RuntimeException('Nao foi possivel abrir meta do job.');
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                throw new \RuntimeException('Nao foi possivel bloquear meta do job.');
            }
            ftruncate($fp, 0);
            rewind($fp);
            $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            fwrite($fp, $json);
            fflush($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
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

        if (preg_match('/^\d+\.0+$/', $value) === 1) {
            $value = preg_replace('/\.0+$/', '', $value) ?? $value;
        }

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
