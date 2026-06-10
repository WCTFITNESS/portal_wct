<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Shuchkin\SimpleXLSXGen;

class ProtheusRomaneioMonitorService
{
    /** @var list<string> */
    private const EXCLUDED_TRANSPORTES = ['000006', '000176', '000177', '000179', '000265'];

    /**
     * Canais exibidos no filtro mesmo sem registro no recorte do monitor (romaneio pendente).
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

    /** Cores alinhadas ao monitor na tela (hex sem #, formato ARGB no XLSX via FF prefix interno). */
    private const COLOR_HEADER = 'F1F5F9';
    private const COLOR_ROW_ROMANEIO = 'FEF9C3';
    private const COLOR_ROW_LIBERACAO = 'FFEDD5';
    private const COLOR_CELL_ROMANEIO_EMPTY = 'FDE047';
    private const COLOR_CELL_LIBERACAO_EMPTY = 'FDBA74';
    private const COLOR_MISSING = 'FEF2F2';
    private const COLOR_MISSING_TEXT = '991B1B';

    /** @var list<string> */
    private const EXPORT_HIGHLIGHT_COLUMNS = ['ROMANEIO', 'DT_LIBERACAO', 'HR_LIBERACAO'];

    private ?string $za4JoinFromSc5 = null;

    public function __construct(
        private ProtheusConnectionService $connectionService
    ) {
    }

    /** @return array<string, string> coluna SQL => rotulo na planilha */
    public static function exportColumns(): array
    {
        return [
            'F2_FILIAL' => 'Filial',
            'F2_DOC' => 'Doc',
            'F2_SERIE' => 'Serie',
            'F2_CHVNFE' => 'Chave NF-e',
            'F2_CLIENTE' => 'Cliente',
            'F2_LOJA' => 'Loja',
            'CPF_CNPJ' => 'CPF/CNPJ',
            'F2_EMISSAO' => 'Emissao',
            'F2_VALBRUT' => 'Valor bruto',
            'ROMANEIO' => 'Romaneio',
            'DT_LIBERACAO' => 'Dt liberacao',
            'HR_LIBERACAO' => 'Hr liberacao',
            'PED_Marketplace' => 'Ped. marketplace',
            'Marketplace' => 'Marketplace',
            'IDLEXOS' => 'ID Lexos',
            'GW1_SITINT' => 'SIT. INT.',
        ];
    }

