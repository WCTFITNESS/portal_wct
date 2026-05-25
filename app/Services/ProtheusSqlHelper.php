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
}
