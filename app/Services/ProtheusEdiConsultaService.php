<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Shuchkin\SimpleXLSXGen;

class ProtheusEdiConsultaService
{
    private const COLOR_HEADER = 'F1F5F9';
    private const COLOR_ROW_ERRO = 'FEE2E2';
    private const COLOR_ROW_REJEITADO = 'FFEDD5';
    private const COLOR_ROW_OK = 'DCFCE7';

    private ?bool $hasGu3Table = null;
    private ?bool $hasGxhTable = null;

    public function __construct(
        private ProtheusConnectionService $connectionService
    ) {
    }

    /** @return array<string, string> */
    public static function exportColumns(): array
    {
        return [
            'GXG_FILIAL' => 'Filial',
            'DT_IMPORT' => 'Dt importacao',
            'DT_EMISSAO' => 'Dt emissao',
            'COD_TRANSPORTADORA' => 'Cod. transp.',
            'NOME_TRANSPORTADORA' => 'Transportadora',
            'CNPJ_TRANSPORTADORA' => 'CNPJ transp.',
            'ARQUIVO_EDI' => 'Arquivo EDI',
            'ESPECIE' => 'Especie',
            'SERIE' => 'Serie',
            'NR_DOCUMENTO' => 'Nr documento',
            'SIT_EDI' => 'Sit. EDI',
            'ACAO' => 'Acao',
            'CHAVE_CTE' => 'Chave CT-e',
            'CHAVE_NFE' => 'Chave NF-e',
            'DOC_ORIGEM' => 'Doc origem',
            'SERIE_ORIGEM' => 'Serie origem',
            'SEQ_IMPORT' => 'Seq. import.',
            'MSG_EDI' => 'Mensagem EDI',
        ];
    }

    /** @return list<string> */
    public static function situacaoFilterOptions(): array
    {
        return ['', '1', '2', '3', '4', '5'];
    }

