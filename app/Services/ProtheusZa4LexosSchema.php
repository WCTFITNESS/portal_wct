<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Resolve colunas e joins ZA4/ZA5/SC5 para integracao Lexos (nomes variam por ambiente).
 */
final class ProtheusZa4LexosSchema
{
    /**
     * Schema do monitor de erros: ZA4 (pedido/erro), SC5 (Lexos/ped. marketplace), ZA5 opcional (cliente).
     *
     * @return array{
     *   idlexo4: ?string,
     *   idlexo_expr: string,
     *   pedmar4: ?string,
     *   sc5_join_sql: string,
     *   sc5_match_cond: string,
     *   sc5_apply_sql: string,
     *   za5_apply_sql: string,
     *   has_sc5: bool,
     *   has_za5: bool,
     *   client_nome: ?string,
     *   client_cgc: ?string,
     *   notes: list<string>
     * }
     */
    public static function resolvePedidosErro(PDO $pdo): array
    {
        $notes = [];
        $za4Cols = self::tableColumns($pdo, 'ZA4010');
        $hasSc5 = self::tableExists($pdo, 'SC5010');
        $hasZa5 = self::tableExists($pdo, 'ZA5010');

        $idlexo4 = self::pickLexosIdColumn($za4Cols, 'ZA4');
        $pedmar4 = self::pickColumn($za4Cols, ['ZA4_PEDMAR', 'ZA4_PEDMK', 'ZA4_PEDMARKE', 'ZA4_PEDIDO']);

        $idlexoParts = [];
        if ($idlexo4 !== null) {
            $idlexoParts[] = "NULLIF(RTRIM(ZA4.{$idlexo4}), '')";
        }
        if ($hasSc5) {
            $idlexoParts[] = "NULLIF(RTRIM(SC5.C5_ZIDLEX), '')";
        }
        $idlexoExpr = $idlexoParts !== []
            ? 'COALESCE(' . implode(', ', $idlexoParts) . ", '')"
            : "''";

        if ($idlexo4 === null && $hasSc5) {
            $notes[] = 'ID Lexos via SC5.C5_ZIDLEX (ZA4 sem coluna *IDLEX*).';
        }

        $sc5MatchCond = '1 = 0';
        $sc5Join = '1 = 0';
        $sc5Apply = '';
        if ($hasSc5) {
            $match = [];
            if ($idlexo4 !== null) {
                $match[] = 'RTRIM(SC5.C5_ZIDLEX) = RTRIM(ZA4.' . $idlexo4 . ')';
            }
            if ($pedmar4 !== null) {
                $match[] = 'RTRIM(SC5.C5_PEDMAR) = RTRIM(ZA4.' . $pedmar4 . ')';
            }
            $sc5MatchCond = $match !== [] ? '(' . implode(' OR ', $match) . ')' : '1 = 1';
            $sc5Join = 'SC5.C5_FILIAL = ZA4.ZA4_FILIAL AND ' . $sc5MatchCond;
            $sc5Apply = '
OUTER APPLY (
    SELECT TOP 1 SC5.C5_ZIDLEX, SC5.C5_PEDMAR, SC5.C5_ZMAKET
    FROM ' . ProtheusSqlHelper::tbl('SC5010', 'SC5') . '
    WHERE SC5.C5_FILIAL = ZA4.ZA4_FILIAL
        AND SC5.D_E_L_E_T_ = \' \'
        AND ' . $sc5MatchCond . '
) SC5';
        }

        $za5Apply = '';
        $clientNome = null;
        $clientCgc = null;
        if ($hasZa5) {
            $za5Cols = self::tableColumns($pdo, 'ZA5010');
            $pedmar5 = self::pickColumn($za5Cols, ['ZA5_PEDMAR', 'ZA5_PEDMK', 'ZA5_PEDIDO']);
            $clientNome = self::pickColumn($za5Cols, [
                'ZA5_NOME', 'ZA5_NOMCLI', 'ZA5_CLIENTE', 'ZA5_RAZAO', 'ZA5_NOMCLI',
            ]);
            $clientCgc = self::pickColumn($za5Cols, [
                'ZA5_CGC', 'ZA5_CPF', 'ZA5_CNPJ', 'ZA5_CGCCPF', 'ZA5_CPFCLI',
            ]);

            $applyWhere = [
                'ZA5.ZA5_FILIAL = ZA4.ZA4_FILIAL',
                "ZA5.D_E_L_E_T_ = ' '",
            ];
            if ($pedmar4 !== null && $pedmar5 !== null) {
                $applyWhere[] = 'RTRIM(ZA5.' . $pedmar5 . ') = RTRIM(ZA4.' . $pedmar4 . ')';
                $notes[] = 'ZA5 (cliente): vinculo por pedido marketplace (' . $pedmar4 . ').';
            } else {
                $shared = self::pickSharedJoinColumn($za4Cols, $za5Cols);
                if ($shared !== null) {
                    $applyWhere[] = 'RTRIM(ZA5.' . $shared['za5'] . ') = RTRIM(ZA4.' . $shared['za4'] . ')';
                    $notes[] = 'ZA5 (cliente): vinculo por ' . $shared['za4'] . '.';
                } else {
                    $notes[] = 'ZA5 (cliente): apenas por filial (sem chave pedido detectada).';
                }
            }

            $za5Select = ['ZA5.ZA5_FILIAL'];
            if ($clientNome !== null) {
                $za5Select[] = 'ZA5.' . $clientNome;
            }
            if ($clientCgc !== null) {
                $za5Select[] = 'ZA5.' . $clientCgc;
            }
            $za5Apply = '
OUTER APPLY (
    SELECT TOP 1 ' . implode(', ', array_unique($za5Select)) . '
    FROM ' . ProtheusSqlHelper::tbl('ZA5010', 'ZA5') . '
    WHERE ' . implode(' AND ', $applyWhere) . '
) ZA5';
        }

        $notes[] = 'Pedidos e erros em ZA4; SC5 para Lexos; ZA5 somente dados de cliente (opcional).';

        return [
            'idlexo4' => $idlexo4,
            'idlexo_expr' => $idlexoExpr,
            'pedmar4' => $pedmar4,
            'sc5_join_sql' => $sc5Join,
            'sc5_match_cond' => $sc5MatchCond,
            'sc5_apply_sql' => $sc5Apply,
            'za5_apply_sql' => $za5Apply,
            'has_sc5' => $hasSc5,
            'has_za5' => $hasZa5,
            'client_nome' => $clientNome,
            'client_cgc' => $clientCgc,
            'notes' => $notes,
        ];
    }

