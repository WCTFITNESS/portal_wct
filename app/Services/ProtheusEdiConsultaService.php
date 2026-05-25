<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Shuchkin\SimpleXLSXGen;

/**
 * Monitor EDI — ocorrencias GWL/GWD vinculadas a NF (SF2), pedido Lexos (SC5) e ZA4.
 */
class ProtheusEdiConsultaService
{
    private const DELETED_ACTIVE = ' ';

    public const DEFAULT_PER_PAGE = 100;

    private const MAX_PER_PAGE = 200;

    private const EXPORT_MAX_ROWS = 5000;

    private const QUERY_TIMEOUT_SEC = 45;

    private const COLOR_HEADER = 'F1F5F9';
    private const COLOR_ROW_ALERT = 'FFEDD5';
    private const COLOR_ROW_ERRO = 'FEE2E2';

    /** Transportadoras excluidas (regra de negocio WCT). */
    private const TRANSP_EXCLUIDAS = ['000006', '000176', '000177', '000179', '000265'];

    public function __construct(
        private ProtheusConnectionService $connectionService
    ) {
    }

    public static function deletedFlagSql(string $alias): string
    {
        return $alias . ".D_E_L_E_T_ = '" . self::DELETED_ACTIVE . "'";
    }

    /** @return array<string, string> */
    public static function exportColumns(): array
    {
        return [
            'REC' => 'REC',
            'GWL_NROCO' => 'Nº coleta',
            'NOTAFISCAL' => 'Nota fiscal',
            'SERIE' => 'Serie',
            'F2_IDHUB' => 'ID Hub',
            'DATA_OCORRENCIA' => 'Data ocorrencia',
            'HORA_OCORRENIA' => 'Hora ocorrencia',
            'IDLEXOS' => 'ID Lexos',
            'COD_OCORRENCIA' => 'Cod. ocorrencia',
            'MOTIVO_OCORRENCIA' => 'Motivo',
            'DESCRICAO_OCORRENCIA' => 'Descricao',
            'CRIACAO_OCORRENCIA' => 'Criacao',
            'HORA_CRIACAO' => 'Hora criacao',
            'STATUS' => 'Status integracao',
            'PED_MAR' => 'Ped. marketplace',
        ];
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
    public function listOcorrencias(
        string $filial,
        string $dataDe,
        string $dataAte,
        string $notaFiscal = '',
        string $idlexo = '',
        string $pedMar = '',
        string $codOcorrencia = '',
        string $motivoOcorrencia = '',
        string $status = '',
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE
    ): array {
        $filial = $this->normalizeFilial($filial);
        $dataDe = $this->normalizeProtheusDate($dataDe, '20260101');
        $dataAte = $this->normalizeProtheusDate($dataAte, date('Ymd'));
        $page = max(1, $page);
        $perPage = max(10, min(self::MAX_PER_PAGE, $perPage));
        $offset = ($page - 1) * $perPage;

        $pdo = $this->connectionService->connect();
        $this->applyQueryTimeout($pdo);
        $this->assertEdiTables($pdo);

        $params = $this->buildParams(
            $filial,
            $dataDe,
            $dataAte,
            $notaFiscal,
            $idlexo,
            $pedMar,
            $codOcorrencia,
            $motivoOcorrencia,
            $status
        );

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
    public function listAllForExport(
        string $filial,
        string $dataDe,
        string $dataAte,
        string $notaFiscal = '',
        string $idlexo = '',
        string $pedMar = '',
        string $codOcorrencia = '',
        string $motivoOcorrencia = '',
        string $status = ''
    ): array {
        $pdo = $this->connectionService->connect();
        $this->applyQueryTimeout($pdo);
        $this->assertEdiTables($pdo);

        $params = $this->buildParams(
            $this->normalizeFilial($filial),
            $this->normalizeProtheusDate($dataDe, '20260101'),
            $this->normalizeProtheusDate($dataAte, date('Ymd')),
            $notaFiscal,
            $idlexo,
            $pedMar,
            $codOcorrencia,
            $motivoOcorrencia,
            $status
        );

        return $this->fetchPage($pdo, $params, 0, self::EXPORT_MAX_ROWS);
    }

    public function exportToXlsx(
        string $filial,
        string $dataDe,
        string $dataAte,
        string $notaFiscal = '',
        string $idlexo = '',
        string $pedMar = '',
        string $codOcorrencia = '',
        string $motivoOcorrencia = '',
        string $status = ''
    ): string {
        $rows = $this->listAllForExport(
            $filial,
            $dataDe,
            $dataAte,
            $notaFiscal,
            $idlexo,
            $pedMar,
            $codOcorrencia,
            $motivoOcorrencia,
            $status
        );
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
                $line[] = $this->exportDataCell($row, $text);
            }
            $sheet[] = $line;
        }

        $dir = $this->exportDirectory();
        $safeFilial = preg_replace('/[^0-9]/', '', $this->normalizeFilial($filial)) ?: 'filial';
        $fileName = sprintf('monitor_edi_%s_%s.xlsx', $safeFilial, date('Ymd_His'));
        $fullPath = $dir . DIRECTORY_SEPARATOR . $fileName;

        require_once __DIR__ . '/../Lib/SimpleXLSXGen.php';
        $xlsx = SimpleXLSXGen::fromArray($sheet, 'Monitor EDI');
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
        $status = trim((string) ($row['STATUS'] ?? ''));
        if ($status !== '' && $status !== ' ' && !in_array($status, ['1', '2', '3', '4'], true)) {
            return 'row-edi-erro';
        }

        $cod = trim((string) ($row['COD_OCORRENCIA'] ?? ''));
        if ($cod !== '' && $cod !== '001') {
            return 'row-edi-alerta';
        }

        return '';
    }

