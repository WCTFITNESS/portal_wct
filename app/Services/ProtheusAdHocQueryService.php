<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Shuchkin\SimpleXLSXGen;

/**
 * Consultas SELECT ad-hoc no Protheus (tabela + WHERE) com NOLOCK e cancelamento.
 */
class ProtheusAdHocQueryService
{
    /** Marcador no historico para consultas coladas (SQL completo). */
    public const RAW_QUERY_MARKER = '__RAW__';

    /** Marcador no historico / colunas para modo contagem no montador. */
    public const COUNT_COLUMNS_MARKER = '__COUNT__';

    public const DEFAULT_TOP = 200;

    public const MAX_TOP = 2000;

    public const MAX_RAW_SQL_LENGTH = 32000;

    public const MAX_TABLES_LIST = 500;

    public const MAX_COLUMNS_LIST = 500;

    private const META_TTL_SECONDS = 3600;

    public function __construct(
        private ProtheusConnectionService $connectionService
    ) {
    }

    /**
     * @return array{
     *   ok: bool,
     *   query_id: string,
     *   sql: string,
     *   columns: list<string>,
     *   rows: list<array<string, mixed>>,
     *   row_count: int,
     *   elapsed_ms: int,
     *   truncated: bool
     * }
     */
    public function runQuery(
        string $table,
        string $where,
        string $columns = '*',
        int $top = self::DEFAULT_TOP,
        ?string $queryId = null,
        string $orderBy = '',
        bool $countOnly = false
    ): array {
        $tableSql = $this->validateTable($table);
        $whereSql = $this->validateWhereForTable($where, $tableSql);
        $orderBySql = $this->validateOrderByForTable($orderBy, $tableSql);
        $countOnly = $countOnly || strtoupper(trim($columns)) === self::COUNT_COLUMNS_MARKER;

        if ($countOnly) {
            $columnsSql = self::COUNT_COLUMNS_MARKER;
            $top = 1;
        } else {
            $columnsSql = $this->validateColumnsForTable($columns, $tableSql);
            $top = max(1, min(self::MAX_TOP, $top));
        }

        $queryId = $this->normalizeQueryId($queryId ?? $this->newQueryId());
        $sql = $countOnly
            ? $this->buildCountSql($tableSql, $whereSql, $orderBySql)
            : $this->buildSelectSql($tableSql, $columnsSql, $whereSql, $orderBySql, $top);

        $started = microtime(true);
        $pdo = $this->connectionService->connect();
        $this->applyQueryTimeout($pdo);

        $spid = $this->currentSpid($pdo);
        $this->saveQueryMeta($queryId, $spid);

        try {
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new RuntimeException($this->formatSqlServerError($e, $sql), (int) $e->getCode(), $e);
        } finally {
            $this->clearQueryMeta($queryId);
        }

        $elapsedMs = (int) round((microtime(true) - $started) * 1000);
        $columnNames = $rows !== [] ? array_keys($rows[0]) : $this->inferColumnsFromSql($columnsSql);

        return [
            'ok' => true,
            'query_id' => $queryId,
            'sql' => $sql,
            'columns' => $columnNames,
            'rows' => $this->normalizeRows($rows),
            'row_count' => count($rows),
            'elapsed_ms' => $elapsedMs,
            'truncated' => !$countOnly && count($rows) >= $top,
        ];
    }

