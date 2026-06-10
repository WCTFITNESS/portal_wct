<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Shuchkin\SimpleXLSXGen;

/**
 * Monitor de pedidos Lexos com erro (ZA4010).
 * SC5: ID Lexos / pedido marketplace. ZA5: somente dados do cliente (opcional).
 * D_E_L_E_T_: registro ativo usa um espaco (' ').
 */
class ProtheusZa4PedidosErroMonitorService
{
    /** Registro nao excluido no Protheus (padrao confirmado nas demais consultas do portal). */
    private const DELETED_ACTIVE = ' ';

    private const EXPORT_MAX_ROWS = 5000;

    /** Pagina maxima na tela (OFFSET/FETCH na listagem). */
    private const MAX_PER_PAGE = 200;

    /** Timeout da consulta no SQL Server (segundos). */
    private const QUERY_TIMEOUT_SEC = 45;

    private const COLOR_HEADER = 'F1F5F9';
    private const COLOR_ROW_ERRO = 'FEE2E2';

    /** @var array<string, mixed>|null */
    private ?array $schema = null;

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
            'ZA4_FILIAL' => 'Filial',
            'DT_REGISTRO' => 'Data',
            'IDLEXOS' => 'ID Lexos',
            'PED_MAR' => 'Ped. marketplace',
            'MARKETPLACE' => 'Marketplace',
            'STATUS_PED' => 'Status',
            'MSG_ERRO' => 'Mensagem erro',
            'CLIENTE' => 'Cliente',
            'CPF_CNPJ' => 'CPF/CNPJ',
        ];
    }

    /**
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   total: int,
     *   page: int,
     *   per_page: int,
     *   total_pages: int,
     *   schema_notes: list<string>
     * }
     */
    public function listPedidos(
        string $filial,
        string $dataDe,
        string $dataAte,
        bool $somenteErro = true,
        string $idlexo = '',
        string $pedMar = '',
        string $textoErro = '',
        int $page = 1,
        int $perPage = 50
    ): array {
        $filial = $this->normalizeFilial($filial);
        $dataDe = $this->normalizeProtheusDate($dataDe, '20260101');
        $dataAte = $this->normalizeProtheusDate($dataAte, date('Ymd'));
        $page = max(1, $page);
        $perPage = max(10, min(self::MAX_PER_PAGE, $perPage));
        $offset = ($page - 1) * $perPage;

        $pdo = $this->connectionService->connect();
        $this->applyQueryTimeout($pdo);
        $schema = $this->resolveSchema($pdo);
        $params = $this->buildParams($filial, $dataDe, $dataAte, $idlexo, $pedMar, $textoErro, $schema);

        $total = $this->fetchTotal($pdo, $schema, $params, $somenteErro);
        $rows = $this->fetchPage($pdo, $schema, $params, $somenteErro, $offset, $perPage);
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'schema_notes' => $schema['notes'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAllForExport(
        string $filial,
        string $dataDe,
        string $dataAte,
        bool $somenteErro = true,
        string $idlexo = '',
        string $pedMar = '',
        string $textoErro = ''
    ): array {
        $pdo = $this->connectionService->connect();
        $this->applyQueryTimeout($pdo);
        $schema = $this->resolveSchema($pdo);
        $params = $this->buildParams(
            $this->normalizeFilial($filial),
            $this->normalizeProtheusDate($dataDe, '20260101'),
            $this->normalizeProtheusDate($dataAte, date('Ymd')),
            $idlexo,
            $pedMar,
            $textoErro,
            $schema
        );

        return $this->fetchPage($pdo, $schema, $params, $somenteErro, 0, self::EXPORT_MAX_ROWS);
    }

    public function exportToXlsx(
        string $filial,
        string $dataDe,
        string $dataAte,
        bool $somenteErro = true,
        string $idlexo = '',
        string $pedMar = '',
        string $textoErro = ''
    ): string {
        $rows = $this->listAllForExport($filial, $dataDe, $dataAte, $somenteErro, $idlexo, $pedMar, $textoErro);
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
                $line[] = $this->exportDataCell($text);
            }
            $sheet[] = $line;
        }

        $dir = $this->exportDirectory();
        $safeFilial = preg_replace('/[^0-9]/', '', $this->normalizeFilial($filial)) ?: 'filial';
        $fileName = sprintf('monitor_za4_erros_%s_%s.xlsx', $safeFilial, date('Ymd_His'));
        $fullPath = $dir . DIRECTORY_SEPARATOR . $fileName;

        require_once __DIR__ . '/../Lib/SimpleXLSXGen.php';
        $xlsx = SimpleXLSXGen::fromArray($sheet, 'Erros ZA4');
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
        $msg = trim((string) ($row['MSG_ERRO'] ?? ''));
        $status = strtoupper(trim((string) ($row['STATUS_PED'] ?? '')));

        if ($msg !== '' || in_array($status, ['E', 'ER', 'ERR', '2', 'ERRO', 'F', 'FALHA', 'X'], true)) {
            return 'row-za4-erro';
        }

        return '';
    }

    public function displayCellText(string $columnKey, mixed $value): string
    {
        if ($columnKey === 'CPF_CNPJ') {
            return ProtheusRomaneioMonitorService::formatCpfCnpj($value);
        }
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    public function displayCellHtml(string $columnKey, mixed $value): string
    {
        $text = $this->displayCellText($columnKey, $value);
        if ($columnKey === 'MSG_ERRO') {
            return '<span class="cell-erro-text">' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
        }

        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSchema(PDO $pdo): array
    {
        if ($this->schema !== null) {
            return $this->schema;
        }

        $notes = [];
        if (!$this->tableExists($pdo, 'ZA4010')) {
            throw new \RuntimeException('Tabela ZA4010 nao encontrada no banco Protheus.');
        }

        $lexos = ProtheusZa4LexosSchema::resolvePedidosErro($pdo);
        $notes = array_merge($notes, $lexos['notes']);

        $za4Cols = $this->tableColumns($pdo, 'ZA4010');

        $date4 = $this->pickDateColumn($za4Cols, 'ZA4');
        $status4 = $this->pickColumn($za4Cols, ['ZA4_STATUS', 'ZA4_SIT', 'ZA4_SITUAC', 'ZA4_SITINT', 'ZA4_SITPED']);
        $msg4 = $this->pickColumn($za4Cols, ['ZA4_ERRO', 'ZA4_MSG', 'ZA4_MSGER', 'ZA4_MENSAG', 'ZA4_LOG', 'ZA4_OBS', 'ZA4_OBSERV']);
        $mkt4 = $this->pickColumn($za4Cols, ['ZA4_MARKET', 'ZA4_MKT', 'ZA4_ZMAKET', 'ZA4_CANAL']);

        $notes[] = "Filtro de exclusao: D_E_L_E_T_ = '" . self::DELETED_ACTIVE . "' (1 espaco — padrao Protheus).";
        if ($date4 === null) {
            $notes[] = 'Nenhuma coluna de data em ZA4 detectada; filtro por periodo desabilitado.';
        }
        if ($status4 === null && $msg4 === null) {
            $notes[] = 'Colunas de erro/status nao detectadas em ZA4; use o filtro de texto ou desmarque "Somente com erro".';
        }

        $this->schema = [
            'idlexo4' => $lexos['idlexo4'],
            'idlexo_expr' => $lexos['idlexo_expr'],
            'pedmar4' => $lexos['pedmar4'],
            'date4' => $date4,
            'status4' => $status4,
            'msg4' => $msg4,
            'mkt4' => $mkt4,
            'sc5_match_cond' => $lexos['sc5_match_cond'],
            'sc5_apply_sql' => $lexos['sc5_apply_sql'],
            'za5_apply_sql' => $lexos['za5_apply_sql'],
            'recno' => $this->pickColumn($za4Cols, ['R_E_C_N_O_']) ?? 'R_E_C_N_O_',
            'has_sc5' => $lexos['has_sc5'],
            'has_za5' => $lexos['has_za5'],
            'client_nome' => $lexos['client_nome'],
            'client_cgc' => $lexos['client_cgc'],
            'notes' => $notes,
        ];

        return $this->schema;
    }

    /**
     * SELECT enriquecido (SC5/ZA5) apos paginar ZA4 — evita scan completo com joins.
     */
    private function selectEnrichedSql(array $schema): string
    {
        $dateExpr = $schema['date4'] !== null
            ? "SUBSTRING(ZA4.{$schema['date4']}, 7, 2) + '/' +
                SUBSTRING(ZA4.{$schema['date4']}, 5, 2) + '/' +
                SUBSTRING(ZA4.{$schema['date4']}, 1, 4)"
            : "''";

        $idlexo = $schema['idlexo_expr'];
        $pedMarExpr = $this->pedMarExpr($schema);
        $mktExpr = $schema['mkt4'] !== null
            ? 'RTRIM(ZA4.' . $schema['mkt4'] . ')'
            : ($schema['has_sc5'] ? 'RTRIM(SC5.C5_ZMAKET)' : "''");
        $statusExpr = $schema['status4'] !== null
            ? 'RTRIM(ZA4.' . $schema['status4'] . ')'
            : "''";
        $msgZa4 = $schema['msg4'] !== null ? 'RTRIM(ZA4.' . $schema['msg4'] . ')' : "''";
        $clienteExpr = $schema['has_za5'] && $schema['client_nome'] !== null
            ? 'RTRIM(ZA5.' . $schema['client_nome'] . ')'
            : "''";
        $cgcExpr = $schema['has_za5'] && $schema['client_cgc'] !== null
            ? 'RTRIM(ZA5.' . $schema['client_cgc'] . ')'
            : "''";

        return <<<SQL
SELECT
    RTRIM(ZA4.ZA4_FILIAL) AS ZA4_FILIAL,
    {$dateExpr} AS DT_REGISTRO,
    {$idlexo} AS IDLEXOS,
    {$pedMarExpr} AS PED_MAR,
    {$mktExpr} AS MARKETPLACE,
    {$statusExpr} AS STATUS_PED,
    {$msgZa4} AS MSG_ERRO,
    {$clienteExpr} AS CLIENTE,
    {$cgcExpr} AS CPF_CNPJ
FROM {$this->tblZa4()}
{$schema['sc5_apply_sql']}
{$schema['za5_apply_sql']}
SQL;
    }

    private function pedMarExpr(array $schema): string
    {
        $parts = [];
        if ($schema['pedmar4'] !== null) {
            $parts[] = 'NULLIF(RTRIM(ZA4.' . $schema['pedmar4'] . "), '')";
        }
        if ($schema['has_sc5']) {
            $parts[] = "NULLIF(RTRIM(SC5.C5_PEDMAR), '')";
        }
        if ($parts === []) {
            return "''";
        }

        return 'COALESCE(' . implode(', ', $parts) . ", '')";
    }

    /**
     * Filtros apenas em ZA4 (+ EXISTS em SC5 quando necessario). Sem JOIN/APPLY — rapido para COUNT e paginacao.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $params
     */
    private function za4FilterSql(array $schema, array $params, bool $somenteErro): string
    {
        $sql = '
WHERE ' . $this->deletedFlagSql('ZA4') . '
    AND ZA4.ZA4_FILIAL = :filial';

        if ($schema['date4'] !== null) {
            $sql .= "
    AND ZA4.{$schema['date4']} BETWEEN :data_de AND :data_ate";
        }

        if (isset($params[':idlexo'])) {
            $idlexoParts = [];
            if ($schema['idlexo4'] !== null) {
                $idlexoParts[] = 'RTRIM(ZA4.' . $schema['idlexo4'] . ') LIKE :idlexo';
            }
            $exists = $this->sc5ExistsSql($schema, 'AND RTRIM(SC5.C5_ZIDLEX) LIKE :idlexo');
            if ($exists !== '') {
                $idlexoParts[] = $exists;
            }
            if ($idlexoParts !== []) {
                $sql .= '
    AND (' . implode(' OR ', $idlexoParts) . ')';
            }
        }

        if (isset($params[':ped_mar'])) {
            $pedParts = [];
            if ($schema['pedmar4'] !== null) {
                $pedParts[] = 'RTRIM(ZA4.' . $schema['pedmar4'] . ') LIKE :ped_mar';
            }
            $exists = $this->sc5ExistsSql($schema, 'AND RTRIM(SC5.C5_PEDMAR) LIKE :ped_mar');
            if ($exists !== '') {
                $pedParts[] = $exists;
            }
            if ($pedParts !== []) {
                $sql .= '
    AND (' . implode(' OR ', $pedParts) . ')';
            }
        }

        if (isset($params[':texto_erro'])) {
            $erroParts = [];
            if ($schema['msg4'] !== null) {
                $erroParts[] = 'RTRIM(ZA4.' . $schema['msg4'] . ') LIKE :texto_erro';
            }
            if ($schema['status4'] !== null) {
                $erroParts[] = 'RTRIM(ZA4.' . $schema['status4'] . ') LIKE :texto_erro';
            }
            if ($erroParts !== []) {
                $sql .= '
    AND (' . implode(' OR ', $erroParts) . ')';
            }
        }

        $sql .= $this->sqlSomenteErro($schema, $somenteErro);

        return $sql;
    }

    private function sc5ExistsSql(array $schema, string $andExtra = ''): string
    {
        if (!$schema['has_sc5']) {
            return '';
        }

        return 'EXISTS (
    SELECT 1
    FROM ' . ProtheusSqlHelper::tbl('SC5010', 'SC5') . '
    WHERE SC5.C5_FILIAL = ZA4.ZA4_FILIAL
        AND ' . self::deletedFlagSql('SC5') . '
        AND ' . $schema['sc5_match_cond'] . '
        ' . $andExtra . '
)';
    }

    private function sqlSomenteErro(array $schema, bool $somenteErro): string
    {
        if (!$somenteErro) {
            return '';
        }

        $parts = [];
        if ($schema['status4'] !== null) {
            $col = $schema['status4'];
            $parts[] = "UPPER(RTRIM(ISNULL(ZA4.{$col}, ''))) IN ('E','ER','ERR','2','ERRO','F','FALHA','X')";
        }
        if ($schema['msg4'] !== null) {
            $parts[] = 'RTRIM(ISNULL(ZA4.' . $schema['msg4'] . ", '')) <> ''";
        }

        if ($parts === []) {
            return '';
        }

        return '
    AND (' . implode(' OR ', $parts) . ')';
    }

    /** Ordenacao so em colunas ZA4 (paginacao antes dos joins). */
    private function orderSqlZa4(array $schema): string
    {
        $parts = [];
        if ($schema['date4'] !== null) {
            $parts[] = 'ZA4.' . $schema['date4'] . ' DESC';
        }
        if ($schema['idlexo4'] !== null) {
            $parts[] = 'ZA4.' . $schema['idlexo4'] . ' DESC';
        } elseif ($schema['pedmar4'] !== null) {
            $parts[] = 'ZA4.' . $schema['pedmar4'] . ' DESC';
        } else {
            $parts[] = 'ZA4.' . $schema['recno'] . ' DESC';
        }

        return ' ORDER BY ' . implode(', ', $parts);
    }

    private function applyQueryTimeout(PDO $pdo): void
    {
        if (defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) {
            $pdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, self::QUERY_TIMEOUT_SEC);
        }
    }

    private function tblZa4(): string
    {
        return ProtheusSqlHelper::tbl('ZA4010', 'ZA4');
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $params
     */
    private function buildParams(
        string $filial,
        string $dataDe,
        string $dataAte,
        string $idlexo,
        string $pedMar,
        string $textoErro,
        array $schema
    ): array {
        $params = [
            ':filial' => $filial,
            ':data_de' => $dataDe,
            ':data_ate' => $dataAte,
        ];

        if (trim($idlexo) !== '') {
            $params[':idlexo'] = '%' . trim($idlexo) . '%';
        }
        if (trim($pedMar) !== '') {
            $params[':ped_mar'] = '%' . trim($pedMar) . '%';
        }
        if (trim($textoErro) !== '') {
            $params[':texto_erro'] = '%' . trim($textoErro) . '%';
        }

        if ($schema['date4'] === null) {
            unset($params[':data_de'], $params[':data_ate']);
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $params
     */
    private function fetchTotal(PDO $pdo, array $schema, array $params, bool $somenteErro): int
    {
        $sql = 'SELECT COUNT(1) AS total FROM ' . $this->tblZa4()
            . $this->za4FilterSql($schema, $params, $somenteErro);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return is_array($row) ? (int) ($row['total'] ?? 0) : 0;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function fetchPage(
        PDO $pdo,
        array $schema,
        array $params,
        bool $somenteErro,
        int $offset,
        int $limit
    ): array {
        $recno = $schema['recno'];
        $sql = ';WITH pg AS (
    SELECT ZA4.' . $recno . ' AS recno
    FROM ' . $this->tblZa4()
            . $this->za4FilterSql($schema, $params, $somenteErro)
            . $this->orderSqlZa4($schema)
            . '
    OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY
)
' . $this->selectEnrichedSql($schema) . '
INNER JOIN pg ON pg.recno = ZA4.' . $recno;

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

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 AS ok FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = :table'
        );
        $stmt->execute([':table' => $table]);

        return is_array($stmt->fetch());
    }

    /**
     * @return list<string>
     */
    private function tableColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = :table'
        );
        $stmt->execute([':table' => $table]);
        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        return array_map(
            static fn (array $row): string => strtoupper((string) ($row['COLUMN_NAME'] ?? '')),
            $rows
        );
    }

    /**
     * @param list<string> $available
     * @param list<string> $candidates
     */
    private function pickColumn(array $available, array $candidates): ?string
    {
        $set = array_flip($available);
        foreach ($candidates as $candidate) {
            $key = strtoupper($candidate);
            if (isset($set[$key])) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param list<string> $columns
     */
    private function pickDateColumn(array $columns, string $prefix): ?string
    {
        $preferred = [];
        foreach ($columns as $col) {
            if (!str_starts_with($col, $prefix . '_DT') && !str_starts_with($col, $prefix . '_DATA')) {
                continue;
            }
            if (str_contains($col, 'EMIS') || str_contains($col, 'PED') || str_contains($col, 'INC')
                || str_contains($col, 'CAD') || str_contains($col, 'REG')) {
                $preferred[] = $col;
            }
        }

        return $preferred[0] ?? $this->pickColumn($columns, [
            $prefix . '_DTINC',
            $prefix . '_DTPED',
            $prefix . '_DTREG',
            $prefix . '_DATA',
            $prefix . '_DTALT',
        ]);
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

    private function exportDataCell(string $text): string
    {
        $safe = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<style bgcolor="#' . self::COLOR_ROW_ERRO . '">' . $safe . '</style>';
    }
}