    public static function formatCpfCnpj(mixed $value): string
    {
        $digits = preg_replace('/\D/', '', (string) ($value ?? ''));
        if (strlen($digits) === 11) {
            return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $digits) ?: $digits;
        }
        if (strlen($digits) === 14) {
            return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $digits) ?: $digits;
        }

        return trim((string) ($value ?? ''));
    }

    public static function formatChaveNfe(mixed $value): string
    {
        $digits = preg_replace('/\D/', '', (string) ($value ?? ''));
        if (strlen($digits) === 44) {
            return $digits;
        }

        return trim((string) ($value ?? ''));
    }

    public static function formatSitIntLabel(mixed $value): string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return 'Não integrado';
        }

        if (is_numeric($raw)) {
            $code = (int) round((float) $raw);
            return match ($code) {
                1 => '1-Sucesso',
                2 => '2-erro',
                default => $raw,
            };
        }

        return match ($raw) {
            '1' => '1-Sucesso',
            '2' => '2-erro',
            default => $raw,
        };
    }

    /**
     * Gera XLSX com todos os registros do filtro (sem paginacao).
     */
    public function exportToXlsx(
        string $filial,
        string $emissaoDe,
        string $emissaoAte,
        string $marketplace = '',
        string $docsCsv = '',
        string $pedidosCsv = ''
    ): string {
        $pdo = $this->connectionService->connect();
        $ctx = $this->buildFilterContext($filial, $emissaoDe, $emissaoAte, $marketplace, $docsCsv, $pedidosCsv);
        $rows = $this->fetchAll($pdo, $ctx);
        $foundBatch = $this->fetchFoundBatchIdentifiers($pdo, $ctx);
        $missingDocs = ProtheusSqlHelper::missingFromBatch($ctx['docs'], $foundBatch['docs']);
        $missingPedidos = ProtheusSqlHelper::missingFromBatch($ctx['pedidos'], $foundBatch['pedidos']);
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
        $fileName = sprintf(
            'monitor_romaneio_%s_%s.xlsx',
            $safeFilial,
            date('Ymd_His')
        );
        $fullPath = $dir . DIRECTORY_SEPARATOR . $fileName;

        require_once __DIR__ . '/../Lib/SimpleXLSXGen.php';
        $xlsx = SimpleXLSXGen::fromArray($sheet, 'Romaneio');
        $this->attachMissingExportSheet($xlsx, $missingDocs, $missingPedidos);
        if (!$xlsx->saveAs($fullPath)) {
            throw new \RuntimeException('Nao foi possivel gravar o arquivo de exportacao.');
        }

        return $fullPath;
    }

    /**
     * @return list<array<string, mixed>>
     */
    /**
     * @return list<string>
     */
    public function listMarketplaces(string $filial, string $emissaoDe, string $emissaoAte): array
    {
        $pdo = $this->connectionService->connect();
        $ctx = $this->buildFilterContext($filial, $emissaoDe, $emissaoAte, '', '', '');

        $doMonitor = $this->fetchDistinctMarketplaces($pdo, $ctx, true);
        $doPeriodo = $this->fetchDistinctMarketplaces($pdo, $ctx, false);

        return $this->mergeMarketplaceOptions($doMonitor, $doPeriodo);
    }

    /**
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>} $ctx
     * @return list<string>
     */
    private function fetchDistinctMarketplaces(PDO $pdo, array $ctx, bool $apenasMonitor): array
    {
        $params = $ctx['params'];
        $transports = implode(',', array_map(
            static fn (string $code): string => "'" . str_replace("'", "''", $code) . "'",
            self::EXCLUDED_TRANSPORTES
        ));

        $sf2 = ProtheusSqlHelper::tbl('SF2010', 'SF2');
        $sc5 = ProtheusSqlHelper::tbl('SC5010', 'SC5');

        $sql = 'SELECT DISTINCT RTRIM(SC5.C5_ZMAKET) AS marketplace
FROM ' . $sf2 . '
INNER JOIN ' . $sc5 . '
    ON SC5.C5_FILIAL = SF2.F2_FILIAL
    AND SC5.C5_NOTA = SF2.F2_DOC
    AND SC5.C5_SERIE = SF2.F2_SERIE
    AND SC5.D_E_L_E_T_ = \' \'
WHERE SF2.D_E_L_E_T_ = \' \'
    AND SF2.F2_FILIAL = :filial
    AND SF2.F2_TRANSP NOT IN (' . $transports . ')
    AND SF2.F2_EMISSAO BETWEEN :emissao_de AND :emissao_ate
    AND RTRIM(ISNULL(SC5.C5_ZMAKET, \'\')) <> \'\'';

        if ($apenasMonitor) {
            $gw1 = ProtheusSqlHelper::tbl('GW1010', 'GW1');
            $sql = 'SELECT DISTINCT RTRIM(SC5.C5_ZMAKET) AS marketplace
FROM ' . $sf2 . '
INNER JOIN ' . $gw1 . '
    ON GW1.GW1_FILIAL = SF2.F2_FILIAL
    AND GW1.GW1_NRDC = SF2.F2_DOC
    AND GW1.GW1_SERDC = SF2.F2_SERIE
    AND GW1.D_E_L_E_T_ = \' \'
INNER JOIN ' . $sc5 . '
    ON SC5.C5_FILIAL = SF2.F2_FILIAL
    AND SC5.C5_NOTA = SF2.F2_DOC
    AND SC5.C5_SERIE = SF2.F2_SERIE
    AND SC5.D_E_L_E_T_ = \' \'
' . $this->baseWhereClause($ctx) . '
    AND RTRIM(ISNULL(SC5.C5_ZMAKET, \'\')) <> \'\'';
        }

        $sql .= ' ORDER BY marketplace';

        $stmt = $pdo->prepare($sql);
        $stmt->execute(ProtheusSqlHelper::paramsSemMarketplaceSeLike($params));
        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $lista = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $nome = trim((string) ($row['marketplace'] ?? ''));
            if ($nome !== '') {
                $lista[] = $nome;
            }
        }

        return $lista;
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

    public function listAllRomaneios(
        string $filial,
        string $emissaoDe,
        string $emissaoAte,
        string $marketplace = '',
        string $docsCsv = '',
        string $pedidosCsv = ''
    ): array {
        $pdo = $this->connectionService->connect();
        $ctx = $this->buildFilterContext($filial, $emissaoDe, $emissaoAte, $marketplace, $docsCsv, $pedidosCsv);

        return $this->fetchAll($pdo, $ctx);
    }

    /**
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   total: int,
     *   page: int,
     *   per_page: int,
     *   total_pages: int,
     *   missing_docs: list<string>,
     *   missing_pedidos: list<string>
     * }
     */
    public function listRomaneios(
        string $filial,
        string $emissaoDe,
        string $emissaoAte,
        int $page = 1,
        int $perPage = 50,
        string $marketplace = '',
        string $docsCsv = '',
        string $pedidosCsv = ''
    ): array {
        $page = max(1, $page);
        $perPage = max(10, min(200, $perPage));
        $offset = ($page - 1) * $perPage;

        $pdo = $this->connectionService->connect();
        $ctx = $this->buildFilterContext($filial, $emissaoDe, $emissaoAte, $marketplace, $docsCsv, $pedidosCsv);

        $total = $this->fetchTotal($pdo, $ctx);
        $rows = $this->fetchPage($pdo, $ctx, $offset, $perPage);
        $foundBatch = $this->fetchFoundBatchIdentifiers($pdo, $ctx);

        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'missing_docs' => ProtheusSqlHelper::missingFromBatch($ctx['docs'], $foundBatch['docs']),
            'missing_pedidos' => ProtheusSqlHelper::missingFromBatch($ctx['pedidos'], $foundBatch['pedidos']),
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

    private function exportHeaderCell(string $label): string
    {
        $safe = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<style bgcolor="#' . self::COLOR_HEADER . '"><b>' . $safe . '</b></style>';
    }

    /**
     * @param list<string> $missingDocs
     * @param list<string> $missingPedidos
     */
    private function attachMissingExportSheet(
        SimpleXLSXGen $xlsx,
        array $missingDocs,
        array $missingPedidos
    ): void {
        $entries = ProtheusSqlHelper::missingExportEntries($missingDocs, $missingPedidos);
        if ($entries === []) {
            return;
        }

        $sheet = [
            [
                $this->exportHeaderCell('Tipo'),
                $this->exportHeaderCell('Valor informado'),
            ],
        ];
        foreach ($entries as $entry) {
            $sheet[] = [
                $this->exportMissingCell($entry['tipo']),
                $this->exportMissingCell($entry['valor']),
            ];
        }

        $xlsx->addSheet($sheet, 'Nao encontrados');
    }

    private function exportMissingCell(string $value): string
    {
        $safe = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<style bgcolor="#' . self::COLOR_MISSING . '" color="#' . self::COLOR_MISSING_TEXT . '"><b>' . $safe . '</b></style>';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function exportDataCell(array $row, string $columnKey, string $value): string
    {
        $alert = $this->rowAlertClass($row);
        $rowColor = match ($alert) {
            'row-alert-romaneio' => self::COLOR_ROW_ROMANEIO,
            'row-alert-liberacao' => self::COLOR_ROW_LIBERACAO,
            default => null,
        };

        $bg = $rowColor;
        $highlightColumn = in_array($columnKey, self::EXPORT_HIGHLIGHT_COLUMNS, true);
        if ($highlightColumn && $value === '' && $rowColor !== null) {
            $bg = match ($alert) {
                'row-alert-romaneio' => self::COLOR_CELL_ROMANEIO_EMPTY,
                'row-alert-liberacao' => self::COLOR_CELL_LIBERACAO_EMPTY,
                default => $rowColor,
            };
        }

        $safe = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($this->isEmptyAlertCell($columnKey, $value)) {
            return '<style color="#DC2626"><b>' . $safe . '</b></style>';
        }

        if ($bg === null) {
            return $safe;
        }

        return '<style bgcolor="#' . $bg . '">' . $safe . '</style>';
    }

    /**
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>} $ctx
     */
    private function fetchTotal(PDO $pdo, array $ctx): int
    {
        $sql = 'SELECT COUNT(1) AS total FROM (' . $this->baseSql($ctx) . ') AS q';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(ProtheusSqlHelper::paramsSemMarketplaceSeLike($ctx['params']));
        $row = $stmt->fetch();

        return (int) ($row['total'] ?? 0);
    }

    /**
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>} $ctx
     * @return list<array<string, mixed>>
     */
    private function fetchPage(PDO $pdo, array $ctx, int $offset, int $limit): array
    {
        $bind = ProtheusSqlHelper::paramsSemMarketplaceSeLike($ctx['params']);
        $sql = $this->baseSql($ctx)
            . ' ORDER BY SF2.F2_EMISSAO DESC, SF2.F2_DOC, SF2.F2_SERIE'
            . ' OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY';

        $stmt = $pdo->prepare($sql);
        foreach ($bind as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>} $ctx
     * @return list<array<string, mixed>>
     */
    private function fetchAll(PDO $pdo, array $ctx): array
    {
        $sql = $this->baseSql($ctx)
            . ' ORDER BY SF2.F2_EMISSAO DESC, SF2.F2_DOC, SF2.F2_SERIE';

        $stmt = $pdo->prepare($sql);
        $stmt->execute(ProtheusSqlHelper::paramsSemMarketplaceSeLike($ctx['params']));
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>} $ctx
     * @return array{docs: list<string>, pedidos: list<string>}
     */
    private function fetchFoundBatchIdentifiers(PDO $pdo, array $ctx): array
    {
        if ($ctx['docs'] === [] && $ctx['pedidos'] === []) {
            return ['docs' => [], 'pedidos' => []];
        }

        $sql = 'SELECT DISTINCT RTRIM(CAST(q.F2_DOC AS VARCHAR(50))) AS batch_doc, '
            . 'RTRIM(CAST(q.PED_Marketplace AS VARCHAR(100))) AS batch_ped '
            . 'FROM (' . $this->baseSql($ctx) . ') AS q';

        $stmt = $pdo->prepare($sql);
        $stmt->execute(ProtheusSqlHelper::paramsSemMarketplaceSeLike($ctx['params']));
        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return ['docs' => [], 'pedidos' => []];
        }

        $foundDocs = [];
        $foundPedidos = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($ctx['docs'] !== []) {
                $doc = trim((string) ($row['batch_doc'] ?? ''));
                if ($doc !== '') {
                    $foundDocs[strtoupper($doc)] = $doc;
                }
            }
            if ($ctx['pedidos'] !== []) {
                $ped = trim((string) ($row['batch_ped'] ?? ''));
                if ($ped !== '') {
                    $foundPedidos[strtoupper($ped)] = $ped;
                }
            }
        }

        return [
            'docs' => array_values($foundDocs),
            'pedidos' => array_values($foundPedidos),
        ];
    }

    private function exportDirectory(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ml-portal-protheus';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Nao foi possivel criar pasta temporaria para exportacao.');
        }

        return $dir;
    }

    private function za4JoinFromSc5Sql(): string
    {
        if ($this->za4JoinFromSc5 === null) {
            $resolved = ProtheusZa4LexosSchema::resolveZa4JoinFromSc5($this->connectionService->connect());
            $this->za4JoinFromSc5 = $resolved['join_sql'];
        }

        return $this->za4JoinFromSc5;
    }

    /**
     * @return array{params: array<string, string>, docs: list<string>, pedidos: list<string>}
     */
    private function buildFilterContext(
        string $filial,
        string $emissaoDe,
        string $emissaoAte,
        string $marketplace,
        string $docsCsv = '',
        string $pedidosCsv = ''
    ): array {
        $params = [
            ':filial' => $this->normalizeFilial($filial),
            ':emissao_de' => $this->normalizeProtheusDate($emissaoDe, '20260101'),
            ':emissao_ate' => $this->normalizeProtheusDate($emissaoAte, date('Ymd')),
        ];
        $marketplace = trim($marketplace);
        if ($marketplace !== '') {
            $params[':marketplace'] = $marketplace;
        }

        $docs = $this->parseBatchFilter($docsCsv);
        $pedidos = $this->parseBatchFilter($pedidosCsv);
        foreach ($docs as $i => $doc) {
            $params[':doc_' . $i] = $doc;
        }
        foreach ($pedidos as $i => $ped) {
            $params[':ped_' . $i] = $ped;
        }

        return [
            'params' => $params,
            'docs' => $docs,
            'pedidos' => $pedidos,
        ];
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
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>} $ctx
     */
    private function baseWhereClause(array $ctx): string
    {
        $params = $ctx['params'];
        $docs = $ctx['docs'];
        $pedidos = $ctx['pedidos'];
        $transports = implode(',', array_map(
            static fn (string $code): string => "'" . str_replace("'", "''", $code) . "'",
            self::EXCLUDED_TRANSPORTES
        ));

        $sql = <<<SQL
WHERE SF2.D_E_L_E_T_ = ' '
    AND SF2.F2_FILIAL = :filial
    AND SF2.F2_TRANSP NOT IN ({$transports})
    AND SF2.F2_EMISSAO BETWEEN :emissao_de AND :emissao_ate
    AND GW1.GW1_DTSAI = ''
    AND GW1.GW1_HRSAI = ''
    AND SC5.C5_ZIDLEX <> ''
SQL;

        if (isset($params[':marketplace'])) {
            $sql .= ProtheusSqlHelper::marketplaceAndSql($params[':marketplace']);
        }

        if ($docs !== []) {
            $placeholders = [];
            foreach (array_keys($docs) as $i) {
                $placeholders[] = ':doc_' . $i;
            }
            $sql .= ' AND RTRIM(SF2.F2_DOC) IN (' . implode(', ', $placeholders) . ')';
        }

        if ($pedidos !== []) {
            $placeholders = [];
            foreach (array_keys($pedidos) as $i) {
                $placeholders[] = ':ped_' . $i;
            }
            $sql .= ' AND RTRIM(SC5.C5_PEDMAR) IN (' . implode(', ', $placeholders) . ')';
        }

        return $sql;
    }

    /**
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>} $ctx
     */
    private function baseSql(array $ctx): string
    {
        $sf2 = ProtheusSqlHelper::tbl('SF2010', 'SF2');
        $gw1 = ProtheusSqlHelper::tbl('GW1010', 'GW1');
        $sc5 = ProtheusSqlHelper::tbl('SC5010', 'SC5');
        $sa1 = ProtheusSqlHelper::tbl('SA1010', 'SA1');
        $za4 = ProtheusSqlHelper::tbl('ZA4010', 'ZA4');

        return <<<SQL
SELECT
    SF2.F2_FILIAL,
    SF2.F2_DOC,
    SF2.F2_SERIE,
    RTRIM(ISNULL(SF2.F2_CHVNFE, '')) AS F2_CHVNFE,
    SF2.F2_CLIENTE,
    SF2.F2_LOJA,
    RTRIM(SA1.A1_CGC) AS CPF_CNPJ,
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
FROM {$sf2}
INNER JOIN {$gw1}
    ON GW1.GW1_FILIAL = SF2.F2_FILIAL
    AND GW1.GW1_NRDC = SF2.F2_DOC
    AND GW1.GW1_SERDC = SF2.F2_SERIE
    AND GW1.D_E_L_E_T_ = ' '
INNER JOIN {$sc5}
    ON SC5.C5_FILIAL = SF2.F2_FILIAL
    AND SC5.C5_NOTA = SF2.F2_DOC
    AND SC5.C5_SERIE = SF2.F2_SERIE
    AND SC5.D_E_L_E_T_ = ' '
LEFT JOIN {$sa1}
    ON RTRIM(SA1.A1_COD) = RTRIM(SF2.F2_CLIENTE)
    AND RTRIM(SA1.A1_LOJA) = RTRIM(SF2.F2_LOJA)
    AND (
        RTRIM(SA1.A1_FILIAL) = RTRIM(SF2.F2_FILIAL)
        OR RTRIM(ISNULL(SA1.A1_FILIAL, '')) = ''
    )
    AND SA1.D_E_L_E_T_ = ' '
LEFT JOIN {$za4}
    ON {$this->za4JoinFromSc5Sql()}
    AND ZA4.D_E_L_E_T_ = ' '
{$this->baseWhereClause($ctx)}
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

    public function displayCellText(string $columnKey, mixed $value): string
    {
        if ($columnKey === 'GW1_SITINT') {
            return self::formatSitIntLabel($value);
        }
        if ($columnKey === 'CPF_CNPJ') {
            return self::formatCpfCnpj($value);
        }
        if ($columnKey === 'F2_CHVNFE') {
            return self::formatChaveNfe($value);
        }

        $text = $this->cellText($value);

        if ($columnKey === 'ROMANEIO' && $text === '') {
            return 'SEM Nº ROMANEIO';
        }
        if ($columnKey === 'DT_LIBERACAO' && $text === '') {
            return 'ROMANEIO Ñ LIBERADO';
        }

        return $text;
    }

    public function displayCellHtml(string $columnKey, mixed $value): string
    {
        $raw = $this->cellText($value);

        if ($columnKey === 'ROMANEIO' && $raw === '') {
            return '<span class="cell-empty-alert">SEM Nº ROMANEIO</span>';
        }
        if ($columnKey === 'DT_LIBERACAO' && $raw === '') {
            return '<span class="cell-empty-alert">ROMANEIO Ñ LIBERADO</span>';
        }

        return htmlspecialchars($this->displayCellText($columnKey, $value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function isEmptyAlertCell(string $columnKey, string $displayText): bool
    {
        return ($columnKey === 'ROMANEIO' && $displayText === 'SEM Nº ROMANEIO')
            || ($columnKey === 'DT_LIBERACAO' && $displayText === 'ROMANEIO Ñ LIBERADO');
    }

    private function cellText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }
}
