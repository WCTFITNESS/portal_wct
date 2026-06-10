<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Padroes SQL para consultas Protheus (SQL Server): NOLOCK e filtros comuns.
 */
final class ProtheusSqlHelper
{
    /** Tabela Protheus com hint NOLOCK (leitura sem bloqueio). */
    public static function tbl(string $table, string $alias = ''): string
    {
        if ($alias === '') {
            return $table . ' WITH (NOLOCK)';
        }

        return $table . ' ' . $alias . ' WITH (NOLOCK)';
    }

    /**
     * Condicao AND para filtro de marketplace (evita repetir :marketplace no PDO/ODBC).
     */
    public static function marketplaceAndSql(string $marketplace): string
    {
        $marketplace = trim($marketplace);
        if ($marketplace === '') {
            return '';
        }

        if (self::isWebContinentalFilter($marketplace)) {
            return '
    AND (' . self::webContinentalMatchSql('SC5.C5_ZMAKET') . ')';
        }

        return "
    AND RTRIM(SC5.C5_ZMAKET) = :marketplace";
    }

    public static function isWebContinentalFilter(string $marketplace): bool
    {
        $u = strtoupper(trim($marketplace));
        if ($u === '') {
            return false;
        }

        return $u === 'WEB CONTINENTAL'
            || $u === 'WEBCONTINENTAL'
            || $u === 'WCT'
            || $u === 'WEB'
            || str_contains($u, 'CONTINENTAL')
            || str_starts_with($u, 'WEB ')
            || str_starts_with($u, 'LOJA WEB')
            || str_starts_with($u, 'SITE WCT');
    }

    /** Expressao SQL (sem AND) para reconhecer Web Continental no Protheus. */
    public static function webContinentalMatchSql(string $column = 'SC5.C5_ZMAKET'): string
    {
        $col = 'UPPER(RTRIM(' . $column . '))';

        return "{$col} LIKE '%CONTINENTAL%'
        OR {$col} LIKE '%WEB%CONT%'
        OR {$col} IN ('WEB CONTINENTAL', 'WEBCONTINENTAL', 'WCT', 'WEB', 'LOJA WEB', 'SITE WCT')";
    }