    public function displayCellText(string $columnKey, mixed $value): string
    {
        return $this->cellText($value);
    }

    public function displayCellHtml(string $columnKey, mixed $value): string
    {
        if ($columnKey === 'DESCRICAO_OCORRENCIA') {
            $text = $this->cellText($value);
            if ($text === '') {
                return '';
            }

            return '<span class="cell-desc">' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
        }

        return htmlspecialchars($this->displayCellText($columnKey, $value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function assertEdiTables(?PDO $pdo = null): void
    {
        $pdo ??= $this->connectionService->connect();
        foreach (['GWL010', 'GWD010', 'SF2010', 'SC5010', 'ZA4010'] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new \RuntimeException(
                    'Tabela ' . $table . ' nao encontrada no banco Protheus. Verifique o ambiente EDI (GWL/GWD).'
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildParams(
        string $filial,
        string $dataDe,
        string $dataAte,
        string $notaFiscal,
        string $idlexo,
        string $pedMar,
        string $codOcorrencia,
        string $motivoOcorrencia,
        string $status
    ): array {
        // ODBC SQL Server exige um bind por placeholder (nao repetir :filial).
        $params = [
            ':filial' => $filial,
            ':filial_gwd' => $filial,
            ':filial_sf2' => $filial,
            ':filial_za4' => $filial,
            ':data_de' => $dataDe,
            ':data_ate' => $dataAte,
        ];

        if (trim($notaFiscal) !== '') {
            $params[':nota_fiscal'] = trim($notaFiscal);
        }
        if (trim($idlexo) !== '') {
            $params[':idlexo'] = '%' . trim($idlexo) . '%';
        }
        if (trim($pedMar) !== '') {
            $like = '%' . trim($pedMar) . '%';
            $params[':ped_mar'] = $like;
            $params[':ped_mar_sc5'] = $like;
        }
        if (trim($codOcorrencia) !== '') {
            $params[':cod_ocorrencia'] = trim($codOcorrencia);
        }
        if (trim($motivoOcorrencia) !== '') {
            $params[':motivo_ocorrencia'] = trim($motivoOcorrencia);
        }
        if (trim($status) !== '') {
            $params[':status'] = trim($status);
        }

        return $params;
    }

    private function joinSql(): string
    {
        $gwl = ProtheusSqlHelper::tbl('GWL010', 'GWL');
        $gwd = ProtheusSqlHelper::tbl('GWD010', 'GWD');
        $sf2 = ProtheusSqlHelper::tbl('SF2010', 'SF2');
        $sc5 = ProtheusSqlHelper::tbl('SC5010', 'SC5');
        $za4 = ProtheusSqlHelper::tbl('ZA4010', 'ZA4');

        $transpList = implode(', ', array_map(
            static fn (string $c) => "'" . $c . "'",
            self::TRANSP_EXCLUIDAS
        ));

        return <<<SQL
FROM {$gwl}
INNER JOIN {$gwd}
    ON GWD.GWD_FILIAL = :filial_gwd
   AND GWD.GWD_NROCO = GWL.GWL_NROCO
   AND {$this->deletedFlagSql('GWD')}
INNER JOIN {$sf2}
    ON SF2.F2_FILIAL = :filial_sf2
   AND SF2.F2_DOC = GWL.GWL_NRDC
   AND SF2.F2_SERIE = GWL.GWL_SERDC
   AND SF2.F2_TRANSP NOT IN ({$transpList})
   AND {$this->deletedFlagSql('SF2')}
INNER JOIN {$sc5}
    ON SF2.F2_FILIAL = SC5.C5_FILIAL
   AND SF2.F2_DOC = SC5.C5_NOTA
   AND SF2.F2_SERIE = SC5.C5_SERIE
   AND SF2.F2_CLIENTE = SC5.C5_CLIENTE
   AND SF2.F2_LOJA = SC5.C5_LOJACLI
   AND {$this->deletedFlagSql('SC5')}
   AND RTRIM(SC5.C5_ZIDLEX) <> ''
INNER JOIN {$za4}
    ON ZA4.ZA4_FILIAL = :filial_za4
   AND ZA4.ZA4_IDLEXO = SC5.C5_ZIDLEX
   AND {$this->deletedFlagSql('ZA4')}
SQL;
    }

    private function selectSql(): string
    {
        $dtOcor = $this->formatProtheusDateSql('GWD.GWD_DTOCOR');
        $dtCria = $this->formatProtheusDateSql('GWD.GWD_DTCRIA');
        $hrOcor = $this->formatProtheusTimeSql('GWD.GWD_HROCOR');
        $hrCria = $this->formatProtheusTimeSql('GWD.GWD_HRCRIA');

        return <<<SQL
SELECT
    GWD.R_E_C_N_O_ AS REC,
    RTRIM(GWL.GWL_NROCO) AS GWL_NROCO,
    RTRIM(GWL.GWL_NRDC) AS NOTAFISCAL,
    RTRIM(GWL.GWL_SERDC) AS SERIE,
    RTRIM(SF2.F2_IDHUB) AS F2_IDHUB,
    {$dtOcor} AS DATA_OCORRENCIA,
    {$hrOcor} AS HORA_OCORRENIA,
    RTRIM(SC5.C5_ZIDLEX) AS IDLEXOS,
    RTRIM(GWD.GWD_CDTIPO) AS COD_OCORRENCIA,
    RTRIM(GWD.GWD_CDMOT) AS MOTIVO_OCORRENCIA,
    LEFT(CAST(GWD.GWD_DSOCOR AS VARCHAR(4000)), 500) AS DESCRICAO_OCORRENCIA,
    {$dtCria} AS CRIACAO_OCORRENCIA,
    {$hrCria} AS HORA_CRIACAO,
    RTRIM(GWD.GWD_SITINT) AS STATUS,
    COALESCE(NULLIF(RTRIM(ZA4.ZA4_PEDMAR), ''), RTRIM(SC5.C5_PEDMAR)) AS PED_MAR
SQL;
    }

    private function baseSql(): string
    {
        return $this->selectSql() . "\n" . $this->joinSql();
    }

    /**
     * @param array<string, mixed> $params
     */
    private function filterSql(array $params): string
    {
        $sql = <<<'SQL'

WHERE GWL.GWL_FILIAL = :filial
  AND GWL.D_E_L_E_T_ = ' '
  AND GWD.GWD_DTOCOR BETWEEN :data_de AND :data_ate
SQL;

        if (isset($params[':nota_fiscal'])) {
            $sql .= "\n  AND RTRIM(GWL.GWL_NRDC) = :nota_fiscal";
        }
        if (isset($params[':idlexo'])) {
            $sql .= "\n  AND RTRIM(SC5.C5_ZIDLEX) LIKE :idlexo";
        }
        if (isset($params[':ped_mar'])) {
            $sql .= "\n  AND (RTRIM(ZA4.ZA4_PEDMAR) LIKE :ped_mar OR RTRIM(SC5.C5_PEDMAR) LIKE :ped_mar_sc5)";
        }
        if (isset($params[':cod_ocorrencia'])) {
            $sql .= "\n  AND RTRIM(GWD.GWD_CDTIPO) = :cod_ocorrencia";
        }
        if (isset($params[':motivo_ocorrencia'])) {
            $sql .= "\n  AND RTRIM(GWD.GWD_CDMOT) = :motivo_ocorrencia";
        }
        if (isset($params[':status'])) {
            $sql .= "\n  AND RTRIM(GWD.GWD_SITINT) = :status";
        }

        return $sql;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function fetchTotal(PDO $pdo, array $params): int
    {
        $sql = 'SELECT COUNT(1) AS total ' . $this->joinSql() . $this->filterSql($params);
        $stmt = $pdo->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();
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
            . ' ORDER BY GWD.R_E_C_N_O_ DESC'
            . ' OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY';

        $stmt = $pdo->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    private function formatProtheusDateSql(string $column): string
    {
        return "CASE WHEN NULLIF(RTRIM({$column}), '') IS NULL THEN '' ELSE "
            . "SUBSTRING({$column}, 7, 2) + '/' + SUBSTRING({$column}, 5, 2) + '/' + SUBSTRING({$column}, 1, 4) END";
    }

    private function formatProtheusTimeSql(string $column): string
    {
        return "CASE WHEN NULLIF(RTRIM({$column}), '') IS NULL THEN '' ELSE "
            . "SUBSTRING({$column}, 1, 2) + ':' + SUBSTRING({$column}, 3, 2) "
            . "+ CASE WHEN LEN(RTRIM({$column})) >= 6 THEN ':' + SUBSTRING({$column}, 5, 2) ELSE '' END END";
    }

    /**
     * @param array<string, mixed> $params
     */
    private function bindParams(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
    }

    private function applyQueryTimeout(PDO $pdo): void
    {
        try {
            $pdo->exec('SET LOCK_TIMEOUT 45000');
        } catch (\Throwable) {
            // ignorar se o driver nao suportar
        }
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
    private function exportDataCell(array $row, string $text): string
    {
        $color = match ($this->rowAlertClass($row)) {
            'row-edi-erro' => self::COLOR_ROW_ERRO,
            'row-edi-alerta' => self::COLOR_ROW_ALERT,
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