    /**
     * SQL para join ZA4 em consultas que partem de SC5 (ex.: monitor de pedidos NF).
     *
     * @return array{join_sql: string, notes: list<string>}
     */
    public static function resolveZa4JoinFromSc5(PDO $pdo): array
    {
        $za4Cols = self::tableColumns($pdo, 'ZA4010');
        $idlexo4 = self::pickLexosIdColumn($za4Cols, 'ZA4');
        $pedmar4 = self::pickColumn($za4Cols, ['ZA4_PEDMAR', 'ZA4_PEDMK', 'ZA4_PEDMARKE', 'ZA4_PEDIDO']);

        if ($idlexo4 !== null) {
            return [
                'join_sql' => 'SC5.C5_FILIAL = ZA4.ZA4_FILIAL AND RTRIM(ZA4.' . $idlexo4 . ') = RTRIM(SC5.C5_ZIDLEX)',
                'notes' => [],
            ];
        }

        if ($pedmar4 !== null) {
            return [
                'join_sql' => 'SC5.C5_FILIAL = ZA4.ZA4_FILIAL AND RTRIM(ZA4.' . $pedmar4 . ') = RTRIM(SC5.C5_PEDMAR)',
                'notes' => ['ZA4: join por ' . $pedmar4 . ' (sem coluna ID Lexos em ZA4).'],
            ];
        }

        return [
            'join_sql' => 'SC5.C5_FILIAL = ZA4.ZA4_FILIAL',
            'notes' => ['ZA4: join apenas por filial (sem coluna ID Lexos/pedmar em ZA4).'],
        ];
    }

    /**
     * @param list<string> $cols
     */
    private static function pickLexosIdColumn(array $cols, string $prefix): ?string
    {
        $byFragment = self::pickColumnContaining($cols, 'IDLEX');
        if ($byFragment !== null) {
            return $byFragment;
        }

        return self::pickColumn($cols, [
            $prefix . '_IDLEXO',
            $prefix . '_IDLEXOS',
            $prefix . '_IDLEX',
            $prefix . '_ID_LEX',
            $prefix . '_CODLEX',
        ]);
    }

    /**
     * @param list<string> $za4Cols
     * @param list<string> $za5Cols
     * @return array{za4: string, za5: string}|null
     */
    private static function pickSharedJoinColumn(array $za4Cols, array $za5Cols): ?array
    {
        $skip = ['D_E_L_E_T_', 'R_E_C_N_O_', 'R_E_C_D_E_L_'];
        foreach ($za4Cols as $za4) {
            if (!str_starts_with($za4, 'ZA4_')) {
                continue;
            }
            foreach ($skip as $s) {
                if (str_starts_with($za4, $s)) {
                    continue 2;
                }
            }
            $suffix = substr($za4, 3);
            $za5 = 'ZA5' . $suffix;
            if (in_array($za5, $za5Cols, true)) {
                return ['za4' => $za4, 'za5' => $za5];
            }
        }

        return null;
    }

    /**
     * @param list<string> $available
     */
    private static function pickColumnContaining(array $available, string $fragment): ?string
    {
        $fragment = strtoupper($fragment);
        foreach ($available as $col) {
            if (str_contains(strtoupper($col), $fragment)) {
                return $col;
            }
        }

        return null;
    }

    /**
     * @param list<string> $available
     * @param list<string> $candidates
     */
    private static function pickColumn(array $available, array $candidates): ?string
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

    private static function tableExists(PDO $pdo, string $table): bool
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
    private static function tableColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = :table ORDER BY ORDINAL_POSITION'
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
}