    /**
     * Mantem apenas parametros referenciados no SQL (sqlsrv rejeita binds extras).
     *
     * @param array<string, string> $params
     * @return array<string, string>
     */
    public static function paramsForSql(string $sql, array $params): array
    {
        if (!preg_match_all('/:\w+/', $sql, $matches)) {
            return [];
        }

        $used = array_flip(array_unique($matches[0]));
        $filtered = [];
        foreach ($params as $key => $value) {
            if (isset($used[$key])) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * Variantes de preenchimento (campos char do Protheus) para match exato com indice.
     *
     * @return list<string>
     */
    public static function protheusStoredVariants(string $value): array
    {
        $value = (string) $value;
        $unique = [$value];
        foreach ([15, 20, 25, 30] as $len) {
            if (strlen($value) >= $len) {
                continue;
            }
            $padded = str_pad($value, $len, ' ');
            if (!in_array($padded, $unique, true)) {
                $unique[] = $padded;
            }
        }

        return $unique;
    }

    /**
     * Clausula (col = :bind OR ...) sem funcoes na coluna — permite seek no indice.
     *
     * @param list<string> $values
     * @return array{sql: string, params: array<string, string>}
     */
    public static function exactCharMatchClause(string $column, array $values, string $prefix): array
    {
        $params = [];
        $parts = [];
        $idx = 0;
        foreach (array_values($values) as $value) {
            foreach (self::protheusStoredVariants($value) as $variant) {
                $key = ':' . $prefix . '_' . $idx;
                $params[$key] = $variant;
                $parts[] = $column . ' = ' . $key;
                $idx++;
            }
        }

        if ($parts === []) {
            return ['sql' => '1 = 0', 'params' => []];
        }

        return ['sql' => '(' . implode(' OR ', $parts) . ')', 'params' => $params];
    }

    /**
     * Variantes de padding para pedidos marketplace (campos char maiores que 30).
     *
     * @return list<string>
     */
    public static function protheusPedidoVariants(string $value): array
    {
        $variants = self::protheusStoredVariants($value);
        foreach ([35, 40, 50] as $len) {
            if (strlen($value) >= $len) {
                continue;
            }
            $padded = str_pad($value, $len, ' ');
            if (!in_array($padded, $variants, true)) {
                $variants[] = $padded;
            }
        }

        return $variants;
    }

    /**
     * Match de pedido marketplace: igualdade com padding + RTRIM (como o Protheus grava).
     *
     * @param list<string> $values
     * @return array{sql: string, params: array<string, string>}
     */
    public static function pedidoMarketplaceMatchClause(string $column, array $values, string $prefix): array
    {
        $params = [];
        $exactParts = [];
        $idx = 0;
        foreach (array_values($values) as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            foreach (self::protheusPedidoVariants($value) as $variant) {
                $key = ':' . $prefix . '_e' . $idx;
                $params[$key] = $variant;
                $exactParts[] = $column . ' = ' . $key;
                $idx++;
            }
        }

        $rtrim = self::batchRtrimInClause($column, $values, $prefix . '_r');
        $parts = [];
        if ($exactParts !== []) {
            $parts[] = '(' . implode(' OR ', $exactParts) . ')';
        }
        if ($rtrim['sql'] !== '1 = 0') {
            $parts[] = $rtrim['sql'];
        }

        if ($parts === []) {
            return ['sql' => '1 = 0', 'params' => []];
        }

        return [
            'sql' => '(' . implode(' OR ', $parts) . ')',
            'params' => array_merge($params, $rtrim['params']),
        ];
    }

    /**
     * Match enxuto para poucos pedidos (evita dezenas de OR que estouram timeout no SQL Server).
     *
     * @param list<string> $values
     * @return array{sql: string, params: array<string, string>}
     */
    public static function pedidoMarketplaceMatchFast(string $column, array $values, string $prefix): array
    {
        $parts = [];
        $params = [];
        $i = 0;
        foreach (array_values($values) as $value) {
            $trim = trim((string) $value);
            if ($trim === '') {
                continue;
            }
            $keyTrim = ':' . $prefix . '_t' . $i;
            $params[$keyTrim] = $trim;
            $parts[] = 'RTRIM(' . $column . ') = ' . $keyTrim;
            foreach ([15, 20, 25, 30] as $len) {
                if (strlen($trim) >= $len) {
                    continue;
                }
                $keyPad = ':' . $prefix . '_p' . $i . '_' . $len;
                $params[$keyPad] = str_pad($trim, $len, ' ');
                $parts[] = $column . ' = ' . $keyPad;
            }
            $i++;
        }

        if ($parts === []) {
            return ['sql' => '1 = 0', 'params' => []];
        }

        return ['sql' => '(' . implode(' OR ', $parts) . ')', 'params' => $params];
    }

    /**
     * IN com RTRIM na coluna — um bind por valor (lotes de pedido/nota, sem padding extra).
     *
     * @param list<string> $values
     * @return array{sql: string, params: array<string, string>}
     */
    public static function batchRtrimInClause(string $column, array $values, string $prefix): array
    {
        $params = [];
        $placeholders = [];
        foreach (array_values($values) as $i => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $key = ':' . $prefix . '_' . $i;
            $params[$key] = $value;
            $placeholders[] = $key;
        }

        if ($placeholders === []) {
            return ['sql' => '1 = 0', 'params' => []];
        }

        return [
            'sql' => 'RTRIM(' . $column . ') IN (' . implode(', ', $placeholders) . ')',
            'params' => $params,
        ];
    }

    /**
     * @param array<string, string> $params
     * @return array<string, string>
     */
    public static function paramsSemMarketplaceSeLike(array $params): array
    {
        if (!isset($params[':marketplace'])) {
            return $params;
        }
        if (self::isWebContinentalFilter($params[':marketplace'])) {
            unset($params[':marketplace']);
        }

        return $params;
    }

    /**
     * Itens solicitados no filtro em lote que nao apareceram no resultado.
     *
     * @param list<string> $requested
     * @param list<string> $found
     * @return list<string>
     */
    public static function missingFromBatch(array $requested, array $found): array
    {
        if ($requested === []) {
            return [];
        }

        $foundKeys = [];
        foreach ($found as $value) {
            $key = strtoupper(trim($value));
            if ($key !== '') {
                $foundKeys[$key] = true;
            }
        }

        $missing = [];
        foreach ($requested as $item) {
            $key = strtoupper(trim($item));
            if ($key !== '' && !isset($foundKeys[$key])) {
                $missing[] = $item;
            }
        }

        return $missing;
    }

    /**
     * Linhas da aba "Nao encontrados" na exportacao Excel.
     *
     * @param list<string> $missingDocs
     * @param list<string> $missingPedidos
     * @param list<string> $missingCpfs
     * @return list<array{tipo: string, valor: string}>
     */
    public static function missingExportEntries(
        array $missingDocs,
        array $missingPedidos,
        array $missingCpfs = [],
        ?callable $formatCpf = null
    ): array {
        $entries = [];
        foreach ($missingDocs as $value) {
            $entries[] = ['tipo' => 'Nota', 'valor' => $value];
        }
        foreach ($missingPedidos as $value) {
            $entries[] = ['tipo' => 'Ped. marketplace', 'valor' => $value];
        }
        foreach ($missingCpfs as $value) {
            $entries[] = [
                'tipo' => 'CPF/CNPJ',
                'valor' => $formatCpf !== null ? $formatCpf($value) : $value,
            ];
        }

        return $entries;
    }
}