    /**
     * @return array{ok: true, tables: list<string>, total: int}
     */
    public function listTables(?string $search = null, int $limit = self::MAX_TABLES_LIST): array
    {
        $limit = max(1, min(self::MAX_TABLES_LIST, $limit));
        $search = $this->normalizeTableSearch($search);

        $pdo = $this->connectionService->connect();
        $this->applyQueryTimeout($pdo);

        if ($search !== '') {
            $like = '%' . $search . '%';
            $stmt = $pdo->prepare(
                'SELECT TOP (' . $limit . ') TABLE_NAME AS name
                FROM INFORMATION_SCHEMA.TABLES WITH (NOLOCK)
                WHERE TABLE_TYPE = \'BASE TABLE\'
                  AND TABLE_NAME LIKE ?
                ORDER BY TABLE_NAME'
            );
            $stmt->execute([$like]);
        } else {
            $stmt = $pdo->query(
                'SELECT TOP (' . $limit . ') TABLE_NAME AS name
                FROM INFORMATION_SCHEMA.TABLES WITH (NOLOCK)
                WHERE TABLE_TYPE = \'BASE TABLE\'
                ORDER BY TABLE_NAME'
            );
        }

        $tables = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '') {
                $tables[] = $name;
            }
        }

        return [
            'ok' => true,
            'tables' => $tables,
            'total' => count($tables),
        ];
    }

    /**
     * @return array{ok: true, table: string, columns: list<array{name: string, data_type: string, max_length: int|null}>}
     */
    public function listTableColumns(string $table): array
    {
        $tableSql = $this->validateTable($table);

        $pdo = $this->connectionService->connect();
        $this->applyQueryTimeout($pdo);

        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME AS name,
                    DATA_TYPE AS data_type,
                    CHARACTER_MAXIMUM_LENGTH AS max_length
             FROM INFORMATION_SCHEMA.COLUMNS WITH (NOLOCK)
             WHERE TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute([$tableSql]);

        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $maxLen = $row['max_length'] ?? null;
            $columns[] = [
                'name' => $name,
                'data_type' => (string) ($row['data_type'] ?? ''),
                'max_length' => $maxLen === null ? null : (int) $maxLen,
            ];
        }

        if ($columns === []) {
            throw new RuntimeException('Tabela nao encontrada ou sem colunas: ' . $tableSql);
        }

        return [
            'ok' => true,
            'table' => $tableSql,
            'columns' => $columns,
        ];
    }

    /**
     * Reexecuta a consulta e gera Excel com coluna # e valores completos.
     */
    public function exportToXlsx(
        string $table,
        string $where,
        string $columns = '*',
        int $top = self::DEFAULT_TOP,
        string $orderBy = '',
        bool $countOnly = false
    ): string {
        $result = $this->runQuery($table, $where, $columns, $top, null, $orderBy, $countOnly);
        $cols = $result['columns'];
        $rows = $result['rows'];

        $header = array_merge(['#'], $cols);
        $sheet = [$header];

        foreach ($rows as $index => $row) {
            $line = [(string) ($index + 1)];
            foreach ($cols as $col) {
                $line[] = (string) ($row[$col] ?? '');
            }
            $sheet[] = $line;
        }

        $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $this->validateTable($table)) ?: 'tabela';
        $fileName = sprintf('consulta_sql_%s_%s.xlsx', $safeTable, date('Ymd_His'));
        $fullPath = $this->exportDirectory() . DIRECTORY_SEPARATOR . $fileName;

        require_once __DIR__ . '/../Lib/SimpleXLSXGen.php';
        $xlsx = SimpleXLSXGen::fromArray($sheet, 'Consulta SQL');
        if (!$xlsx->saveAs($fullPath)) {
            throw new RuntimeException('Nao foi possivel gravar o arquivo de exportacao.');
        }

        return $fullPath;
    }

    /**
     * Executa SELECT completo enviado pelo consultor (somente leitura).
     *
     * @return array{
     *   ok: bool,
     *   query_id: string,
     *   sql: string,
     *   columns: list<string>,
     *   rows: list<array<string, mixed>>,
     *   row_count: int,
     *   elapsed_ms: int,
     *   truncated: bool
     * }
     */
    public function runRawQuery(string $sql, ?string $queryId = null): array
    {
        $sql = $this->validateRawSql($sql);
        $queryId = $this->normalizeQueryId($queryId ?? $this->newQueryId());

        $started = microtime(true);
        $pdo = $this->connectionService->connect();
        $this->applyQueryTimeout($pdo);

        $spid = $this->currentSpid($pdo);
        $this->saveQueryMeta($queryId, $spid);

        try {
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new RuntimeException($this->formatSqlServerError($e, $sql), (int) $e->getCode(), $e);
        } finally {
            $this->clearQueryMeta($queryId);
        }

        $elapsedMs = (int) round((microtime(true) - $started) * 1000);
        $columnNames = $rows !== [] ? array_keys($rows[0]) : [];
        $topInSql = $this->extractTopLimit($sql);

        return [
            'ok' => true,
            'query_id' => $queryId,
            'sql' => $sql,
            'columns' => $columnNames,
            'rows' => $this->normalizeRows($rows),
            'row_count' => count($rows),
            'elapsed_ms' => $elapsedMs,
            'truncated' => $topInSql > 0 && count($rows) >= $topInSql,
        ];
    }

    public function exportRawToXlsx(string $sql): string
    {
        $result = $this->runRawQuery($sql);
        $cols = $result['columns'];
        $rows = $result['rows'];

        $header = array_merge(['#'], $cols);
        $sheet = [$header];

        foreach ($rows as $index => $row) {
            $line = [(string) ($index + 1)];
            foreach ($cols as $col) {
                $line[] = (string) ($row[$col] ?? '');
            }
            $sheet[] = $line;
        }

        $fileName = sprintf('consulta_sql_pronta_%s.xlsx', date('Ymd_His'));
        $fullPath = $this->exportDirectory() . DIRECTORY_SEPARATOR . $fileName;

        require_once __DIR__ . '/../Lib/SimpleXLSXGen.php';
        $xlsx = SimpleXLSXGen::fromArray($sheet, 'Query pronta');
        if (!$xlsx->saveAs($fullPath)) {
            throw new RuntimeException('Nao foi possivel gravar o arquivo de exportacao.');
        }

        return $fullPath;
    }

    public function cancelQuery(string $queryId): array
    {
        $queryId = $this->normalizeQueryId($queryId);
        $meta = $this->loadQueryMeta($queryId);

        if ($meta === null) {
            return [
                'ok' => false,
                'error' => 'Nenhuma consulta em execucao para este ID (ja terminou ou expirou).',
            ];
        }

        $spid = (int) ($meta['spid'] ?? 0);
        if ($spid <= 0) {
            $this->clearQueryMeta($queryId);

            return ['ok' => false, 'error' => 'SPID invalido para cancelamento.'];
        }

        try {
            $pdo = $this->connectionService->connect();
            $pdo->exec('KILL ' . $spid);
            $this->clearQueryMeta($queryId);

            return ['ok' => true, 'message' => 'Comando KILL enviado ao SQL Server (SPID ' . $spid . ').'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function validateTable(string $table): string
    {
        $table = strtoupper(trim($table));
        if ($table === '' || !preg_match('/^[A-Z0-9_]+$/', $table)) {
            throw new RuntimeException('Nome de tabela invalido. Use apenas letras, numeros e underscore (ex.: ZA4010).');
        }

        return $table;
    }

    public function validateOrderBy(string $orderBy): string
    {
        $orderBy = $this->normalizeOrderByInput($orderBy);
        if ($orderBy === '') {
            return '';
        }

        if (strlen($orderBy) > 500) {
            throw new RuntimeException('Clausula ORDER BY muito longa (max. 500 caracteres).');
        }

        if (str_contains($orderBy, ';')) {
            throw new RuntimeException('Nao e permitido ponto e virgula (;) no ORDER BY.');
        }

        if (!preg_match('/^[A-Za-z0-9_,.\s]+$/', $orderBy)) {
            throw new RuntimeException('ORDER BY invalido. Use nomes de colunas, virgula, ASC ou DESC.');
        }

        $upper = ' ' . strtoupper($orderBy) . ' ';
        $blocked = [
            ' INSERT ', ' UPDATE ', ' DELETE ', ' DROP ', ' ALTER ', ' CREATE ', ' TRUNCATE ',
            ' MERGE ', ' EXEC ', ' EXECUTE ', ' SELECT ', ' FROM ', ' WHERE ', ' JOIN ',
            ' UNION ', ' HAVING ', ' GROUP ', ' OPENROWSET ', ' OPENDATASOURCE ', ' KILL ',
            ' -- ', '/*', '*/', ' OFFSET ', ' FETCH ', ' TOP ', ' INTO ',
        ];
        foreach ($blocked as $token) {
            if (str_contains($upper, $token)) {
                throw new RuntimeException('Expressao nao permitida no ORDER BY.');
            }
        }

        return $orderBy;
    }

    public function validateOrderByForTable(string $orderBy, string $table): string
    {
        $orderBySql = $this->validateOrderBy($orderBy);
        if ($orderBySql === '') {
            return '';
        }

        $allowed = array_map('strtoupper', $this->fetchColumnNames($table));
        $allowedFlip = array_flip($allowed);

        foreach (array_map('trim', explode(',', $orderBySql)) as $part) {
            if ($part === '') {
                continue;
            }

            $col = $part;
            if (preg_match('/^(.+?)\s+(ASC|DESC)$/i', $part, $m)) {
                $col = trim($m[1]);
            } elseif (preg_match('/^(ASC|DESC)$/i', $part, $m)) {
                throw new RuntimeException(
                    'Informe a coluna antes de ' . strtoupper($m[1]) . ' (ex.: B1_COD ' . strtoupper($m[1]) . '). '
                    . 'Nao digite "ORDER BY" no campo — o sistema adiciona automaticamente.'
                );
            }

            if ($col === '') {
                throw new RuntimeException(
                    'ORDER BY invalido. Use o nome da coluna e opcionalmente ASC ou DESC (ex.: B1_COD DESC).'
                );
            }

            if (str_contains($col, '.')) {
                $pieces = explode('.', $col);
                $col = trim((string) end($pieces));
            }

            if (preg_match('/^ORDER$/i', $col) || preg_match('/^BY$/i', $col)) {
                throw new RuntimeException(
                    'Nao digite "ORDER BY" no campo. Informe apenas colunas (ex.: B1_DESC DESC).'
                );
            }

            if (!isset($allowedFlip[strtoupper($col)])) {
                throw new RuntimeException('Coluna ORDER BY nao existe na tabela ' . $table . ': ' . $col);
            }
        }

        return $orderBySql;
    }

    /** Remove "ORDER BY" do inicio — o SQL ja inclui essa clausula. */
    public function normalizeOrderByInput(string $orderBy): string
    {
        $orderBy = trim($orderBy);
        if ($orderBy === '') {
            return '';
        }

        if (preg_match('/^ORDER\s+BY\s+/i', $orderBy)) {
            $orderBy = preg_replace('/^ORDER\s+BY\s+/i', '', $orderBy) ?? $orderBy;
        }

        return trim($orderBy);
    }

    public function validateWhere(string $where): string
    {
        $where = trim($where);
        if ($where === '') {
            return '1=1';
        }

        if (strlen($where) > 4000) {
            throw new RuntimeException('Clausula WHERE muito longa (max. 4000 caracteres).');
        }

        if (str_contains($where, ';')) {
            throw new RuntimeException('Nao e permitido ponto e virgula (;) na clausula WHERE.');
        }

        $upper = strtoupper($where);
        $blocked = [
            ' INSERT ', ' UPDATE ', ' DELETE ', ' DROP ', ' ALTER ', ' CREATE ', ' TRUNCATE ',
            ' MERGE ', ' EXEC ', ' EXECUTE ', ' GRANT ', ' REVOKE ', ' DENY ',
            ' OPENROWSET ', ' OPENDATASOURCE ', ' BULK ', ' BACKUP ', ' RESTORE ',
            ' SHUTDOWN ', ' RECONFIGURE ', ' DBCC ', ' KILL ', ' WAITFOR ',
            ' INTO ', ' OUTFILE ', ' LOAD_FILE ',
        ];

        foreach ($blocked as $token) {
            if (str_contains($upper, $token)) {
                throw new RuntimeException('Palavra ou comando nao permitido na clausula WHERE: ' . trim($token) . '.');
            }
        }

        if (str_contains($where, '--') || str_contains($where, '/*') || str_contains($where, '*/')) {
            throw new RuntimeException('Comentarios SQL nao sao permitidos na clausula WHERE.');
        }

        if (preg_match('/\bUNION\b/i', $where)) {
            throw new RuntimeException('UNION nao e permitido na clausula WHERE.');
        }

        return $where;
    }

    /**
     * Valida WHERE contra tipos das colunas (Protheus: muitos "numericos" sao varchar).
     */
    public function validateWhereForTable(string $where, string $table): string
    {
        $where = $this->validateWhere($where);
        if ($where === '1=1') {
            return $where;
        }

        $types = $this->fetchColumnTypes($table);

        if (preg_match_all(
            '/([A-Za-z0-9_\.]+)\s*(?:=|<>|!=|>|<|>=|<=)\s*N?\'([^\']*)\'/i',
            $where,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $colKey = $this->resolveWhereColumnKey($m[1], $types);
                if ($colKey === null) {
                    continue;
                }
                $dtype = $types[$colKey];
                if (!$this->isNumericSqlType($dtype)) {
                    continue;
                }
                $val = (string) $m[2];
                if (!$this->isNumericLiteral($val)) {
                    throw new RuntimeException($this->numericWhereMismatchMessage($colKey, $dtype, $val));
                }
            }
        }

        if (preg_match_all(
            '/([A-Za-z0-9_\.]+)\s*(?:=|<>|!=|>|<|>=|<=)\s*(\d+(?:\.\d+)?)\b/i',
            $where,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $colKey = $this->resolveWhereColumnKey($m[1], $types);
                if ($colKey === null) {
                    continue;
                }
                $dtype = $types[$colKey];
                if (!$this->isStringSqlType($dtype)) {
                    continue;
                }
                throw new RuntimeException($this->stringWhereNumericLiteralMessage($colKey, $dtype, (string) $m[2]));
            }
        }

        if (preg_match_all(
            '/([A-Za-z0-9_\.]+)\s+IN\s*\(([^)]+)\)/i',
            $where,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $colKey = $this->resolveWhereColumnKey($m[1], $types);
                if ($colKey === null) {
                    continue;
                }
                $dtype = $types[$colKey];
                foreach ($this->splitSqlInList($m[2]) as $item) {
                    if (preg_match('/^N?\'([^\']*)\'$/i', $item, $qm)) {
                        $val = (string) $qm[1];
                        if ($this->isNumericSqlType($dtype) && !$this->isNumericLiteral($val)) {
                            throw new RuntimeException($this->numericWhereMismatchMessage($colKey, $dtype, $val));
                        }
                        continue;
                    }
                    if (preg_match('/^-?\d+(\.\d+)?$/', $item) && $this->isStringSqlType($dtype)) {
                        throw new RuntimeException(
                            'No IN (' . $item . '), use aspas para o campo texto '
                            . $colKey . ' (ex.: IN (\'1\', \'3\')).'
                        );
                    }
                    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $item) && $this->isNumericSqlType($dtype)) {
                        throw new RuntimeException(
                            'No IN (' . $item . '), use numeros sem aspas para o campo numerico '
                            . $colKey . ' (ex.: IN (1, 2, 3)).'
                        );
                    }
                    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $item) && $this->isStringSqlType($dtype)) {
                        throw new RuntimeException(
                            'No IN (' . $item . '), use aspas para o campo texto '
                            . $colKey . ' (ex.: IN (\'A\', \'I\')).'
                        );
                    }
                }
            }
        }

        return $where;
    }

    public function validateColumns(string $columns): string
    {
        $columns = trim($columns);
        if ($columns === '' || $columns === '*') {
            return '*';
        }

        if (strlen($columns) > 4000) {
            throw new RuntimeException('Lista de colunas muito longa.');
        }

        if (!preg_match('/^[A-Za-z0-9_,\s]+$/', $columns)) {
            throw new RuntimeException('Colunas invalidas. Use nomes separados por virgula ou *.');
        }

        $upper = strtoupper($columns);
        foreach ([';', '--', '/*', '*/', ' FROM ', ' WHERE ', ' SELECT '] as $token) {
            if (str_contains($upper, $token)) {
                throw new RuntimeException('Expressao de colunas nao permitida.');
            }
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $columns))));
        foreach ($parts as $part) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $part)) {
                throw new RuntimeException('Nome de coluna invalido: ' . $part);
            }
        }

        return implode(', ', $parts);
    }

    public function validateColumnsForTable(string $columns, string $table): string
    {
        $columnsSql = $this->validateColumns($columns);
        if ($columnsSql === '*') {
            return '*';
        }

        $allowed = $this->fetchColumnNames($table);
        $allowedUpper = array_flip(array_map('strtoupper', $allowed));

        foreach (array_map('trim', explode(',', $columnsSql)) as $col) {
            if ($col === '') {
                continue;
            }
            if (!isset($allowedUpper[strtoupper($col)])) {
                throw new RuntimeException('Coluna nao existe na tabela ' . $table . ': ' . $col);
            }
        }

        return $columnsSql;
    }

    private function normalizeTableSearch(?string $search): string
    {
        $search = strtoupper(trim((string) $search));
        if ($search === '') {
            return '';
        }

        if (strlen($search) > 64) {
            throw new RuntimeException('Filtro de tabela muito longo (max. 64 caracteres).');
        }

        if (!preg_match('/^[A-Z0-9_%]+$/', $search)) {
            throw new RuntimeException('Filtro de tabela invalido. Use letras, numeros, _ ou %.');
        }

        return str_replace(['%', '_'], ['[%]', '[_]'], $search);
    }

    /**
     * @return list<string>
     */
    private function fetchColumnNames(string $table): array
    {
        $result = $this->listTableColumns($table);

        return array_map(
            static fn (array $col): string => (string) $col['name'],
            $result['columns']
        );
    }

    /**
     * @return array<string, string> coluna (upper) => data_type
     */
    private function fetchColumnTypes(string $table): array
    {
        $result = $this->listTableColumns($table);
        $types = [];
        foreach ($result['columns'] as $col) {
            $name = strtoupper(trim((string) ($col['name'] ?? '')));
            if ($name !== '') {
                $types[$name] = strtolower((string) ($col['data_type'] ?? ''));
            }
        }

        return $types;
    }

    /**
     * @param array<string, string> $types
     */
    private function resolveWhereColumnKey(string $expr, array $types): ?string
    {
        $expr = trim($expr);
        if (str_contains($expr, '.')) {
            $parts = explode('.', $expr);
            $expr = trim((string) end($parts));
        }
        $key = strtoupper($expr);

        return isset($types[$key]) ? $key : null;
    }

    private function isNumericSqlType(string $dataType): bool
    {
        return in_array($dataType, [
            'int', 'bigint', 'smallint', 'tinyint', 'bit',
            'decimal', 'numeric', 'float', 'real', 'money', 'smallmoney',
        ], true);
    }

    private function isStringSqlType(string $dataType): bool
    {
        return in_array($dataType, [
            'varchar', 'char', 'nchar', 'nvarchar', 'text', 'ntext',
        ], true);
    }

    private function isNumericLiteral(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        return (bool) preg_match('/^-?\d+(\.\d+)?$/', $value);
    }

    private function numericWhereMismatchMessage(string $col, string $dtype, string $value): string
    {
        return sprintf(
            'O campo %s é numérico (%s), mas o WHERE usa o texto \'%s\'. '
            . 'Use número sem aspas (ex.: %s = 3) ou escolha outra coluna de texto.',
            $col,
            $dtype,
            $value,
            $col
        );
    }

    private function stringWhereNumericLiteralMessage(string $col, string $dtype, string $value): string
    {
        return sprintf(
            'O campo %s é texto (%s) no Protheus e pode conter letras (ex.: \'I\'). '
            . 'Use aspas no valor (ex.: %s = \'%s\'), nao %s = %s sem aspas.',
            $col,
            $dtype,
            $col,
            $value,
            $col,
            $value
        );
    }

    /**
     * @return list<string>
     */
    private function splitSqlInList(string $list): array
    {
        $items = [];
        $current = '';
        $inQuote = false;
        $len = strlen($list);

        for ($i = 0; $i < $len; $i++) {
            $ch = $list[$i];
            if ($ch === "'" && ($i === 0 || $list[$i - 1] !== '\\')) {
                $inQuote = !$inQuote;
                $current .= $ch;
                continue;
            }
            if ($ch === ',' && !$inQuote) {
                $items[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $ch;
        }

        if (trim($current) !== '') {
            $items[] = trim($current);
        }

        return $items;
    }

    private function formatSqlServerError(\PDOException $e, string $sql): string
    {
        $msg = $e->getMessage();
        if (str_contains($msg, '22018') || stripos($msg, 'Conversion failed') !== false) {
            $hint = ' Conversao de tipo no SQL Server: em campos numericos use numeros sem aspas '
                . '(ex.: ZA4_STATUS = 3). Em campos texto/filial use aspas (ex.: ZA4_FILIAL = \'0101\').';
            if (preg_match("/varchar value '([^']*)' to data type int/i", $msg, $m)) {
                $hint = sprintf(
                    ' A coluna tem texto (ex.: \'%s\') mas o WHERE comparou com numero sem aspas. '
                    . 'No Protheus use aspas: ZA4_STATUS = \'3\' (nao ZA4_STATUS = 3).',
                    $m[1]
                );
            }

            return trim($msg) . $hint;
        }

        return $msg;
    }

    public function newQueryId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function applyQueryTimeout(PDO $pdo): void
    {
        if (defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) {
            $pdo->setAttribute(constant('PDO::SQLSRV_ATTR_QUERY_TIMEOUT'), 300);
        }
    }

    private function currentSpid(PDO $pdo): int
    {
        $row = $pdo->query('SELECT @@SPID AS spid')->fetch(PDO::FETCH_ASSOC);

        return (int) ($row['spid'] ?? 0);
    }

    private function normalizeQueryId(string $queryId): string
    {
        $queryId = strtolower(trim($queryId));
        if ($queryId === '' || !preg_match('/^[a-f0-9]{32}$/', $queryId)) {
            throw new RuntimeException('ID de consulta invalido.');
        }

        return $queryId;
    }

    private function metaPath(string $queryId): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ml_portal_protheus_sql_' . $queryId . '.json';
    }

    private function saveQueryMeta(string $queryId, int $spid): void
    {
        $payload = [
            'query_id' => $queryId,
            'spid' => $spid,
            'pid' => getmypid(),
            'started_at' => time(),
        ];

        $path = $this->metaPath($queryId);
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR), LOCK_EX);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadQueryMeta(string $queryId): ?array
    {
        $path = $this->metaPath($queryId);
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        $started = (int) ($data['started_at'] ?? 0);
        if ($started > 0 && (time() - $started) > self::META_TTL_SECONDS) {
            @unlink($path);

            return null;
        }

        return $data;
    }

    private function clearQueryMeta(string $queryId): void
    {
        $path = $this->metaPath($queryId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, string>>
     */
    private function normalizeRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($row as $key => $value) {
                if ($value === null) {
                    $line[(string) $key] = '';
                } elseif (is_string($value) || is_numeric($value)) {
                    $line[(string) $key] = (string) $value;
                } else {
                    $line[(string) $key] = json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
                }
            }
            $out[] = $line;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function inferColumnsFromSql(string $columnsSql): array
    {
        if ($columnsSql === '*') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $c): string => trim($c),
            explode(',', $columnsSql)
        )));
    }

    public function validateRawSql(string $sql): string
    {
        $sql = trim($sql);
        if ($sql === '') {
            throw new RuntimeException('Cole a query SQL no campo Query pronta.');
        }

        if (strlen($sql) > self::MAX_RAW_SQL_LENGTH) {
            throw new RuntimeException('Query muito longa (max. ' . self::MAX_RAW_SQL_LENGTH . ' caracteres).');
        }

        if (str_contains($sql, ';')) {
            throw new RuntimeException('Nao e permitido ponto e virgula (;). Use apenas um SELECT.');
        }

        $normalized = $this->stripSqlComments($sql);
        $upper = ' ' . strtoupper($normalized) . ' ';

        if (!preg_match('/^\s*(WITH\s+[\s\S]+?\s+)?SELECT\b/i', $normalized)) {
            throw new RuntimeException('Apenas consultas SELECT sao permitidas (opcionalmente com WITH ... SELECT).');
        }

        $blocked = [
            ' INSERT ', ' UPDATE ', ' DELETE ', ' DROP ', ' ALTER ', ' CREATE ', ' TRUNCATE ',
            ' MERGE ', ' EXEC ', ' EXECUTE ', ' GRANT ', ' REVOKE ', ' DENY ',
            ' OPENROWSET ', ' OPENDATASOURCE ', ' BULK ', ' BACKUP ', ' RESTORE ',
            ' SHUTDOWN ', ' RECONFIGURE ', ' DBCC ', ' KILL ', ' WAITFOR ', ' XP_',
            ' SP_', ' INTO ', ' OUTFILE ',
        ];
        foreach ($blocked as $token) {
            if (str_contains($upper, $token)) {
                throw new RuntimeException('Comando nao permitido na query: ' . trim($token) . '.');
            }
        }

        if (
            !preg_match('/\bTOP\s*(\(|\s+\d)/i', $normalized)
            && !$this->isAggregateOnlySelect($normalized)
        ) {
            if (preg_match('/\bSELECT\b/i', $sql, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1] + strlen($m[0][0]);
                $sql = substr($sql, 0, $pos) . ' TOP (' . self::MAX_TOP . ')' . substr($sql, $pos);
            }
        }

        return trim($sql);
    }

    /**
     * SELECT com unico agregado (COUNT, SUM, etc.) nao recebe TOP automatico.
     */
    private function isAggregateOnlySelect(string $normalized): bool
    {
        if (!preg_match('/^\s*(WITH\s+[\s\S]+?\s+)?SELECT\b/i', $normalized, $selectMatch, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        $afterSelect = $selectMatch[0][1] + strlen($selectMatch[0][0]);
        if (!preg_match('/\bFROM\b/i', $normalized, $fromMatch, PREG_OFFSET_CAPTURE, $afterSelect)) {
            return false;
        }

        $selectList = substr($normalized, $afterSelect, $fromMatch[0][1] - $afterSelect);
        $selectList = preg_replace('/^\s*TOP\s*(\(\s*\d+\s*\)|\s+\d+)\s*/i', '', $selectList) ?? $selectList;
        $selectList = trim($selectList);

        return (bool) preg_match('/^(DISTINCT\s+)?(COUNT|SUM|AVG|MIN|MAX)\s*\(/i', $selectList);
    }

    private function stripSqlComments(string $sql): string
    {
        $sql = preg_replace('/--[^\r\n]*/', '', $sql) ?? $sql;
        $sql = preg_replace('/\/\*[\s\S]*?\*\//', '', $sql) ?? $sql;

        return trim($sql);
    }

    private function extractTopLimit(string $sql): int
    {
        if (preg_match('/\bTOP\s*\(\s*(\d+)\s*\)/i', $sql, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/\bTOP\s+(\d+)\b/i', $sql, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    private function buildSelectSql(
        string $tableSql,
        string $columnsSql,
        string $whereSql,
        string $orderBySql,
        int $top
    ): string {
        $sql = 'SELECT TOP (' . $top . ') ' . $columnsSql . '
FROM ' . ProtheusSqlHelper::tbl($tableSql) . '
WHERE ' . $whereSql;
        if ($orderBySql !== '') {
            $sql .= '
ORDER BY ' . $orderBySql;
        }

        return $sql;
    }

    private function buildCountSql(string $tableSql, string $whereSql, string $orderBySql): string
    {
        $sql = 'SELECT COUNT(1) AS total
FROM ' . ProtheusSqlHelper::tbl($tableSql) . '
WHERE ' . $whereSql;
        if ($orderBySql !== '') {
            $sql .= '
ORDER BY ' . $orderBySql;
        }

        return $sql;
    }

    private function exportDirectory(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ml-portal-protheus';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Nao foi possivel criar pasta temporaria para exportacao.');
        }

        return $dir;
    }
}