    public static function formatEdiSitLabel(mixed $value): string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return '';
        }

        return match ($raw) {
            '1' => '1-Importado',
            '2' => '2-Importado com erro',
            '3' => '3-Rejeitado',
            '4' => '4-Processado',
            '5' => '5-Erro impeditivo',
            default => $raw,
        };
    }

    public static function formatAcaoLabel(mixed $value): string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return '';
        }

        return match ($raw) {
            '1' => '1-Incluir',
            '2' => '2-Eliminar',
            default => $raw,
        };
    }

    /**
     * @return list<array{cod: string, nome: string}>
     */
    public function listTransportadoras(string $filial, string $dataDe, string $dataAte): array
    {
        $this->assertEdiTables();
        $filial = $this->normalizeFilial($filial);
        $dataDe = $this->normalizeProtheusDate($dataDe, '20260101');
        $dataAte = $this->normalizeProtheusDate($dataAte, date('Ymd'));

        $pdo = $this->connectionService->connect();
        $hasGu3 = $this->hasGu3Table($pdo);

        $nomeExpr = $hasGu3
            ? 'COALESCE(NULLIF(RTRIM(GU3.GU3_NMEMIT), \'\'), RTRIM(GXG.GXG_EMISDF))'
            : 'RTRIM(GXG.GXG_EMISDF)';

        $joinGu3 = $hasGu3
            ? 'LEFT JOIN GU3010 GU3
                ON GU3.GU3_FILIAL = GXG.GXG_FILIAL
                AND RTRIM(GU3.GU3_CDEMIT) = RTRIM(GXG.GXG_EMISDF)
                AND GU3.D_E_L_E_T_ = \' \''
            : '';

        $sql = <<<SQL
SELECT DISTINCT
    RTRIM(GXG.GXG_EMISDF) AS cod,
    {$nomeExpr} AS nome
FROM GXG010 GXG
{$joinGu3}
WHERE GXG.D_E_L_E_T_ = ' '
    AND GXG.GXG_FILIAL = :filial
    AND GXG.GXG_DTIMP BETWEEN :data_de AND :data_ate
    AND RTRIM(GXG.GXG_EMISDF) <> ''
ORDER BY nome
SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':filial' => $filial,
            ':data_de' => $dataDe,
            ':data_ate' => $dataAte,
        ]);
        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cod = trim((string) ($row['cod'] ?? ''));
            if ($cod === '') {
                continue;
            }
            $out[] = [
                'cod' => $cod,
                'nome' => trim((string) ($row['nome'] ?? $cod)),
            ];
        }

        return $out;
    }

    /**
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   total: int,
     *   page: int,
     *   per_page: int,
     *   total_pages: int
     * }
     */
    public function listEdi(
        string $filial,
        string $dataDe,
        string $dataAte,
        string $transportadora = '',
        string $situacaoEdi = '',
        string $arquivo = '',
        int $page = 1,
        int $perPage = 50
    ): array {
        $filial = $this->normalizeFilial($filial);
        $dataDe = $this->normalizeProtheusDate($dataDe, '20260101');
        $dataAte = $this->normalizeProtheusDate($dataAte, date('Ymd'));
        $page = max(1, $page);
        $perPage = max(10, min(200, $perPage));
        $offset = ($page - 1) * $perPage;

        $pdo = $this->connectionService->connect();
        $params = $this->buildParams($filial, $dataDe, $dataAte, $transportadora, $situacaoEdi, $arquivo);

        $total = $this->fetchTotal($pdo, $params);
        $rows = $this->fetchPage($pdo, $params, $offset, $perPage);
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAllEdi(
        string $filial,
        string $dataDe,
        string $dataAte,
        string $transportadora = '',
        string $situacaoEdi = '',
        string $arquivo = ''
    ): array {
        $filial = $this->normalizeFilial($filial);
        $dataDe = $this->normalizeProtheusDate($dataDe, '20260101');
        $dataAte = $this->normalizeProtheusDate($dataAte, date('Ymd'));

        $pdo = $this->connectionService->connect();
        $params = $this->buildParams($filial, $dataDe, $dataAte, $transportadora, $situacaoEdi, $arquivo);

        $sql = $this->baseSql()
            . $this->filterSql($params)
            . ' ORDER BY GXG.GXG_DTIMP DESC, GXG.GXG_NRIMP DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function exportToXlsx(
        string $filial,
        string $dataDe,
        string $dataAte,
        string $transportadora = '',
        string $situacaoEdi = '',
        string $arquivo = ''
    ): string {
        $rows = $this->listAllEdi($filial, $dataDe, $dataAte, $transportadora, $situacaoEdi, $arquivo);
        $columns = self::exportColumns();

        $headerRow = [];
        foreach (array_values($columns) as $label) {
            $headerRow[] = $this->exportHeaderCell((string) $label);
        }
        $sheet = [$headerRow];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $line = [];
            foreach (array_keys($columns) as $key) {
                $text = $this->displayCellText((string) $key, $row[$key] ?? null);
                $line[] = $this->exportDataCell($row, (string) $key, $text);
            }
            $sheet[] = $line;
        }

        $dir = $this->exportDirectory();
        $safeFilial = preg_replace('/[^0-9]/', '', $this->normalizeFilial($filial)) ?: 'filial';
        $fileName = sprintf('consulta_edi_%s_%s.xlsx', $safeFilial, date('Ymd_His'));
        $fullPath = $dir . DIRECTORY_SEPARATOR . $fileName;

        require_once __DIR__ . '/../Lib/SimpleXLSXGen.php';
        $xlsx = SimpleXLSXGen::fromArray($sheet, 'EDI Transportadoras');
        if (!$xlsx->saveAs($fullPath)) {
            throw new \RuntimeException('Nao foi possivel gravar o arquivo de exportacao.');
        }

        return $fullPath;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function rowAlertClass(array $row): string
    {
        $sit = trim((string) ($row['SIT_EDI'] ?? ''));

        return match ($sit) {
            '2', '5' => 'row-edi-erro',
            '3' => 'row-edi-rejeitado',
            '1', '4' => 'row-edi-ok',
            default => '',
        };
    }

    public function displayCellText(string $columnKey, mixed $value): string
    {
        if ($columnKey === 'SIT_EDI') {
            return self::formatEdiSitLabel($value);
        }
        if ($columnKey === 'ACAO') {
            return self::formatAcaoLabel($value);
        }
        if ($columnKey === 'CNPJ_TRANSPORTADORA') {
            return ProtheusMedidosMonitorService::formatCpfCnpj($value);
        }

        return $this->cellText($value);
    }

    public function displayCellHtml(string $columnKey, mixed $value): string
    {
        return htmlspecialchars($this->displayCellText($columnKey, $value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function assertEdiTables(): void
    {
        $pdo = $this->connectionService->connect();
        if (!$this->tableExists($pdo, 'GXG010')) {
            throw new \RuntimeException(
                'Tabela GXG010 (EDI - Documento de Frete) nao encontrada no banco Protheus. Verifique se o modulo SIGAGFE esta em uso.'
            );
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function buildParams(
        string $filial,
        string $dataDe,
        string $dataAte,
        string $transportadora,
        string $situacaoEdi,
        string $arquivo
    ): array {
        $params = [
            ':filial' => $filial,
            ':data_de' => $dataDe,
            ':data_ate' => $dataAte,
        ];

        if (trim($transportadora) !== '') {
            $params[':transportadora'] = trim($transportadora);
        }
        if (trim($situacaoEdi) !== '') {
            $params[':sit_edi'] = trim($situacaoEdi);
        }
        if (trim($arquivo) !== '') {
            $params[':arquivo'] = '%' . trim($arquivo) . '%';
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function filterSql(array $params): string
    {
        $sql = <<<'SQL'

WHERE GXG.D_E_L_E_T_ = ' '
    AND GXG.GXG_FILIAL = :filial
    AND GXG.GXG_DTIMP BETWEEN :data_de AND :data_ate
SQL;

        if (isset($params[':transportadora'])) {
            $sql .= "\n    AND RTRIM(GXG.GXG_EMISDF) = :transportadora";
        }
        if (isset($params[':sit_edi'])) {
            $sql .= "\n    AND RTRIM(GXG.GXG_EDISIT) = :sit_edi";
        }
        if (isset($params[':arquivo'])) {
            $sql .= "\n    AND RTRIM(GXG.GXG_EDIARQ) LIKE :arquivo";
        }

        return $sql;
    }

    private function baseSql(): string
    {
        $pdo = $this->connectionService->connect();
        $hasGu3 = $this->hasGu3Table($pdo);
        $hasGxh = $this->hasGxhTable($pdo);

        $nomeTransp = $hasGu3
            ? 'COALESCE(NULLIF(RTRIM(GU3.GU3_NMEMIT), \'\'), RTRIM(GXG.GXG_EMISDF))'
            : 'RTRIM(GXG.GXG_EMISDF)';
        $cnpjTransp = $hasGu3 ? 'RTRIM(GU3.GU3_IDFED)' : "''";

        $joinGu3 = $hasGu3
            ? 'LEFT JOIN GU3010 GU3
                ON GU3.GU3_FILIAL = GXG.GXG_FILIAL
                AND RTRIM(GU3.GU3_CDEMIT) = RTRIM(GXG.GXG_EMISDF)
                AND GU3.D_E_L_E_T_ = \' \''
            : '';

        $joinGxh = $hasGxh
            ? 'LEFT JOIN (
                SELECT
                    GXH.GXH_NRIMP,
                    MIN(NULLIF(RTRIM(GXH.GXH_DANFE), \'\')) AS CHAVE_NFE
                FROM GXH010 GXH
                WHERE GXH.D_E_L_E_T_ = \' \'
                GROUP BY GXH.GXH_NRIMP
            ) GXH_AGG ON GXH_AGG.GXH_NRIMP = GXG.GXG_NRIMP'
            : '';

        $chaveNfe = $hasGxh ? 'RTRIM(GXH_AGG.CHAVE_NFE)' : "''";

        return <<<SQL
SELECT
    RTRIM(GXG.GXG_FILIAL) AS GXG_FILIAL,
    SUBSTRING(GXG.GXG_DTIMP, 7, 2) + '/' +
        SUBSTRING(GXG.GXG_DTIMP, 5, 2) + '/' +
        SUBSTRING(GXG.GXG_DTIMP, 1, 4) AS DT_IMPORT,
    SUBSTRING(GXG.GXG_DTEMIS, 7, 2) + '/' +
        SUBSTRING(GXG.GXG_DTEMIS, 5, 2) + '/' +
        SUBSTRING(GXG.GXG_DTEMIS, 1, 4) AS DT_EMISSAO,
    RTRIM(GXG.GXG_EMISDF) AS COD_TRANSPORTADORA,
    {$nomeTransp} AS NOME_TRANSPORTADORA,
    {$cnpjTransp} AS CNPJ_TRANSPORTADORA,
    RTRIM(GXG.GXG_EDIARQ) AS ARQUIVO_EDI,
    RTRIM(GXG.GXG_CDESP) AS ESPECIE,
    RTRIM(GXG.GXG_SERDF) AS SERIE,
    RTRIM(GXG.GXG_NRDF) AS NR_DOCUMENTO,
    RTRIM(GXG.GXG_EDISIT) AS SIT_EDI,
    RTRIM(GXG.GXG_ACAO) AS ACAO,
    RTRIM(GXG.GXG_CTE) AS CHAVE_CTE,
    {$chaveNfe} AS CHAVE_NFE,
    RTRIM(GXG.GXG_ORINR) AS DOC_ORIGEM,
    RTRIM(GXG.GXG_ORISER) AS SERIE_ORIGEM,
    RTRIM(GXG.GXG_NRIMP) AS SEQ_IMPORT,
    LEFT(CAST(GXG.GXG_EDIMSG AS VARCHAR(4000)), 220) AS MSG_EDI
FROM GXG010 GXG
{$joinGu3}
{$joinGxh}
SQL;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function fetchTotal(PDO $pdo, array $params): int
    {
        $sql = 'SELECT COUNT(1) AS total FROM GXG010 GXG' . $this->filterSql($params);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return is_array($row) ? (int) ($row['total'] ?? 0) : 0;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function fetchPage(PDO $pdo, array $params, int $offset, int $limit): array
    {
        $sql = $this->baseSql()
            . $this->filterSql($params)
            . ' ORDER BY GXG.GXG_DTIMP DESC, GXG.GXG_NRIMP DESC'
            . ' OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY';

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    private function hasGu3Table(PDO $pdo): bool
    {
        if ($this->hasGu3Table !== null) {
            return $this->hasGu3Table;
        }
        $this->hasGu3Table = $this->tableExists($pdo, 'GU3010');

        return $this->hasGu3Table;
    }

    private function hasGxhTable(PDO $pdo): bool
    {
        if ($this->hasGxhTable !== null) {
            return $this->hasGxhTable;
        }
        $this->hasGxhTable = $this->tableExists($pdo, 'GXH010');

        return $this->hasGxhTable;
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 AS ok FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = :table'
        );
        $stmt->execute([':table' => $table]);

        return is_array($stmt->fetch());
    }

    private function normalizeFilial(string $filial): string
    {
        $filial = trim($filial);
        if ($filial === '' || !preg_match('/^\d{1,4}$/', $filial)) {
            return '0101';
        }

        return str_pad($filial, 4, '0', STR_PAD_LEFT);
    }

    private function normalizeProtheusDate(string $value, string $fallback): string
    {
        $value = trim($value);
        if (preg_match('/^\d{8}$/', $value)) {
            return $value;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return str_replace('-', '', $value);
        }

        return $fallback;
    }

    private function exportDirectory(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ml-portal-protheus';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Nao foi possivel criar pasta temporaria para exportacao.');
        }

        return $dir;
    }

    private function exportHeaderCell(string $label): string
    {
        $safe = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<style bgcolor="#' . self::COLOR_HEADER . '"><b>' . $safe . '</b></style>';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function exportDataCell(array $row, string $columnKey, string $text): string
    {
        $color = match ($this->rowAlertClass($row)) {
            'row-edi-erro' => self::COLOR_ROW_ERRO,
            'row-edi-rejeitado' => self::COLOR_ROW_REJEITADO,
            'row-edi-ok' => self::COLOR_ROW_OK,
            default => 'FFFFFF',
        };
        $safe = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<style bgcolor="#' . $color . '">' . $safe . '</style>';
    }

    private function cellText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }
}
