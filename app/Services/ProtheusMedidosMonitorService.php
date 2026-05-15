<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

class ProtheusMedidosMonitorService
{
    /** @var list<string> */
    private const EXCLUDED_TRANSPORTES = ['000006', '000176', '000177', '000179', '000265'];

    public function __construct(
        private ProtheusConnectionService $connectionService
    ) {
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
    public function listMedidos(
        string $filial,
        string $emissaoDe,
        string $emissaoAte,
        int $page = 1,
        int $perPage = 50
    ): array {
        $filial = $this->normalizeFilial($filial);
        $emissaoDe = $this->normalizeProtheusDate($emissaoDe, '20260101');
        $emissaoAte = $this->normalizeProtheusDate($emissaoAte, '20260514');
        $page = max(1, $page);
        $perPage = max(10, min(200, $perPage));
        $offset = ($page - 1) * $perPage;

        $pdo = $this->connectionService->connect();
        $params = [
            ':filial' => $filial,
            ':emissao_de' => $emissaoDe,
            ':emissao_ate' => $emissaoAte,
        ];

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
     * @param array<string, mixed> $row
     */
    public function rowAlertClass(array $row): string
    {
        $romaneio = $this->cellText($row['ROMANEIO'] ?? null);
        $liberacao = $this->cellText($row['DT_LIBERACAO'] ?? null);

        if ($romaneio === '') {
            return 'row-alert-romaneio';
        }
        if ($liberacao === '') {
            return 'row-alert-liberacao';
        }

        return '';
    }

  /**
     * @param array<string, string> $params
     */
    private function fetchTotal(PDO $pdo, array $params): int
    {
        $sql = 'SELECT COUNT(1) AS total FROM (' . $this->baseSql() . ') AS q';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return (int) ($row['total'] ?? 0);
    }

    /**
     * @param array<string, string> $params
     * @return list<array<string, mixed>>
     */
    private function fetchPage(PDO $pdo, array $params, int $offset, int $limit): array
    {
        $sql = $this->baseSql()
            . ' ORDER BY SF2.F2_EMISSAO DESC, SF2.F2_DOC, SF2.F2_SERIE'
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

    private function baseSql(): string
    {
        $transports = implode(',', array_map(
            static fn (string $code): string => "'" . str_replace("'", "''", $code) . "'",
            self::EXCLUDED_TRANSPORTES
        ));

        return <<<SQL
SELECT
    SF2.F2_FILIAL,
    SF2.F2_DOC,
    SF2.F2_SERIE,
    SF2.F2_CLIENTE,
    SF2.F2_LOJA,
    SUBSTRING(SF2.F2_EMISSAO, 7, 2) + '/' +
        SUBSTRING(SF2.F2_EMISSAO, 5, 2) + '/' +
        SUBSTRING(SF2.F2_EMISSAO, 1, 4) AS F2_EMISSAO,
    SF2.F2_VALBRUT,
    GW1.GW1_NRROM AS ROMANEIO,
    GW1.GW1_DTSAI AS DT_LIBERACAO,
    GW1.GW1_HRSAI AS HR_LIBERACAO,
    SC5.C5_PEDMAR AS PED_Marketplace,
    SC5.C5_ZMAKET AS Marketplace,
    SC5.C5_ZIDLEX AS IDLEXOS,
    GW1.GW1_SITINT
FROM SF2010 SF2
INNER JOIN GW1010 GW1
    ON GW1.GW1_FILIAL = SF2.F2_FILIAL
    AND GW1.GW1_NRDC = SF2.F2_DOC
    AND GW1.GW1_SERDC = SF2.F2_SERIE
    AND GW1.D_E_L_E_T_ = ' '
INNER JOIN SC5010 SC5
    ON SC5.C5_FILIAL = SF2.F2_FILIAL
    AND SC5.C5_NOTA = SF2.F2_DOC
    AND SC5.C5_SERIE = SF2.F2_SERIE
    AND SC5.D_E_L_E_T_ = ' '
INNER JOIN ZA4010 ZA4
    ON SC5.C5_FILIAL = ZA4.ZA4_FILIAL
    AND ZA4.ZA4_IDLEXO = SC5.C5_ZIDLEX
    AND ZA4.D_E_L_E_T_ = ' '
WHERE SF2.D_E_L_E_T_ = ' '
    AND SF2.F2_FILIAL = :filial
    AND SF2.F2_TRANSP NOT IN ({$transports})
    AND SF2.F2_EMISSAO BETWEEN :emissao_de AND :emissao_ate
    AND GW1.GW1_DTSAI = ''
    AND GW1.GW1_HRSAI = ''
    AND SC5.C5_ZIDLEX <> ''
SQL;
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

    private function cellText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }
}
