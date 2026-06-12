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
    /**
     * Canais exibidos no filtro mesmo sem registro no recorte do monitor.
     *
     * @var list<string>
     */
    private const MARKETPLACES_PADRAO = [
        'AMAZON',
        'DECATHLON',
        'LEXOS ERP',
        'MERCADO LIVRE',
        'WEB CONTINENTAL',
    ];

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
        int $perPage = 50,
        string $marketplace = ''
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
        $ctx = $this->buildFilterContext($filial, $dataDe, $dataAte, $idlexo, $pedMar, $textoErro, $marketplace, $schema);
        $broad = $this->isBroadQuery($ctx);
        $notes = $schema['notes'];

        if ($broad && $page > 1) {
            $notes[] = 'Consulta ampla: use pedido marketplace, ID Lexos ou marketplace para paginar.';

            return [
                'rows' => [],
                'total' => -1,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => 1,
                'schema_notes' => $notes,
                'query_hint' => 'broad_pagination',
            ];
        }

        try {
            $total = $broad ? -1 : $this->fetchTotal($pdo, $schema, $ctx, $somenteErro);
            $rows = $this->fetchPage($pdo, $schema, $ctx, $somenteErro, $broad ? 0 : $offset, $perPage, $broad);
        } catch (\PDOException $exception) {
            throw new \RuntimeException($this->formatQueryException($exception, $broad), 0, $exception);
        }

        if ($broad) {
            $notes[] = 'Total nao calculado (consulta ampla). Informe pedido, ID Lexos ou marketplace para resposta mais rapida.';
            $totalPages = 1;
        } else {
            $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'schema_notes' => $notes,
            'query_hint' => $broad ? 'broad_list' : null,
        ];
    }

    /** @return list<string> */
    public function defaultMarketplaceOptions(): array
    {
        return $this->mergeMarketplaceOptions([]);
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
        string $textoErro = '',
        string $marketplace = ''
    ): array {
        $pdo = $this->connectionService->connect();
        $this->applyQueryTimeout($pdo);
        $schema = $this->resolveSchema($pdo);
        $ctx = $this->buildFilterContext(
            $this->normalizeFilial($filial),
            $this->normalizeProtheusDate($dataDe, '20260101'),
            $this->normalizeProtheusDate($dataAte, date('Ymd')),
            $idlexo,
            $pedMar,
            $textoErro,
            $marketplace,
            $schema
        );

        return $this->fetchPage($pdo, $schema, $ctx, $somenteErro, 0, self::EXPORT_MAX_ROWS);
    }

    public function exportToXlsx(
        string $filial,
        string $dataDe,
        string $dataAte,
        bool $somenteErro = true,
        string $idlexo = '',
        string $pedMar = '',
        string $textoErro = '',
        string $marketplace = ''
    ): string {
        $rows = $this->listAllForExport(
            $filial,
            $dataDe,
            $dataAte,
            $somenteErro,
            $idlexo,
            $pedMar,
            $textoErro,
            $marketplace
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
     * @return list<string>
     */
    public function listMarketplaces(string $filial, string $dataDe, string $dataAte): array
    {
        $pdo = $this->connectionService->connect();
        $this->applyQueryTimeout($pdo);
        $schema = $this->resolveSchema($pdo);
        $ctx = $this->buildFilterContext(
            $this->normalizeFilial($filial),
            $this->normalizeProtheusDate($dataDe, '20260101'),
            $this->normalizeProtheusDate($dataAte, date('Ymd')),
            '',
            '',
            '',
            '',
            $schema
        );

        if ($schema['mkt4'] !== null) {
            $sql = 'SELECT DISTINCT RTRIM(ZA4.' . $schema['mkt4'] . ') AS marketplace
FROM ' . $this->tblZa4() . '
WHERE ' . $this->deletedFlagSql('ZA4') . '
    AND ZA4.ZA4_FILIAL = :filial';
            if ($schema['date4'] !== null) {
                $sql .= "
    AND ZA4.{$schema['date4']} BETWEEN :data_de AND :data_ate";
            }
            $sql .= "
    AND RTRIM(ISNULL(ZA4.{$schema['mkt4']}, '')) <> ''
ORDER BY marketplace";
        } elseif ($schema['has_sc5']) {
            $sql = 'SELECT DISTINCT RTRIM(SC5.C5_ZMAKET) AS marketplace
FROM ' . ProtheusSqlHelper::tbl('SC5010', 'SC5') . '
WHERE ' . self::deletedFlagSql('SC5') . '
    AND SC5.C5_FILIAL = :filial
    AND RTRIM(ISNULL(SC5.C5_ZMAKET, \'\')) <> \'\'';
            if ($schema['date4'] !== null) {
                $sql .= '
    AND EXISTS (
        SELECT 1
        FROM ' . $this->tblZa4() . '
        WHERE ZA4.ZA4_FILIAL = SC5.C5_FILIAL
            AND ' . $this->deletedFlagSql('ZA4') . '
            AND ZA4.' . $schema['date4'] . ' BETWEEN :data_de AND :data_ate
            AND (
                ' . ($schema['pedmar4'] !== null
                    ? 'RTRIM(SC5.C5_PEDMAR) = RTRIM(ZA4.' . $schema['pedmar4'] . ')'
                    : ($schema['idlexo4'] !== null
                        ? 'RTRIM(SC5.C5_ZIDLEX) = RTRIM(ZA4.' . $schema['idlexo4'] . ')'
                        : '1 = 1')) . '
            )
    )';
            }
            $sql .= '
ORDER BY marketplace';
        } else {
            return $this->defaultMarketplaceOptions();
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute(ProtheusSqlHelper::paramsForSql($sql, $ctx['params']));
        $rows = $stmt->fetchAll();

        $lista = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $nome = trim((string) ($row['marketplace'] ?? ''));
                if ($nome !== '') {
                    $lista[] = $nome;
                }
            }
        }

        return $this->mergeMarketplaceOptions($lista);
    }

    /**
     * @return list<string>
     */
    public function parseBatchFilter(string $input, int $maxItems = 100): array
    {
        $input = trim($input);
        if ($input === '') {
            return [];
        }

        $parts = preg_split('/[\s,;]+/', $input) ?: [];
        $items = [];
        foreach ($parts as $part) {
            $value = trim($part);
            if ($value !== '') {
                $items[] = $value;
            }
        }

        $unique = [];
        foreach ($items as $value) {
            $key = strtoupper($value);
            if (!isset($unique[$key])) {
                $unique[$key] = $value;
            }
        }

        return array_slice(array_values($unique), 0, $maxItems);
    }

    /**
     * Filtros apenas em ZA4 (+ EXISTS em SC5 quando necessario). Sem JOIN/APPLY — rapido para COUNT e paginacao.
     *
     * @param array<string, mixed> $schema
     * @param array{params: array<string, string>, pedidos: list<string>} $ctx
     */
    private function za4FilterSql(array $schema, array $ctx, bool $somenteErro): string
    {
        $params = $ctx['params'];
        $pedidos = $ctx['pedidos'];

        $sql = '
WHERE ' . $this->deletedFlagSql('ZA4') . '
    AND ZA4.ZA4_FILIAL = :filial';

        if ($schema['date4'] !== null) {
            $sql .= "
    AND ZA4.{$schema['date4']} BETWEEN :data_de AND :data_ate";
        }

        if (isset($params[':marketplace'])) {
            $sql .= $this->marketplaceWhereSql($schema, $params[':marketplace']);
        }

        if (isset($params[':idlexo'])) {
            $idlexoParts = [];
            if ($schema['idlexo4'] !== null) {
                $idlexoParts[] = 'RTRIM(ZA4.' . $schema['idlexo4'] . ') LIKE :idlexo';
            }
            $exists = $this->sc5ExistsFilterSql('AND RTRIM(SC5.C5_ZIDLEX) LIKE :idlexo');
            if ($exists !== '') {
                $idlexoParts[] = $exists;
            }
            if ($idlexoParts !== []) {
                $sql .= '
    AND (' . implode(' OR ', $idlexoParts) . ')';
            }
        }

        if ($pedidos !== []) {
            $placeholders = [];
            foreach (array_keys($pedidos) as $i) {
                $placeholders[] = ':ped_' . $i;
            }
            $inList = implode(', ', $placeholders);
            if ($schema['pedmar4'] !== null) {
                $sql .= '
    AND RTRIM(ZA4.' . $schema['pedmar4'] . ') IN (' . $inList . ')';
            } else {
                $exists = $this->sc5ExistsFilterSql('AND RTRIM(SC5.C5_PEDMAR) IN (' . $inList . ')');
                if ($exists !== '') {
                    $sql .= '
    AND ' . $exists;
                }
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

    private function sc5ExistsFilterSql(string $andExtra = ''): string
    {
        return 'EXISTS (
    SELECT 1
    FROM ' . ProtheusSqlHelper::tbl('SC5010', 'SC5') . '
    WHERE SC5.C5_FILIAL = ZA4.ZA4_FILIAL
        AND ' . self::deletedFlagSql('SC5') . '
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
     * @return array{params: array<string, string>, pedidos: list<string>}
     */
    private function buildFilterContext(
        string $filial,
        string $dataDe,
        string $dataAte,
        string $idlexo,
        string $pedMar,
        string $textoErro,
        string $marketplace,
        array $schema
    ): array {
        $params = [
            ':filial' => $filial,
            ':data_de' => $dataDe,
            ':data_ate' => $dataAte,
        ];

        $marketplace = trim($marketplace);
        if ($marketplace !== '') {
            $params[':marketplace'] = $marketplace;
        }

        if (trim($idlexo) !== '') {
            $params[':idlexo'] = '%' . trim($idlexo) . '%';
        }
        if (trim($textoErro) !== '') {
            $params[':texto_erro'] = '%' . trim($textoErro) . '%';
        }

        $pedidos = $this->parseBatchFilter($pedMar);
        foreach ($pedidos as $i => $ped) {
            $params[':ped_' . $i] = $ped;
        }

        if ($schema['date4'] === null) {
            unset($params[':data_de'], $params[':data_ate']);
        }

        return [
            'params' => $params,
            'pedidos' => $pedidos,
        ];
    }

    private function marketplaceExpr(array $schema): string
    {
        if ($schema['mkt4'] !== null) {
            if ($schema['has_sc5']) {
                return 'COALESCE(NULLIF(RTRIM(ZA4.' . $schema['mkt4'] . "), ''), NULLIF(RTRIM(SC5.C5_ZMAKET), ''))";
            }

            return "NULLIF(RTRIM(ZA4.{$schema['mkt4']}), '')";
        }

        if ($schema['has_sc5']) {
            return "NULLIF(RTRIM(SC5.C5_ZMAKET), '')";
        }

        return "''";
    }

    private function marketplaceWhereSql(array $schema, string $marketplace): string
    {
        if ($schema['mkt4'] !== null) {
            if (ProtheusSqlHelper::isWebContinentalFilter($marketplace)) {
                return '
    AND (' . ProtheusSqlHelper::webContinentalMatchSql('ZA4.' . $schema['mkt4']) . ')';
            }

            return '
    AND RTRIM(ZA4.' . $schema['mkt4'] . ') = :marketplace';
        }

        if (!$schema['has_sc5']) {
            return '';
        }

        $sc5Cond = ProtheusSqlHelper::isWebContinentalFilter($marketplace)
            ? ' AND (' . ProtheusSqlHelper::webContinentalMatchSql('SC5.C5_ZMAKET') . ')'
            : ' AND RTRIM(SC5.C5_ZMAKET) = :marketplace';

        return '
    AND ' . $this->sc5ExistsFilterSql($sc5Cond);
    }

    /**
     * @param array{params: array<string, string>, pedidos: list<string>} $ctx
     */
    private function isBroadQuery(array $ctx): bool
    {
        $params = $ctx['params'];

        return $ctx['pedidos'] === []
            && !isset($params[':idlexo'])
            && !isset($params[':texto_erro'])
            && !isset($params[':marketplace']);
    }

    private function formatQueryException(\PDOException $exception, bool $broad): string
    {
        $message = $exception->getMessage();
        if (stripos($message, 'HYT00') !== false || stripos($message, 'tempo limite') !== false) {
            if ($broad) {
                return 'A consulta ampla (somente filial e periodo) excedeu o tempo limite de '
                    . self::QUERY_TIMEOUT_SEC
                    . 's. Informe pedido marketplace, ID Lexos ou marketplace para filtrar e acelerar.';
            }

            return 'A consulta excedeu o tempo limite de ' . self::QUERY_TIMEOUT_SEC
                . 's. Reduza o periodo, informe menos pedidos ou refine o texto do erro.';
        }

        return 'Erro SQL: ' . $message;
    }

    /**
     * @param list<string> ...$listas
     * @return list<string>
     */
    private function mergeMarketplaceOptions(array ...$listas): array
    {
        $porChave = [];
        $temWebContinental = false;
        foreach (array_merge(self::MARKETPLACES_PADRAO, ...$listas) as $nome) {
            $nome = trim($nome);
            if ($nome === '') {
                continue;
            }
            if (ProtheusSqlHelper::isWebContinentalFilter($nome)) {
                $temWebContinental = true;
                continue;
            }
            $porChave[strtoupper($nome)] = $nome;
        }
        if ($temWebContinental) {
            $porChave['WEB CONTINENTAL'] = 'WEB CONTINENTAL';
        }

        $nomes = array_values($porChave);
        usort($nomes, static fn (string $a, string $b): int => strcasecmp($a, $b));

        return $nomes;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array{params: array<string, string>, pedidos: list<string>} $ctx
     */
    private function fetchTotal(PDO $pdo, array $schema, array $ctx, bool $somenteErro): int
    {
        $sql = 'SELECT COUNT(1) AS total FROM ' . $this->tblZa4()
            . $this->za4FilterSql($schema, $ctx, $somenteErro);

        $stmt = $pdo->prepare($sql);
        $stmt->execute(ProtheusSqlHelper::paramsForSql($sql, $ctx['params']));
        $row = $stmt->fetch();

        return is_array($row) ? (int) ($row['total'] ?? 0) : 0;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array{params: array<string, string>, pedidos: list<string>} $ctx
     * @return list<array<string, mixed>>
     */
    private function fetchPage(
        PDO $pdo,
        array $schema,
        array $ctx,
        bool $somenteErro,
        int $offset,
        int $limit,
        bool $broadTopOnly = false
    ): array {
        $recno = $schema['recno'];
        $pageSelect = $broadTopOnly
            ? 'SELECT TOP (:limit) ZA4.' . $recno . ' AS recno'
            : 'SELECT ZA4.' . $recno . ' AS recno';
        $pageTail = $broadTopOnly
            ? ''
            : '
    OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY';

        $sql = ';WITH pg AS (
    ' . $pageSelect . '
    FROM ' . $this->tblZa4()
            . $this->za4FilterSql($schema, $ctx, $somenteErro)
            . $this->orderSqlZa4($schema)
            . $pageTail . '
)
' . $this->selectEnrichedSql($schema) . '
INNER JOIN pg ON pg.recno = ZA4.' . $recno;

        $stmt = $pdo->prepare($sql);
        foreach (ProtheusSqlHelper::paramsForSql($sql, $ctx['params']) as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        if (!$broadTopOnly) {
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        }
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
