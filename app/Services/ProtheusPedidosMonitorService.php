<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Shuchkin\SimpleXLSXGen;

/**
 * Monitor de pedidos / notas fiscais marketplace no Protheus (visao completa).
 */
class ProtheusPedidosMonitorService
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
    private const EXPORT_HIGHLIGHT_COLUMNS = ['ROMANEIO', 'DT_SAIDA'];

    private const QUERY_TIMEOUT_SEC = 45;

    /** Acima disso usa match completo (lotes grandes). */
    private const PEDIDO_FAST_LOOKUP_MAX = 50;

    private const MAX_RESULT_ROWS = 2500;

    /** @var array{join_sql: string, pedmar_col: string, notes: list<string>}|null */
    private ?array $za4JoinMeta = null;

    private ?string $sc5ValorCol = null;

    private ?string $sc5AprovacaoCol = null;

    private ?string $za4AprovacaoCol = null;

    private ?string $sf2IdHubCol = null;

    public function __construct(
        private ProtheusConnectionService $connectionService
    ) {
    }

    /** @return array<string, string> coluna SQL => rotulo na planilha */
    public static function exportColumns(): array
    {
        return [
            'F2_FILIAL' => 'Filial',
            'F2_DOC' => 'Nota',
            'F2_SERIE' => 'Serie',
            'F2_CHVNFE' => 'Chave NF-e',
            'CPF_CNPJ' => 'CPF/CNPJ',
            'F2_EMISSAO' => 'Emissao NF',
            'F2_VALBRUT' => 'Valor bruto',
            'ROMANEIO' => 'Romaneio',
            'DT_SAIDA' => 'Data saida',
            'HR_SAIDA' => 'Hora saida',
            'TRANSP_COD' => 'Transp. cod.',
            'TRANSP_NOME' => 'Transportadora',
            'PED_Marketplace' => 'Ped. marketplace',
            'DT_APROVACAO' => 'Data aprovacao',
            'RAZAO_SOCIAL' => 'Razao social',
            'ID_HUB' => 'ID Hub',
            'Marketplace' => 'Marketplace',
            'PED_INTERNO' => 'Ped. interno',
            'F2_CLIENTE' => 'Cliente',
            'F2_LOJA' => 'Loja',
            'GW1_SITINT' => 'SIT. INT.',
        ];
    }

    public static function formatCpfCnpj(mixed $value): string
    {
        return ProtheusRomaneioMonitorService::formatCpfCnpj($value);
    }

    public static function formatChaveNfe(mixed $value): string
    {
        return ProtheusRomaneioMonitorService::formatChaveNfe($value);
    }

    public static function formatSitIntLabel(mixed $value): string
    {
        return ProtheusRomaneioMonitorService::formatSitIntLabel($value);
    }

    public static function formatDataAprovacao(mixed $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^\d{8}$/', $raw)) {
            return substr($raw, 6, 2) . '/' . substr($raw, 4, 2) . '/' . substr($raw, 0, 4);
        }
        if (preg_match('/^\d{14}$/', $raw)) {
            return substr($raw, 6, 2) . '/' . substr($raw, 4, 2) . '/' . substr($raw, 0, 4)
                . ' ' . substr($raw, 8, 2) . ':' . substr($raw, 10, 2) . ':' . substr($raw, 12, 2);
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})/', $raw, $m)) {
            return $m[3] . '/' . $m[2] . '/' . $m[1] . ' ' . $m[4] . ':' . $m[5] . ':' . $m[6];
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
            return $m[3] . '/' . $m[2] . '/' . $m[1];
        }

        return $raw;
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
        string $pedidosCsv = '',
        string $cpfCnpj = '',
        string $saidaDe = '',
        string $saidaAte = ''
    ): string {
        $pdo = $this->connectionService->connect();
        $this->applyQueryTimeout($pdo);
        $ctx = $this->buildFilterContext(
            $filial,
            $emissaoDe,
            $emissaoAte,
            $marketplace,
            $docsCsv,
            $pedidosCsv,
            $cpfCnpj,
            $saidaDe,
            $saidaAte
        );
        $this->assertPedidoOrDocBatchRequired($ctx);
        if (!empty($ctx['batch_ids'])) {
            $rows = $this->fetchBatchRows($pdo, $ctx);
            $foundBatch = $this->needsBatchLookup($ctx)
                ? $this->collectFoundBatchFromResultRows($ctx, $rows)
                : ['docs' => [], 'pedidos' => [], 'cpfs' => []];
        } else {
            $rows = $this->fetchAll($pdo, $ctx);
            $foundBatch = $this->needsBatchLookup($ctx)
                ? $this->fetchFoundBatchIdentifiersLite($pdo, $ctx)
                : ['docs' => [], 'pedidos' => [], 'cpfs' => []];
        }
        $missingDocs = ProtheusSqlHelper::missingFromBatch($ctx['docs'], $foundBatch['docs']);
        $missingPedidos = ProtheusSqlHelper::missingFromBatch($ctx['pedidos'], $foundBatch['pedidos']);
        $missingCpfs = ProtheusSqlHelper::missingFromBatch($ctx['cpfs'], $foundBatch['cpfs']);
        $missingPedidosMotivos = $this->diagnoseMissingPedidos($pdo, $ctx, $missingPedidos);
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
            'monitor_pedidos_%s_%s.xlsx',
            $safeFilial,
            date('Ymd_His')
        );
        $fullPath = $dir . DIRECTORY_SEPARATOR . $fileName;

        require_once __DIR__ . '/../Lib/SimpleXLSXGen.php';
        $xlsx = SimpleXLSXGen::fromArray($sheet, 'Pedidos');
        $this->attachMissingExportSheet($xlsx, $missingDocs, $missingPedidos, $missingCpfs, $missingPedidosMotivos);
        if (!$xlsx->saveAs($fullPath)) {
            throw new \RuntimeException('Nao foi possivel gravar o arquivo de exportacao.');
        }

        return $fullPath;
    }

    /**
     * @return list<string>
     */
    public function listMarketplaces(string $filial, string $emissaoDe, string $emissaoAte): array
    {
        $pdo = $this->connectionService->connect();
        $ctx = $this->buildFilterContext($filial, $emissaoDe, $emissaoAte, '', '', '');

        $lista = $this->fetchDistinctMarketplaces($pdo, $ctx);

        return $this->mergeMarketplaceOptions($lista);
    }

    /** @return list<string> */
    public function defaultMarketplaceOptions(): array
    {
        return $this->mergeMarketplaceOptions([]);
    }

    public function hasBatchLookupFilters(string $docsCsv, string $pedidosCsv, string $cpfCnpj = ''): bool
    {
        return $this->parseBatchFilter($docsCsv) !== []
            || $this->parseBatchFilter($pedidosCsv) !== []
            || $this->parseCpfCnpjBatchFilter($cpfCnpj) !== [];
    }

    /** Consulta permitida apenas com nota ou pedido marketplace em lote (CPF e datas sao filtros adicionais). */
    public function hasPedidoOrDocBatchFilters(string $docsCsv, string $pedidosCsv): bool
    {
        return $this->parseBatchFilter($docsCsv) !== []
            || $this->parseBatchFilter($pedidosCsv) !== [];
    }

    /**
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>, cpfs: list<string>, saida_filter: bool} $ctx
     * @return list<string>
     */
    private function fetchDistinctMarketplaces(PDO $pdo, array $ctx): array
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

    public function listAllPedidos(
        string $filial,
        string $emissaoDe,
        string $emissaoAte,
        string $marketplace = '',
        string $docsCsv = '',
        string $pedidosCsv = '',
        string $cpfCnpj = '',
        string $saidaDe = '',
        string $saidaAte = ''
    ): array {
        $pdo = $this->connectionService->connect();
        $ctx = $this->buildFilterContext($filial, $emissaoDe, $emissaoAte, $marketplace, $docsCsv, $pedidosCsv, $cpfCnpj, $saidaDe, $saidaAte);

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
     *   missing_pedidos: list<string>,
     *   missing_cpfs: list<string>,
     *   missing_pedidos_motivos: array<string, string>
     * }
     */
    public function listPedidos(
        string $filial,
        string $emissaoDe,
        string $emissaoAte,
        int $page = 1,
        int $perPage = 50,
        string $marketplace = '',
        string $docsCsv = '',
        string $pedidosCsv = '',
        string $cpfCnpj = '',
        string $saidaDe = '',
        string $saidaAte = ''
    ): array {
        $page = max(1, $page);
        $perPage = max(10, min(200, $perPage));
        $offset = ($page - 1) * $perPage;

        $pdo = $this->connectionService->connect();
        $this->applyQueryTimeout($pdo);
        $ctx = $this->buildFilterContext($filial, $emissaoDe, $emissaoAte, $marketplace, $docsCsv, $pedidosCsv, $cpfCnpj, $saidaDe, $saidaAte);

        $this->assertPedidoOrDocBatchRequired($ctx);

        if (!empty($ctx['batch_ids'])) {
            return $this->listPedidosBatchFast($pdo, $ctx, $page, $perPage);
        }

        $total = $this->fetchTotal($pdo, $ctx);
        $rows = $this->fetchPage($pdo, $ctx, $offset, $perPage);

        $foundBatch = ['docs' => [], 'pedidos' => [], 'cpfs' => []];
        if ($this->needsBatchLookup($ctx)) {
            $foundBatch = $this->fetchFoundBatchIdentifiersLite($pdo, $ctx);
        }

        $missingPedidos = ProtheusSqlHelper::missingFromBatch($ctx['pedidos'], $foundBatch['pedidos']);
        $missingCpfs = ProtheusSqlHelper::missingFromBatch($ctx['cpfs'], $foundBatch['cpfs']);

        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'missing_docs' => ProtheusSqlHelper::missingFromBatch($ctx['docs'], $foundBatch['docs']),
            'missing_pedidos' => $missingPedidos,
            'missing_cpfs' => $missingCpfs,
            'missing_pedidos_motivos' => $this->diagnoseMissingPedidos($pdo, $ctx, $missingPedidos),
        ];
    }

    /**
     * Filtro por lista (notas/pedidos): uma consulta partindo de SC5 quando ha pedidos.
     *
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>, cpfs: list<string>, saida_filter: bool, batch_ids: bool} $ctx
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   total: int,
     *   page: int,
     *   per_page: int,
     *   total_pages: int,
     *   missing_docs: list<string>,
     *   missing_pedidos: list<string>,
     *   missing_cpfs: list<string>,
     *   missing_pedidos_motivos: array<string, string>
     * }
     */
    private function listPedidosBatchFast(PDO $pdo, array $ctx, int $page, int $perPage): array
    {
        $allRows = $this->fetchBatchRows($pdo, $ctx);
        $foundBatch = $this->needsBatchLookup($ctx)
            ? $this->collectFoundBatchFromResultRows($ctx, $allRows)
            : ['docs' => [], 'pedidos' => [], 'cpfs' => []];

        $total = count($allRows);
        $offset = ($page - 1) * $perPage;
        $rows = array_slice($allRows, $offset, $perPage);
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;
        $missingPedidos = ProtheusSqlHelper::missingFromBatch($ctx['pedidos'], $foundBatch['pedidos']);

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'missing_docs' => ProtheusSqlHelper::missingFromBatch($ctx['docs'], $foundBatch['docs']),
            'missing_pedidos' => $missingPedidos,
            'missing_cpfs' => ProtheusSqlHelper::missingFromBatch($ctx['cpfs'], $foundBatch['cpfs']),
            'missing_pedidos_motivos' => $this->missingPedidosMotivosResumo($pdo, $ctx, $missingPedidos),
        ];
    }

    /**
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>, cpfs: list<string>, saida_filter: bool, batch_ids: bool} $ctx
     * @param list<string> $missingPedidos
     * @return array<string, string>
     */
    private function missingPedidosMotivosResumo(PDO $pdo, array $ctx, array $missingPedidos): array
    {
        if ($missingPedidos === []) {
            return [];
        }

        if (count($ctx['pedidos']) <= self::PEDIDO_FAST_LOOKUP_MAX) {
            return array_fill_keys(
                $missingPedidos,
                'Nao localizado na filial informada (verifique filial e numero do pedido marketplace).'
            );
        }

        return $this->diagnoseMissingPedidos($pdo, $ctx, $missingPedidos);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function rowAlertClass(array $row): string
    {
        $romaneio = $this->cellText($row['ROMANEIO'] ?? null);
        $saida = $this->cellText($row['DT_SAIDA'] ?? null);

        if ($romaneio === '') {
            return 'row-alert-romaneio';
        }
        if ($saida === '') {
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
     * @param list<string> $missingCpfs
     * @param array<string, string> $missingPedidosMotivos
     */
    private function attachMissingExportSheet(
        SimpleXLSXGen $xlsx,
        array $missingDocs,
        array $missingPedidos,
        array $missingCpfs = [],
        array $missingPedidosMotivos = []
    ): void {
        if ($missingDocs === [] && $missingPedidos === [] && $missingCpfs === []) {
            return;
        }

        $hasMotivos = $missingPedidosMotivos !== [];
        $header = [
            $this->exportHeaderCell('Tipo'),
            $this->exportHeaderCell('Valor informado'),
        ];
        if ($hasMotivos) {
            $header[] = $this->exportHeaderCell('Motivo');
        }
        $sheet = [$header];

        foreach ($missingDocs as $value) {
            $row = [
                $this->exportMissingCell('Nota'),
                $this->exportMissingCell($value),
            ];
            if ($hasMotivos) {
                $row[] = $this->exportMissingCell('');
            }
            $sheet[] = $row;
        }
        foreach ($missingPedidos as $value) {
            $row = [
                $this->exportMissingCell('Ped. marketplace'),
                $this->exportMissingCell($value),
            ];
            if ($hasMotivos) {
                $row[] = $this->exportMissingCell($missingPedidosMotivos[$value] ?? '');
            }
            $sheet[] = $row;
        }
        foreach ($missingCpfs as $digits) {
            $row = [
                $this->exportMissingCell('CPF/CNPJ'),
                $this->exportMissingCell(self::formatCpfCnpj($digits)),
            ];
            if ($hasMotivos) {
                $row[] = $this->exportMissingCell('');
            }
            $sheet[] = $row;
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
     * Parametros PDO alinhados ao SQL gerado (sqlsrv rejeita binds extras).
     *
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>, cpfs: list<string>, saida_filter: bool, batch_ids: bool} $ctx
     * @return array<string, string>
     */
    private function queryParams(array $ctx, ?string $sql = null): array
    {
        $params = ProtheusSqlHelper::paramsSemMarketplaceSeLike($ctx['params']);
        if (!empty($ctx['batch_ids'])) {
            unset($params[':emissao_de'], $params[':emissao_ate']);
        }

        if ($sql !== null) {
            return ProtheusSqlHelper::paramsForSql($sql, $params);
        }

        return $params;
    }

    /**
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>, cpfs: list<string>, saida_filter: bool, batch_ids: bool} $ctx
     */
    private function fetchTotal(PDO $pdo, array $ctx): int
    {
        $sql = 'SELECT COUNT(1) AS total FROM (' . $this->baseSql($ctx, 'sf2', 'or', $pdo) . ') AS q';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($this->queryParams($ctx, $sql));
        $row = $stmt->fetch();

        return (int) ($row['total'] ?? 0);
    }

    /**
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>, cpfs: list<string>, saida_filter: bool, batch_ids: bool} $ctx
     */
    private function needsBatchLookup(array $ctx): bool
    {
        return $ctx['docs'] !== [] || $ctx['pedidos'] !== [] || $ctx['cpfs'] !== [];
    }

    /**
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>, cpfs: list<string>, saida_filter: bool, batch_ids: bool} $ctx
     * @return array{docs: list<string>, pedidos: list<string>, cpfs: list<string>}
     */
    private function fetchFoundBatchIdentifiersLite(PDO $pdo, array $ctx): array
    {
        $sf2 = ProtheusSqlHelper::tbl('SF2010', 'SF2');
        $sc5 = ProtheusSqlHelper::tbl('SC5010', 'SC5');
        $za4 = ProtheusSqlHelper::tbl('ZA4010', 'ZA4');
        $sa1 = ProtheusSqlHelper::tbl('SA1010', 'SA1');

        $joins = <<<SQL
FROM {$sf2}
INNER JOIN {$sc5}
    ON SC5.C5_FILIAL = SF2.F2_FILIAL
    AND SC5.C5_NOTA = SF2.F2_DOC
    AND SC5.C5_SERIE = SF2.F2_SERIE
    AND SC5.D_E_L_E_T_ = ' '
LEFT JOIN {$za4}
    ON {$this->za4JoinFromSc5Sql($pdo)}
    AND ZA4.D_E_L_E_T_ = ' '
LEFT JOIN {$sa1}
    ON RTRIM(SA1.A1_COD) = RTRIM(SF2.F2_CLIENTE)
    AND RTRIM(SA1.A1_LOJA) = RTRIM(SF2.F2_LOJA)
    AND (
        RTRIM(SA1.A1_FILIAL) = RTRIM(SF2.F2_FILIAL)
        OR RTRIM(ISNULL(SA1.A1_FILIAL, '')) = ''
    )
    AND SA1.D_E_L_E_T_ = ' '
SQL;

        if (!empty($ctx['saida_filter'])) {
            $gw1 = ProtheusSqlHelper::tbl('GW1010', 'GW1');
            $joins .= <<<SQL

LEFT JOIN {$gw1}
    ON GW1.GW1_FILIAL = SF2.F2_FILIAL
    AND GW1.GW1_NRDC = SF2.F2_DOC
    AND GW1.GW1_SERDC = SF2.F2_SERIE
    AND GW1.D_E_L_E_T_ = ' '
SQL;
        }

        $pedmarCol = $this->za4PedmarColumn($pdo);
        $sql = 'SELECT DISTINCT RTRIM(SF2.F2_DOC) AS batch_doc, '
            . 'COALESCE(NULLIF(RTRIM(SC5.C5_PEDMAR), \'\'), NULLIF(RTRIM(ZA4.' . $pedmarCol . '), \'\')) AS batch_ped, '
            . 'RTRIM(SA1.A1_CGC) AS batch_cpf '
            . $joins
            . $this->baseWhereClause($ctx, $pdo);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($this->queryParams($ctx, $sql));
        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return ['docs' => [], 'pedidos' => [], 'cpfs' => []];
        }

        return $this->collectFoundBatchFromRows($ctx, $rows);
    }

    /**
     * @param array{docs: list<string>, pedidos: list<string>, cpfs: list<string>} $ctx
     * @param list<array<string, mixed>> $rows
     * @return array{docs: list<string>, pedidos: list<string>, cpfs: list<string>}
     */
    private function collectFoundBatchFromRows(array $ctx, array $rows): array
    {
        $foundDocs = [];
        $foundPedidos = [];
        $foundCpfs = [];
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
            if ($ctx['cpfs'] !== []) {
                $cpfDigits = preg_replace('/\D/', '', (string) ($row['batch_cpf'] ?? ''));
                if ($cpfDigits !== '') {
                    foreach ($ctx['cpfs'] as $requested) {
                        if ($requested !== '' && str_contains($cpfDigits, $requested)) {
                            $foundCpfs[$requested] = $requested;
                        }
                    }
                }
            }
        }

        return [
            'docs' => array_values($foundDocs),
            'pedidos' => array_values($foundPedidos),
            'cpfs' => array_values($foundCpfs),
        ];
    }

    /**
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>, cpfs: list<string>, saida_filter: bool, batch_ids: bool} $ctx
     * @return list<array<string, mixed>>
     */
    private function fetchPage(PDO $pdo, array $ctx, int $offset, int $limit): array
    {
        $sql = $this->baseSql($ctx, 'sf2', 'or', $pdo)
            . ' ORDER BY SF2.F2_EMISSAO DESC, SF2.F2_DOC, SF2.F2_SERIE'
            . ' OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY';
        $bind = $this->queryParams($ctx, $sql);

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
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>, cpfs: list<string>, saida_filter: bool, batch_ids: bool} $ctx
     * @return list<array<string, mixed>>
     */
    private function fetchAll(PDO $pdo, array $ctx): array
    {
        $sql = $this->baseSql($ctx, 'sf2', 'or', $pdo)
            . ' ORDER BY SF2.F2_EMISSAO DESC, SF2.F2_DOC, SF2.F2_SERIE';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($this->queryParams($ctx, $sql));
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>, cpfs: list<string>, saida_filter: bool, batch_ids: bool} $ctx
     * @return list<array<string, mixed>>
     */
    private function fetchAllBatch(PDO $pdo, array $ctx, string $joinLead = 'sf2', string $pedidosFilter = 'or'): array
    {
        $sql = $this->baseSql($ctx, $joinLead, $pedidosFilter, $pdo)
            . ' ORDER BY SF2.F2_EMISSAO DESC, SF2.F2_DOC, SF2.F2_SERIE';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($this->queryParams($ctx, $sql));
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * Lote (notas/pedidos): localiza chaves NF leves e enriquece so as linhas encontradas.
     *
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>, cpfs: list<string>, saida_filter: bool, batch_ids: bool} $ctx
     * @return list<array<string, mixed>>
     */
    private function fetchBatchRows(PDO $pdo, array $ctx): array
    {
        if ($ctx['pedidos'] !== []) {
            return $this->fetchBatchRowsForPedidos($pdo, $ctx);
        }

        $keys = $this->resolveDocBatchKeys($pdo, $ctx);
        if ($keys === []) {
            return [];
        }

        return $this->fetchEnrichedByNfKeys($pdo, $ctx, $keys);
    }

    /**
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>, cpfs: list<string>, saida_filter: bool, batch_ids: bool} $ctx
     * @return list<array<string, mixed>>
     */
    private function fetchBatchRowsForPedidos(PDO $pdo, array $ctx): array
    {
        if (count($ctx['pedidos']) <= self::PEDIDO_FAST_LOOKUP_MAX) {
            return $this->fetchBatchRowsForPedidosFast($pdo, $ctx);
        }

        $requested = $ctx['pedidos'];
        $keys = $this->resolvePedidoBatchKeys($pdo, $ctx);
        $rows = $keys === [] ? [] : $this->fetchEnrichedByNfKeys($pdo, $ctx, $keys);
        $rows = $this->filterRowsToRequestedPedidos($rows, $requested);

        $foundPedidos = array_merge(
            $this->pedidosFromResolvedKeys($keys),
            $this->pedidosFromResultRows($rows)
        );
        $stillMissing = ProtheusSqlHelper::missingFromBatch($requested, $foundPedidos);
        if ($stillMissing !== []) {
            $semNf = $this->fetchPedidosSemNfRows($pdo, $ctx, $stillMissing);
            $rows = array_merge($semNf, $rows);
        }

        return $this->dedupeMonitorRows($rows);
    }

    /**
     * Poucos pedidos: 1 leitura SC5 + enrich so das NFs encontradas (sem EXISTS/ZA4 pesado).
     *
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>, cpfs: list<string>, saida_filter: bool, batch_ids: bool} $ctx
     * @return list<array<string, mixed>>
     */
    private function fetchBatchRowsForPedidosFast(PDO $pdo, array $ctx): array
    {
        $requested = $ctx['pedidos'];
        $pedMatch = $this->pedidoLookupMatchClause('SC5.C5_PEDMAR', $requested, 'pf');
        $params = array_merge([':filial' => $ctx['params'][':filial']], $pedMatch['params']);
        $sc5 = ProtheusSqlHelper::tbl('SC5010', 'SC5');
        $top = min(120, max(10, count($requested) * 3));
        $pedmarCol = $this->za4PedmarColumn($pdo);
        $za4Apply = $this->za4OuterApplyFromSc5Sql($pdo, $pedmarCol);

        $sql = 'SELECT TOP ' . $top . '
    RTRIM(SC5.C5_FILIAL) AS filial,
    RTRIM(SC5.C5_NOTA) AS doc,
    RTRIM(SC5.C5_SERIE) AS serie,
    RTRIM(SC5.C5_PEDMAR) AS pedmar,
    SC5.C5_NUM AS ped_interno,
    SC5.C5_ZMAKET AS marketplace,
    RTRIM(SC5.C5_ZIDLEX) AS ID_HUB,
    ' . $this->sc5DataAprovacaoSelectSql(
            $pdo,
            'DT_APROVACAO',
            true,
            $za4Apply['aprov_select'] ?? null
        ) . ',
    SC5.C5_CLIENTE AS cliente,
    SC5.C5_LOJACLI AS loja,
    ' . $this->sc5ValorSelectSql($pdo) . ',
    RTRIM(ISNULL(SC5.C5_TRANSP, \'\')) AS transp,
    RTRIM(ISNULL(T.A4_NOME, \'\')) AS TRANSP_NOME,
    RTRIM(ISNULL(C.A1_NOME, \'\')) AS RAZAO_SOCIAL
FROM ' . $sc5 . '
' . $this->sa1OuterApplyFromSc5Sql() . '
' . $this->sa4OuterApplyFromSc5Sql() . '
' . $za4Apply['sql'] . '
WHERE SC5.C5_FILIAL = :filial
    AND SC5.D_E_L_E_T_ = \' \'
    AND ' . $pedMatch['sql'] . '
ORDER BY SC5.C5_NUM DESC';

        $sc5Rows = $this->executeQuery($pdo, $sql, $params, $top);
        $keys = [];
        $semNfRows = [];
        foreach ($sc5Rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ped = trim((string) ($row['pedmar'] ?? ''));
            if ($ped === '' || !$this->pedidoMatchesRequested($ped, $requested)) {
                continue;
            }
            $doc = trim((string) ($row['doc'] ?? ''));
            if ($doc === '' || $doc === '0') {
                $semNfRows[] = $this->monitorRowFromSc5Lite($row);
                continue;
            }
            $keys[] = [
                'filial' => trim((string) ($row['filial'] ?? '')),
                'doc' => $doc,
                'serie' => trim((string) ($row['serie'] ?? '')),
                'pedmar' => $ped,
            ];
        }

        $keys = $this->normalizeNfKeys($keys, true);
        $rows = $keys === [] ? [] : $this->fetchEnrichedByNfKeys($pdo, $ctx, $keys);
        $rows = $this->filterRowsToRequestedPedidos($rows, $requested);
        $rows = array_merge($semNfRows, $rows);

        $foundPedidos = $this->pedidosFromResultRows($rows);
        $stillMissing = ProtheusSqlHelper::missingFromBatch($requested, $foundPedidos);
        if ($stillMissing !== []) {
            $za4Keys = $this->resolvePedidoKeysFromZa4($pdo, $ctx, $stillMissing);
            if ($za4Keys !== []) {
                $extra = $this->fetchEnrichedByNfKeys($pdo, $ctx, $za4Keys);
                $rows = array_merge($rows, $this->filterRowsToRequestedPedidos($extra, $stillMissing));
            }
        }

        return $this->dedupeMonitorRows($rows);
    }

    /**
     * @param list<string> $requestedPedidos
     * @return array{sql: string, params: array<string, string>}
     */
    private function pedidoLookupMatchClause(string $column, array $requestedPedidos, string $prefix): array
    {
        if (count($requestedPedidos) <= self::PEDIDO_FAST_LOOKUP_MAX) {
            return ProtheusSqlHelper::pedidoMarketplaceMatchFast($column, $requestedPedidos, $prefix);
        }

        return ProtheusSqlHelper::pedidoMarketplaceMatchClause($column, $requestedPedidos, $prefix);
    }

    /**
     * @param list<string> $requestedPedidos
     */
    private function pedidoMatchesRequested(string $ped, array $requestedPedidos): bool
    {
        $key = $this->normalizePedidoMarketplaceKey($ped);
        if ($key === '') {
            return false;
        }
        foreach ($requestedPedidos as $requested) {
            if ($this->normalizePedidoMarketplaceKey($requested) === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function monitorRowFromSc5Lite(array $row): array
    {
        return [
            'F2_FILIAL' => $row['filial'] ?? '',
            'F2_DOC' => '',
            'F2_SERIE' => '',
            'F2_CHVNFE' => '',
            'CPF_CNPJ' => '',
            'F2_EMISSAO' => '',
            'F2_VALBRUT' => $row['valbrut'] ?? null,
            'ROMANEIO' => '',
            'DT_SAIDA' => '',
            'HR_SAIDA' => '',
            'TRANSP_COD' => $row['transp'] ?? '',
            'TRANSP_NOME' => trim((string) ($row['TRANSP_NOME'] ?? $row['transp_nome'] ?? '')),
            'PED_Marketplace' => trim((string) ($row['pedmar'] ?? '')),
            'DT_APROVACAO' => $row['DT_APROVACAO'] ?? '',
            'RAZAO_SOCIAL' => trim((string) ($row['RAZAO_SOCIAL'] ?? '')),
            'ID_HUB' => trim((string) ($row['ID_HUB'] ?? $row['idlexos'] ?? '')),
            'Marketplace' => $row['marketplace'] ?? '',
            'PED_INTERNO' => $row['ped_interno'] ?? '',
            'F2_CLIENTE' => $row['cliente'] ?? '',
            'F2_LOJA' => $row['loja'] ?? '',
            'GW1_SITINT' => '',
        ];
    }

    /**
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>, cpfs: list<string>, saida_filter: bool, batch_ids: bool} $ctx
     * @return list<array{filial: string, doc: string, serie: string}>
     */
    private function resolveDocBatchKeys(PDO $pdo, array $ctx): array
    {
        $docs = $ctx['docs'];
        if ($docs === []) {
            return [];
        }

        $sf2 = ProtheusSqlHelper::tbl('SF2010', 'SF2');
        $docMatch = ProtheusSqlHelper::batchRtrimInClause('SF2.F2_DOC', $docs, 'doc');
        $params = array_merge([':filial' => $ctx['params'][':filial']], $docMatch['params']);

        $sql = 'SELECT TOP 200 RTRIM(SF2.F2_FILIAL) AS filial, RTRIM(SF2.F2_DOC) AS doc, RTRIM(SF2.F2_SERIE) AS serie
FROM ' . $sf2 . '
WHERE SF2.F2_FILIAL = :filial
    AND SF2.D_E_L_E_T_ = \' \'
    AND ' . $docMatch['sql'];

        return $this->normalizeNfKeys($this->executeQuery($pdo, $sql, $params));
    }

    /**
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>, cpfs: list<string>, saida_filter: bool, batch_ids: bool} $ctx
     * @return list<array{filial: string, doc: string, serie: string}>
     */
    private function resolvePedidoBatchKeys(PDO $pdo, array $ctx): array
    {
        $keys = $this->resolvePedidoKeysFromSc5($pdo, $ctx);
        $foundPedidos = $this->pedidosFromResolvedKeys($keys);
        $missingPedidos = ProtheusSqlHelper::missingFromBatch($ctx['pedidos'], $foundPedidos);
        if ($missingPedidos !== []) {
            $keys = $this->mergeNfKeys($keys, $this->resolvePedidoKeysFromZa4($pdo, $ctx, $missingPedidos));
        }

        return $keys;
    }

    /**
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>, cpfs: list<string>, saida_filter: bool, batch_ids: bool} $ctx
     * @return list<array{filial: string, doc: string, serie: string, pedmar: string}>
     */
    private function resolvePedidoKeysFromSc5(PDO $pdo, array $ctx): array
    {
        $pedidos = $ctx['pedidos'];
        if ($pedidos === []) {
            return [];
        }

        $sc5 = ProtheusSqlHelper::tbl('SC5010', 'SC5');
        $pedMatch = $this->pedidoLookupMatchClause('SC5.C5_PEDMAR', $pedidos, 'ped');
        $params = array_merge([':filial' => $ctx['params'][':filial']], $pedMatch['params']);

        $sql = 'SELECT TOP 200
    RTRIM(SC5.C5_FILIAL) AS filial,
    RTRIM(SC5.C5_NOTA) AS doc,
    RTRIM(SC5.C5_SERIE) AS serie,
    RTRIM(SC5.C5_PEDMAR) AS pedmar
FROM ' . $sc5 . '
WHERE SC5.C5_FILIAL = :filial
    AND SC5.D_E_L_E_T_ = \' \'
    AND ' . $pedMatch['sql'] . '
    AND RTRIM(ISNULL(SC5.C5_NOTA, \'\')) NOT IN (\'\', \'0\')';

        return $this->normalizeNfKeys($this->executeQuery($pdo, $sql, $params), true);
    }

    /**
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>, cpfs: list<string>, saida_filter: bool, batch_ids: bool} $ctx
     * @param list<string> $pedidosSubset
     * @return list<array{filial: string, doc: string, serie: string, pedmar: string}>
     */
    private function resolvePedidoKeysFromZa4(PDO $pdo, array $ctx, array $pedidosSubset): array
    {
        if ($pedidosSubset === []) {
            return [];
        }

        $sc5 = ProtheusSqlHelper::tbl('SC5010', 'SC5');
        $za4 = ProtheusSqlHelper::tbl('ZA4010', 'ZA4');
        $pedmarCol = $this->za4PedmarColumn($pdo);
        $za4Join = $this->za4JoinFromSc5Sql($pdo);
        $pedMatch = $this->pedidoLookupMatchClause('ZA4.' . $pedmarCol, array_values($pedidosSubset), 'zped');
        $params = array_merge([':filial' => $ctx['params'][':filial']], $pedMatch['params']);

        $sql = 'SELECT TOP 200
    RTRIM(SC5.C5_FILIAL) AS filial,
    RTRIM(SC5.C5_NOTA) AS doc,
    RTRIM(SC5.C5_SERIE) AS serie,
    RTRIM(ZA4.' . $pedmarCol . ') AS pedmar
FROM ' . $za4 . '
INNER JOIN ' . $sc5 . '
    ON ' . $za4Join . '
    AND SC5.D_E_L_E_T_ = \' \'
WHERE ZA4.ZA4_FILIAL = :filial
    AND ZA4.D_E_L_E_T_ = \' \'
    AND ' . $pedMatch['sql'] . '
    AND RTRIM(ISNULL(SC5.C5_NOTA, \'\')) NOT IN (\'\', \'0\')';

        return $this->normalizeNfKeys($this->executeQuery($pdo, $sql, $params), true);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{filial: string, doc: string, serie: string, pedmar?: string}>
     */
    private function normalizeNfKeys(array $rows, bool $withPedmar = false): array
    {
        $keys = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $filial = trim((string) ($row['filial'] ?? ''));
            $doc = trim((string) ($row['doc'] ?? ''));
            $serie = trim((string) ($row['serie'] ?? ''));
            if ($filial === '' || $doc === '' || $doc === '0') {
                continue;
            }
            $dedupe = strtoupper($filial) . '|' . strtoupper($doc) . '|' . strtoupper($serie);
            if (isset($keys[$dedupe])) {
                continue;
            }
            $entry = ['filial' => $filial, 'doc' => $doc, 'serie' => $serie];
            if ($withPedmar) {
                $entry['pedmar'] = trim((string) ($row['pedmar'] ?? ''));
            }
            $keys[$dedupe] = $entry;
        }

        return array_values($keys);
    }

    /**
     * @param list<array{filial: string, doc: string, serie: string, pedmar?: string}> $primary
     * @param list<array{filial: string, doc: string, serie: string, pedmar?: string}> $extra
     * @return list<array{filial: string, doc: string, serie: string, pedmar?: string}>
     */
    private function mergeNfKeys(array $primary, array $extra): array
    {
        $merged = [];
        foreach ([$primary, $extra] as $set) {
            foreach ($set as $key) {
                $dedupe = strtoupper($key['filial']) . '|' . strtoupper($key['doc']) . '|' . strtoupper($key['serie']);
                if (!isset($merged[$dedupe])) {
                    $merged[$dedupe] = $key;
                }
            }
        }

        return array_values($merged);
    }

    /**
     * @param list<array{filial: string, doc: string, serie: string, pedmar?: string}> $keys
     * @return list<string>
     */
    private function pedidosFromResolvedKeys(array $keys): array
    {
        $found = [];
        foreach ($keys as $key) {
            $ped = trim((string) ($key['pedmar'] ?? ''));
            if ($ped !== '') {
                $found[strtoupper($ped)] = $ped;
            }
        }

        return array_values($found);
    }

    /**
     * @param list<array{filial: string, doc: string, serie: string}> $keys
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>, cpfs: list<string>, saida_filter: bool, batch_ids: bool} $ctx
     * @return list<array<string, mixed>>
     */
    private function fetchEnrichedByNfKeys(PDO $pdo, array $ctx, array $keys): array
    {
        if (count($keys) > 200) {
            $keys = array_slice($keys, 0, 200);
        }

        $sf2 = ProtheusSqlHelper::tbl('SF2010', 'SF2');
        $sc5 = ProtheusSqlHelper::tbl('SC5010', 'SC5');
        $pedmarCol = $this->za4PedmarColumn($pdo);
        $za4Apply = $this->za4OuterApplyFromSc5Sql($pdo, $pedmarCol);

        $bind = $ctx['params'];
        $keyClause = [];
        foreach ($keys as $i => $key) {
            $bind[':nk_f_' . $i] = $key['filial'];
            $bind[':nk_d_' . $i] = $key['doc'];
            $bind[':nk_s_' . $i] = $key['serie'];
            $keyClause[] = '(SF2.F2_FILIAL = :nk_f_' . $i
                . ' AND SF2.F2_DOC = :nk_d_' . $i
                . ' AND SF2.F2_SERIE = :nk_s_' . $i . ')';
        }

        $sql = 'SELECT
    SF2.F2_FILIAL,
    SF2.F2_DOC,
    SF2.F2_SERIE,
    RTRIM(ISNULL(SF2.F2_CHVNFE, \'\')) AS F2_CHVNFE,
    RTRIM(ISNULL(SA1.A1_CGC, \'\')) AS CPF_CNPJ,
    SUBSTRING(SF2.F2_EMISSAO, 7, 2) + \'/\' +
        SUBSTRING(SF2.F2_EMISSAO, 5, 2) + \'/\' +
        SUBSTRING(SF2.F2_EMISSAO, 1, 4) AS F2_EMISSAO,
    SF2.F2_VALBRUT,
    RTRIM(ISNULL(GW1.GW1_NRROM, \'\')) AS ROMANEIO,
    CASE
        WHEN RTRIM(ISNULL(GW1.GW1_DTSAI, \'\')) = \'\' THEN \'\'
        ELSE SUBSTRING(GW1.GW1_DTSAI, 7, 2) + \'/\' +
            SUBSTRING(GW1.GW1_DTSAI, 5, 2) + \'/\' +
            SUBSTRING(GW1.GW1_DTSAI, 1, 4)
    END AS DT_SAIDA,
    RTRIM(ISNULL(GW1.GW1_HRSAI, \'\')) AS HR_SAIDA,
    RTRIM(' . $this->transpCodExprSql() . ') AS TRANSP_COD,
    RTRIM(ISNULL(SA4.A4_NOME, \'\')) AS TRANSP_NOME,
    ' . $za4Apply['pedmar_select'] . ' AS PED_Marketplace,
    ' . $this->sc5DataAprovacaoSelectSql(
            $pdo,
            'DT_APROVACAO',
            true,
            $za4Apply['aprov_select'] ?? null
        ) . ',
    RTRIM(ISNULL(SA1.A1_NOME, \'\')) AS RAZAO_SOCIAL,
    ' . $this->idHubSelectSql($pdo) . ',
    SC5.C5_ZMAKET AS Marketplace,
    SC5.C5_NUM AS PED_INTERNO,
    SF2.F2_CLIENTE,
    SF2.F2_LOJA,
    GW1.GW1_SITINT
FROM ' . $sf2 . '
INNER JOIN ' . $sc5 . '
    ON SC5.C5_FILIAL = SF2.F2_FILIAL
    AND SC5.C5_NOTA = SF2.F2_DOC
    AND SC5.C5_SERIE = SF2.F2_SERIE
    AND SC5.D_E_L_E_T_ = \' \'
' . $za4Apply['sql'] . '
' . $this->gw1OuterApplySql() . '
' . $this->sa1OuterApplySql() . '
' . $this->sa4OuterApplySql() . '
WHERE SF2.D_E_L_E_T_ = \' \'
    AND (' . implode(' OR ', $keyClause) . ')'
            . $this->appendEnrichFilters($ctx, $pdo)
            . ' ORDER BY SF2.F2_EMISSAO DESC, SF2.F2_DOC, SF2.F2_SERIE';

        $maxRows = min(500, max(50, count($keys) * 5));
        $rows = $this->executeQuery($pdo, $sql, ProtheusSqlHelper::paramsForSql($sql, $bind), $maxRows);

        $rows = $this->dedupeEnrichedRowsByNf($rows);
        if ($ctx['pedidos'] !== []) {
            $rows = $this->filterRowsToRequestedPedidos($rows, $ctx['pedidos']);
        }

        return $rows;
    }

    /**
     * Pedido localizado no SC5 mas ainda sem NF emitida (como na tela de Pedidos de Venda do Protheus).
     *
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>, cpfs: list<string>, saida_filter: bool, batch_ids: bool} $ctx
     * @param list<string> $pedidos
     * @return list<array<string, mixed>>
     */
    private function fetchPedidosSemNfRows(PDO $pdo, array $ctx, array $pedidos): array
    {
        if ($pedidos === []) {
            return [];
        }

        $sc5 = ProtheusSqlHelper::tbl('SC5010', 'SC5');
        $pedMatch = $this->pedidoLookupMatchClause('SC5.C5_PEDMAR', $pedidos, 'snf');
        $params = array_merge([':filial' => $ctx['params'][':filial']], $pedMatch['params']);
        $top = min(80, max(5, count($pedidos) * 2));
        $pedmarCol = $this->za4PedmarColumn($pdo);
        $za4Apply = $this->za4OuterApplyFromSc5Sql($pdo, $pedmarCol);

        $sql = 'SELECT TOP ' . $top . '
    RTRIM(SC5.C5_FILIAL) AS filial,
    RTRIM(SC5.C5_PEDMAR) AS pedmar,
    SC5.C5_NUM AS ped_interno,
    SC5.C5_ZMAKET AS marketplace,
    RTRIM(SC5.C5_ZIDLEX) AS ID_HUB,
    ' . $this->sc5DataAprovacaoSelectSql(
            $pdo,
            'DT_APROVACAO',
            true,
            $za4Apply['aprov_select'] ?? null
        ) . ',
    SC5.C5_CLIENTE AS cliente,
    SC5.C5_LOJACLI AS loja,
    ' . $this->sc5ValorSelectSql($pdo) . ',
    RTRIM(ISNULL(SC5.C5_TRANSP, \'\')) AS transp,
    RTRIM(ISNULL(T.A4_NOME, \'\')) AS TRANSP_NOME,
    RTRIM(ISNULL(C.A1_NOME, \'\')) AS RAZAO_SOCIAL
FROM ' . $sc5 . '
' . $this->sa1OuterApplyFromSc5Sql() . '
' . $this->sa4OuterApplyFromSc5Sql() . '
' . $za4Apply['sql'] . '
WHERE SC5.C5_FILIAL = :filial
    AND SC5.D_E_L_E_T_ = \' \'
    AND ' . $pedMatch['sql'] . '
    AND RTRIM(ISNULL(SC5.C5_NOTA, \'\')) IN (\'\', \'0\')
ORDER BY SC5.C5_NUM DESC';

        $rows = [];
        foreach ($this->executeQuery($pdo, $sql, $params, $top) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ped = trim((string) ($row['pedmar'] ?? ''));
            if ($ped === '' || !$this->pedidoMatchesRequested($ped, $pedidos)) {
                continue;
            }
            $rows[] = $this->monitorRowFromSc5Lite($row);
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $requestedPedidos
     * @return list<array<string, mixed>>
     */
    private function filterRowsToRequestedPedidos(array $rows, array $requestedPedidos): array
    {
        if ($requestedPedidos === []) {
            return $rows;
        }

        $wanted = [];
        foreach ($requestedPedidos as $ped) {
            $key = $this->normalizePedidoMarketplaceKey($ped);
            if ($key !== '') {
                $wanted[$key] = true;
            }
        }

        if ($wanted === []) {
            return $rows;
        }

        $filtered = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ped = $this->normalizePedidoMarketplaceKey((string) ($row['PED_Marketplace'] ?? ''));
            if ($ped !== '' && isset($wanted[$ped])) {
                $filtered[] = $row;
            }
        }

        return $filtered;
    }

    private function normalizePedidoMarketplaceKey(string $value): string
    {
        return strtoupper(trim($value));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function pedidosFromResultRows(array $rows): array
    {
        $found = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ped = trim((string) ($row['PED_Marketplace'] ?? ''));
            if ($ped !== '') {
                $found[strtoupper($ped)] = $ped;
            }
        }

        return array_values($found);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function dedupeMonitorRows(array $rows): array
    {
        $byKey = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $doc = trim((string) ($row['F2_DOC'] ?? ''));
            if ($doc === '' || $doc === '0') {
                $key = 'SC5|'
                    . strtoupper(trim((string) ($row['F2_FILIAL'] ?? '')))
                    . '|' . strtoupper(trim((string) ($row['PED_Marketplace'] ?? '')))
                    . '|' . trim((string) ($row['PED_INTERNO'] ?? ''));
            } else {
                $key = 'NF|'
                    . strtoupper(trim((string) ($row['F2_FILIAL'] ?? '')))
                    . '|' . strtoupper($doc)
                    . '|' . strtoupper(trim((string) ($row['F2_SERIE'] ?? '')));
            }
            if (!isset($byKey[$key]) || $this->enrichedRowRank($row) > $this->enrichedRowRank($byKey[$key])) {
                $byKey[$key] = $row;
            }
        }

        return array_values($byKey);
    }

    private function gw1OuterApplySql(): string
    {
        $gw1 = ProtheusSqlHelper::tbl('GW1010', 'G');

        return 'OUTER APPLY (
    SELECT TOP 1
        G.GW1_NRROM,
        G.GW1_DTSAI,
        G.GW1_HRSAI,
        G.GW1_SITINT
    FROM ' . $gw1 . '
    WHERE G.GW1_FILIAL = SF2.F2_FILIAL
        AND G.GW1_NRDC = SF2.F2_DOC
        AND G.GW1_SERDC = SF2.F2_SERIE
        AND G.D_E_L_E_T_ = \' \'
    ORDER BY G.GW1_DTSAI DESC, G.GW1_NRROM DESC
) GW1';
    }

    private function sa1OuterApplySql(): string
    {
        $sa1 = ProtheusSqlHelper::tbl('SA1010', 'C');

        return 'OUTER APPLY (
    SELECT TOP 1
        RTRIM(C.A1_CGC) AS A1_CGC,
        RTRIM(C.A1_NOME) AS A1_NOME
    FROM ' . $sa1 . '
    WHERE RTRIM(C.A1_COD) = RTRIM(SF2.F2_CLIENTE)
        AND RTRIM(C.A1_LOJA) = RTRIM(SF2.F2_LOJA)
        AND (
            RTRIM(C.A1_FILIAL) = RTRIM(SF2.F2_FILIAL)
            OR RTRIM(ISNULL(C.A1_FILIAL, \'\')) = \'\'
        )
        AND C.D_E_L_E_T_ = \' \'
    ORDER BY CASE WHEN RTRIM(C.A1_FILIAL) = RTRIM(SF2.F2_FILIAL) THEN 0 ELSE 1 END
) SA1';
    }

    private function sa1OuterApplyFromSc5Sql(): string
    {
        $sa1 = ProtheusSqlHelper::tbl('SA1010', 'C');

        return 'OUTER APPLY (
    SELECT TOP 1 RTRIM(C.A1_NOME) AS A1_NOME
    FROM ' . $sa1 . '
    WHERE RTRIM(C.A1_COD) = RTRIM(SC5.C5_CLIENTE)
        AND RTRIM(C.A1_LOJA) = RTRIM(SC5.C5_LOJACLI)
        AND (
            RTRIM(C.A1_FILIAL) = RTRIM(SC5.C5_FILIAL)
            OR RTRIM(ISNULL(C.A1_FILIAL, \'\')) = \'\'
        )
        AND C.D_E_L_E_T_ = \' \'
    ORDER BY CASE WHEN RTRIM(C.A1_FILIAL) = RTRIM(SC5.C5_FILIAL) THEN 0 ELSE 1 END
) C';
    }

    private function transpCodExprSql(string $nfAlias = 'SF2', string $sc5Alias = 'SC5'): string
    {
        return 'COALESCE(NULLIF(RTRIM(' . $nfAlias . '.F2_TRANSP), \'\'), NULLIF(RTRIM(' . $sc5Alias . '.C5_TRANSP), \'\'))';
    }

    private function sa4MatchWhereSql(string $codExpr, string $filialExpr, string $alias = 'T'): string
    {
        return '('
            . 'RTRIM(' . $alias . '.A4_COD) = RTRIM(' . $codExpr . ')'
            . ' OR RIGHT(REPLICATE(\'0\', 6) + RTRIM(' . $alias . '.A4_COD), 6) = RIGHT(REPLICATE(\'0\', 6) + RTRIM(' . $codExpr . '), 6)'
            . ')'
            . ' AND ' . $alias . '.D_E_L_E_T_ = \' \''
            . ' AND ('
            . 'RTRIM(' . $alias . '.A4_FILIAL) = RTRIM(' . $filialExpr . ')'
            . ' OR RTRIM(ISNULL(' . $alias . '.A4_FILIAL, \'\')) = \'\''
            . ')';
    }

    private function sa4OuterApplySql(): string
    {
        $sa4 = ProtheusSqlHelper::tbl('SA4010', 'T');
        $cod = $this->transpCodExprSql();

        return 'OUTER APPLY (
    SELECT TOP 1 RTRIM(T.A4_NOME) AS A4_NOME
    FROM ' . $sa4 . '
    WHERE ' . $this->sa4MatchWhereSql($cod, 'SF2.F2_FILIAL', 'T') . '
    ORDER BY CASE WHEN RTRIM(T.A4_FILIAL) = RTRIM(SF2.F2_FILIAL) THEN 0 ELSE 1 END
) SA4';
    }

    private function sa4OuterApplyFromSc5Sql(): string
    {
        $sa4 = ProtheusSqlHelper::tbl('SA4010', 'T');

        return 'OUTER APPLY (
    SELECT TOP 1 RTRIM(T.A4_NOME) AS A4_NOME
    FROM ' . $sa4 . '
    WHERE ' . $this->sa4MatchWhereSql('SC5.C5_TRANSP', 'SC5.C5_FILIAL', 'T') . '
    ORDER BY CASE WHEN RTRIM(T.A4_FILIAL) = RTRIM(SC5.C5_FILIAL) THEN 0 ELSE 1 END
) T';
    }

    /**
     * @return array{sql: string, pedmar_select: string}
     */
    private function za4OuterApplyFromSc5Sql(PDO $pdo, string $pedmarCol): array
    {
        $za4 = ProtheusSqlHelper::tbl('ZA4010', 'Z');
        $joinCond = str_replace('ZA4.', 'Z.', $this->za4JoinFromSc5Sql($pdo));
        $za4Select = ['RTRIM(Z.' . $pedmarCol . ') AS za4_pedmar'];
        $za4AprovCol = $this->za4AprovacaoColumn($pdo);
        if ($za4AprovCol !== '') {
            $za4Select[] = 'RTRIM(Z.' . $za4AprovCol . ') AS za4_dtapro';
        }

        return [
            'sql' => 'OUTER APPLY (
    SELECT TOP 1 ' . implode(', ', $za4Select) . '
    FROM ' . $za4 . '
    WHERE ' . $joinCond . '
        AND Z.D_E_L_E_T_ = \' \'
) ZA4',
            'pedmar_select' => 'COALESCE(NULLIF(RTRIM(SC5.C5_PEDMAR), \'\'), NULLIF(RTRIM(ZA4.za4_pedmar), \'\'))',
            'aprov_select' => $za4AprovCol !== ''
                ? "NULLIF(RTRIM(ZA4.za4_dtapro), '')"
                : null,
        ];
    }

    /**
     * Uma linha por NF (filial + doc + serie); prioriza registro com romaneio/saida preenchidos.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function dedupeEnrichedRowsByNf(array $rows): array
    {
        $byNf = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = strtoupper(trim((string) ($row['F2_FILIAL'] ?? '')))
                . '|' . strtoupper(trim((string) ($row['F2_DOC'] ?? '')))
                . '|' . strtoupper(trim((string) ($row['F2_SERIE'] ?? '')));
            if ($key === '||') {
                continue;
            }
            if (!isset($byNf[$key]) || $this->enrichedRowRank($row) > $this->enrichedRowRank($byNf[$key])) {
                $byNf[$key] = $row;
            }
        }

        return array_values($byNf);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function enrichedRowRank(array $row): int
    {
        $rank = 0;
        if (trim((string) ($row['ROMANEIO'] ?? '')) !== '') {
            $rank += 4;
        }
        if (trim((string) ($row['DT_SAIDA'] ?? '')) !== '') {
            $rank += 2;
        }
        if (trim((string) ($row['PED_Marketplace'] ?? '')) !== '') {
            $rank += 1;
        }

        return $rank;
    }

    /**
     * Filtros opcionais aplicados apos localizar as NFs (volume pequeno).
     *
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>, cpfs: list<string>, saida_filter: bool, batch_ids: bool} $ctx
     */
    private function appendEnrichFilters(array $ctx, PDO $pdo): string
    {
        $params = $ctx['params'];
        $sql = '';

        if ($ctx['cpfs'] !== []) {
            $cgcDigitsSql = "REPLACE(REPLACE(REPLACE(REPLACE(RTRIM(ISNULL(SA1.A1_CGC, '')), '.', ''), '-', ''), '/', ''), ' ', '')";
            $conditions = [];
            foreach (array_keys($ctx['cpfs']) as $i) {
                $conditions[] = $cgcDigitsSql . " LIKE '%' + :cpf_" . $i . " + '%'";
            }
            $sql .= ' AND (' . implode(' OR ', $conditions) . ')';
        }

        if (!empty($ctx['saida_filter'])) {
            $sql .= "
    AND RTRIM(ISNULL(GW1.GW1_DTSAI, '')) <> ''
    AND GW1.GW1_DTSAI BETWEEN :saida_de AND :saida_ate";
        }

        if (isset($params[':marketplace'])) {
            $sql .= ProtheusSqlHelper::marketplaceAndSql($params[':marketplace']);
        }

        return $sql;
    }

    /**
     * @param array<string, string> $params
     * @return list<array<string, mixed>>
     */
    private function executeQuery(PDO $pdo, string $sql, array $params, ?int $maxRows = null): array
    {
        $limit = $maxRows ?? self::MAX_RESULT_ROWS;
        $stmt = $pdo->prepare($sql);
        if (defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) {
            $stmt->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, self::QUERY_TIMEOUT_SEC);
        }
        $stmt->execute($params);

        $rows = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rows[] = $row;
            if (count($rows) > $limit) {
                throw new \RuntimeException(
                    'Consulta retornou volume excessivo de linhas. Reduza a lista de pedidos ou notas (max. 100 por campo).'
                );
            }
        }

        return $rows;
    }

    /**
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>, cpfs: list<string>, saida_filter: bool, batch_ids: bool} $ctx
     */
    private function assertPedidoOrDocBatchRequired(array $ctx): void
    {
        if ($ctx['docs'] !== [] || $ctx['pedidos'] !== []) {
            return;
        }

        throw new \RuntimeException(
            'Informe pelo menos uma nota (Doc) ou pedido marketplace em lote. '
            . 'Consulta apenas por periodo de emissao, marketplace ou CPF nao e permitida neste monitor.'
        );
    }

    private function applyQueryTimeout(PDO $pdo): void
    {
        if (defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) {
            $pdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, self::QUERY_TIMEOUT_SEC);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{docs: list<string>, pedidos: list<string>, cpfs: list<string>}
     */
    private function collectFoundBatchFromResultRows(array $ctx, array $rows): array
    {
        $mapped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mapped[] = [
                'batch_doc' => $row['F2_DOC'] ?? '',
                'batch_ped' => $row['PED_Marketplace'] ?? '',
                'batch_cpf' => $row['CPF_CNPJ'] ?? '',
            ];
        }

        return $this->collectFoundBatchFromRows($ctx, $mapped);
    }

    private function exportDirectory(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ml-portal-protheus';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Nao foi possivel criar pasta temporaria para exportacao.');
        }

        return $dir;
    }

    /**
     * @return array{params: array<string, string>, docs: list<string>, pedidos: list<string>, cpfs: list<string>, saida_filter: bool}
     */
    private function buildFilterContext(
        string $filial,
        string $emissaoDe,
        string $emissaoAte,
        string $marketplace,
        string $docsCsv = '',
        string $pedidosCsv = '',
        string $cpfCnpj = '',
        string $saidaDe = '',
        string $saidaAte = ''
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

        $cpfs = $this->parseCpfCnpjBatchFilter($cpfCnpj);
        foreach ($cpfs as $i => $digits) {
            $params[':cpf_' . $i] = $digits;
        }

        $saidaDeNorm = $this->normalizeOptionalProtheusDate($saidaDe);
        $saidaAteNorm = $this->normalizeOptionalProtheusDate($saidaAte);
        if ($saidaDeNorm !== '' && $saidaAteNorm !== '') {
            $params[':saida_de'] = $saidaDeNorm;
            $params[':saida_ate'] = $saidaAteNorm;
        }

        $docs = $this->parseBatchFilter($docsCsv);
        $pedidos = $this->parseBatchFilter($pedidosCsv);
        foreach ($docs as $i => $doc) {
            $params[':doc_' . $i] = $doc;
        }
        foreach ($pedidos as $i => $ped) {
            $params[':ped_' . $i] = $ped;
            $params[':ped_z_' . $i] = $ped;
        }

        return [
            'params' => $params,
            'docs' => $docs,
            'pedidos' => $pedidos,
            'cpfs' => $cpfs,
            'saida_filter' => $saidaDeNorm !== '' && $saidaAteNorm !== '',
            'batch_ids' => $docs !== [] || $pedidos !== [],
        ];
    }

    /** @return array{join_sql: string, pedmar_col: string, notes: list<string>} */
    private function za4JoinMeta(PDO $pdo): array
    {
        if ($this->za4JoinMeta !== null) {
            return $this->za4JoinMeta;
        }

        $settings = $this->connectionService->getConfiguredSettings();
        $cacheKey = md5(
            trim((string) ($settings['host'] ?? ''))
            . '|' . trim((string) ($settings['database_name'] ?? ''))
        );
        $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ml-portal-protheus';
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'za4-join-' . $cacheKey . '.json';
        if (is_file($cacheFile)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['join_sql'], $cached['pedmar_col'])) {
                $this->za4JoinMeta = $cached;

                return $this->za4JoinMeta;
            }
        }

        $this->za4JoinMeta = ProtheusZa4LexosSchema::resolveZa4JoinFromSc5($pdo);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0700, true);
        }
        file_put_contents($cacheFile, json_encode($this->za4JoinMeta, JSON_UNESCAPED_UNICODE));

        return $this->za4JoinMeta;
    }

    private function za4JoinFromSc5Sql(PDO $pdo): string
    {
        return $this->za4JoinMeta($pdo)['join_sql'];
    }

    private function za4PedmarColumn(PDO $pdo): string
    {
        return $this->za4JoinMeta($pdo)['pedmar_col'];
    }

    private function sc5ValorSelectSql(PDO $pdo): string
    {
        if ($this->sc5ValorCol === null) {
            $this->sc5ValorCol = ProtheusZa4LexosSchema::resolveSc5ValorColumn($pdo) ?? '';
        }

        if ($this->sc5ValorCol === '') {
            return '0 AS valbrut';
        }

        return 'SC5.' . $this->sc5ValorCol . ' AS valbrut';
    }

    private function sc5AprovacaoColumn(PDO $pdo): string
    {
        if ($this->sc5AprovacaoCol === null) {
            $this->sc5AprovacaoCol = ProtheusZa4LexosSchema::resolveSc5DataAprovacaoColumn($pdo) ?? '';
        }

        return $this->sc5AprovacaoCol;
    }

    private function za4AprovacaoColumn(PDO $pdo): string
    {
        if ($this->za4AprovacaoCol === null) {
            $this->za4AprovacaoCol = ProtheusZa4LexosSchema::resolveZa4DataAprovacaoColumn($pdo) ?? '';
        }

        return $this->za4AprovacaoCol;
    }

    private function sc5DataAprovacaoSelectSql(
        PDO $pdo,
        string $alias = 'DT_APROVACAO',
        bool $withZa4 = false,
        ?string $za4AprovExpr = null
    ): string {
        $parts = [];
        $sc5Col = $this->sc5AprovacaoColumn($pdo);
        if ($sc5Col !== '') {
            $parts[] = "NULLIF(RTRIM(SC5.{$sc5Col}), '')";
        }
        if ($withZa4) {
            if ($za4AprovExpr !== null && $za4AprovExpr !== '') {
                $parts[] = $za4AprovExpr;
            } else {
                $za4Col = $this->za4AprovacaoColumn($pdo);
                if ($za4Col !== '') {
                    $parts[] = "NULLIF(RTRIM(ZA4.{$za4Col}), '')";
                }
            }
        }
        if ($parts === []) {
            return "'' AS {$alias}";
        }

        return 'COALESCE(' . implode(', ', $parts) . ", '') AS {$alias}";
    }

    private function sf2IdHubColumn(PDO $pdo): string
    {
        if ($this->sf2IdHubCol === null) {
            $this->sf2IdHubCol = ProtheusZa4LexosSchema::resolveSf2IdHubColumn($pdo) ?? '';
        }

        return $this->sf2IdHubCol;
    }

    private function idHubSelectSql(PDO $pdo, bool $withSf2 = true): string
    {
        $parts = [];
        if ($withSf2) {
            $sf2Col = $this->sf2IdHubColumn($pdo);
            if ($sf2Col !== '') {
                $parts[] = "NULLIF(RTRIM(SF2.{$sf2Col}), '')";
            }
        }
        $parts[] = "NULLIF(RTRIM(SC5.C5_ZIDLEX), '')";

        return 'COALESCE(' . implode(', ', $parts) . ", '') AS ID_HUB";
    }

    /**
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>, cpfs: list<string>, saida_filter: bool, batch_ids: bool} $ctx
     * @param list<string> $missingPedidos
     * @return array<string, string>
     */
    private function diagnoseMissingPedidos(PDO $pdo, array $ctx, array $missingPedidos): array
    {
        if ($missingPedidos === []) {
            return [];
        }

        $filial = $ctx['params'][':filial'];
        $emissaoDe = $ctx['params'][':emissao_de'] ?? '';
        $emissaoAte = $ctx['params'][':emissao_ate'] ?? '';
        $motivos = array_fill_keys($missingPedidos, 'Nao localizado em SC5/ZA4 nesta filial (verifique filial e numero informado).');

        $sc5 = ProtheusSqlHelper::tbl('SC5010', 'SC5');
        $sf2 = ProtheusSqlHelper::tbl('SF2010', 'SF2');
        $za4 = ProtheusSqlHelper::tbl('ZA4010', 'ZA4');
        $za4Join = $this->za4JoinFromSc5Sql($pdo);
        $pedmarCol = $this->za4PedmarColumn($pdo);

        $sc5Match = ProtheusSqlHelper::pedidoMarketplaceMatchClause('SC5.C5_PEDMAR', $missingPedidos, 'dm');
        $za4Match = ProtheusSqlHelper::pedidoMarketplaceMatchClause('ZA4.' . $pedmarCol, $missingPedidos, 'dmz');
        $params = array_merge([':filial' => $filial], $sc5Match['params'], $za4Match['params']);

        $sql = 'SELECT
            RTRIM(SC5.C5_PEDMAR) AS c5_pedmar,
            RTRIM(SC5.C5_NOTA) AS c5_nota,
            RTRIM(SF2.F2_DOC) AS f2_doc,
            RTRIM(SF2.F2_EMISSAO) AS f2_emissao,
            RTRIM(SF2.F2_TRANSP) AS f2_transp,
            RTRIM(ZA4.' . $pedmarCol . ') AS za4_pedmar
        FROM ' . $sc5 . '
        LEFT JOIN ' . $za4 . '
            ON ' . $za4Join . '
            AND ZA4.D_E_L_E_T_ = \' \'
        LEFT JOIN ' . $sf2 . '
            ON SF2.F2_FILIAL = SC5.C5_FILIAL
            AND RTRIM(SF2.F2_DOC) = RTRIM(SC5.C5_NOTA)
            AND RTRIM(SF2.F2_SERIE) = RTRIM(SC5.C5_SERIE)
            AND SF2.D_E_L_E_T_ = \' \'
        WHERE SC5.C5_FILIAL = :filial
            AND SC5.D_E_L_E_T_ = \' \'
            AND (' . $sc5Match['sql'] . ' OR ' . $za4Match['sql'] . ')
        ORDER BY SC5.C5_NUM DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return $motivos;
        }

        $byPed = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ([trim((string) ($row['c5_pedmar'] ?? '')), trim((string) ($row['za4_pedmar'] ?? ''))] as $keyPed) {
                if ($keyPed === '') {
                    continue;
                }
                $upper = strtoupper($keyPed);
                if (!isset($byPed[$upper])) {
                    $byPed[$upper] = $row;
                }
            }
        }

        foreach ($missingPedidos as $ped) {
            $row = $byPed[strtoupper($ped)] ?? null;
            if (!is_array($row)) {
                continue;
            }

            $c5Nota = trim((string) ($row['c5_nota'] ?? ''));
            $f2Doc = trim((string) ($row['f2_doc'] ?? ''));
            $f2Emissao = trim((string) ($row['f2_emissao'] ?? ''));
            $f2Transp = trim((string) ($row['f2_transp'] ?? ''));
            $c5Pedmar = trim((string) ($row['c5_pedmar'] ?? ''));
            $za4Pedmar = trim((string) ($row['za4_pedmar'] ?? ''));

            if ($c5Nota === '' || $c5Nota === '0') {
                $motivos[$ped] = 'Pedido existe em SC5 mas ainda nao faturado (sem nota vinculada).';
                continue;
            }

            if ($f2Doc === '') {
                $motivos[$ped] = 'Pedido vinculado a nota ' . $c5Nota . ', mas NF nao encontrada em SF2.';
                continue;
            }

            if (in_array($f2Transp, self::EXCLUDED_TRANSPORTES, true)) {
                $motivos[$ped] = 'NF ' . $f2Doc . ' com transportadora excluida do monitor (cod. ' . $f2Transp . ').';
                continue;
            }

            if ($emissaoDe !== '' && $emissaoAte !== '' && $f2Emissao !== ''
                && ($f2Emissao < $emissaoDe || $f2Emissao > $emissaoAte)) {
                $motivos[$ped] = 'NF ' . $f2Doc . ' emitida em '
                    . $this->formatProtheusDateDisplay($f2Emissao)
                    . ' (fora do periodo de emissao do filtro).';
                continue;
            }

            $stored = $c5Pedmar !== '' ? $c5Pedmar : $za4Pedmar;
            if ($stored !== '' && strtoupper($stored) !== strtoupper($ped)) {
                $motivos[$ped] = 'Encontrado com numero diferente no Protheus: ' . $stored . '.';
                continue;
            }

            $motivos[$ped] = 'Existe NF ' . $f2Doc . ' — verifique outros filtros ativos (marketplace, CPF, data saida).';
        }

        return $motivos;
    }

    private function formatProtheusDateDisplay(string $yyyymmdd): string
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $yyyymmdd, $m)) {
            return $m[3] . '/' . $m[2] . '/' . $m[1];
        }

        return $yyyymmdd;
    }

    /**
     * @return list<string> apenas digitos (minimo 3), ate 100 itens
     */
    public function parseCpfCnpjBatchFilter(string $input, int $maxItems = 100): array
    {
        $unique = [];
        foreach ($this->parseBatchFilter($input, $maxItems) as $item) {
            $digits = preg_replace('/\D/', '', $item);
            if (strlen($digits) >= 3 && !isset($unique[$digits])) {
                $unique[$digits] = $digits;
            }
        }

        return array_values($unique);
    }

    private function normalizeOptionalProtheusDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^\d{8}$/', $value)) {
            return $value;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return str_replace('-', '', $value);
        }

        return '';
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
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>, cpfs: list<string>, saida_filter: bool} $ctx
     */
    private function baseWhereClause(array $ctx, PDO $pdo, string $joinLead = 'sf2', string $pedidosFilter = 'or'): string
    {
        $params = $ctx['params'];
        $docs = $ctx['docs'];
        $pedidos = $ctx['pedidos'];
        $cpfs = $ctx['cpfs'];
        $batchIds = !empty($ctx['batch_ids']);
        $transports = implode(',', array_map(
            static fn (string $code): string => "'" . str_replace("'", "''", $code) . "'",
            self::EXCLUDED_TRANSPORTES
        ));

        if ($joinLead === 'sc5') {
            $sql = <<<SQL
WHERE SC5.C5_FILIAL = :filial
    AND SC5.D_E_L_E_T_ = ' '
    AND SF2.D_E_L_E_T_ = ' '
SQL;
        } elseif ($joinLead === 'za4') {
            $sql = <<<SQL
WHERE ZA4.ZA4_FILIAL = :filial
    AND ZA4.D_E_L_E_T_ = ' '
    AND SC5.D_E_L_E_T_ = ' '
    AND SF2.D_E_L_E_T_ = ' '
SQL;
        } else {
            $sql = <<<SQL
WHERE SF2.D_E_L_E_T_ = ' '
    AND SF2.F2_FILIAL = :filial
SQL;
        }

        if (!$batchIds) {
            $sql .= "
    AND SF2.F2_TRANSP NOT IN ({$transports})
    AND SF2.F2_EMISSAO BETWEEN :emissao_de AND :emissao_ate";
        }

        if ($cpfs !== []) {
            $cgcDigitsSql = "REPLACE(REPLACE(REPLACE(REPLACE(RTRIM(ISNULL(SA1.A1_CGC, '')), '.', ''), '-', ''), '/', ''), ' ', '')";
            $conditions = [];
            foreach (array_keys($cpfs) as $i) {
                $conditions[] = $cgcDigitsSql . " LIKE '%' + :cpf_" . $i . " + '%'";
            }
            $sql .= ' AND (' . implode(' OR ', $conditions) . ')';
        }

        if (!empty($ctx['saida_filter'])) {
            $sql .= "
    AND RTRIM(ISNULL(GW1.GW1_DTSAI, '')) <> ''
    AND GW1.GW1_DTSAI BETWEEN :saida_de AND :saida_ate";
        }

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

        if ($pedidos !== [] && $pedidosFilter !== 'none') {
            $pedmarCol = $this->za4PedmarColumn($pdo);
            if ($pedidosFilter === 'sc5') {
                $sc5Placeholders = [];
                foreach (array_keys($pedidos) as $i) {
                    $sc5Placeholders[] = ':ped_' . $i;
                }
                $sql .= ' AND RTRIM(SC5.C5_PEDMAR) IN (' . implode(', ', $sc5Placeholders) . ')';
            } elseif ($pedidosFilter === 'za4') {
                $za4Placeholders = [];
                foreach (array_keys($pedidos) as $i) {
                    $za4Placeholders[] = ':ped_z_' . $i;
                }
                $sql .= ' AND RTRIM(ZA4.' . $pedmarCol . ') IN (' . implode(', ', $za4Placeholders) . ')';
            } else {
                $sc5Placeholders = [];
                $za4Placeholders = [];
                foreach (array_keys($pedidos) as $i) {
                    $sc5Placeholders[] = ':ped_' . $i;
                    $za4Placeholders[] = ':ped_z_' . $i;
                }
                $sql .= ' AND (RTRIM(SC5.C5_PEDMAR) IN (' . implode(', ', $sc5Placeholders) . ')'
                    . ' OR RTRIM(ZA4.' . $pedmarCol . ') IN (' . implode(', ', $za4Placeholders) . '))';
            }
        }

        return $sql;
    }

    /**
     * @param array{params: array<string, string>, docs: list<string>, pedidos: list<string>, cpfs: list<string>, saida_filter: bool} $ctx
     */
    private function baseSql(array $ctx, string $joinLead = 'sf2', string $pedidosFilter = 'or', ?PDO $pdo = null): string
    {
        $pdo ??= $this->connectionService->connect();
        $sf2 = ProtheusSqlHelper::tbl('SF2010', 'SF2');
        $gw1 = ProtheusSqlHelper::tbl('GW1010', 'GW1');
        $sc5 = ProtheusSqlHelper::tbl('SC5010', 'SC5');
        $sa1 = ProtheusSqlHelper::tbl('SA1010', 'SA1');
        $sa4 = ProtheusSqlHelper::tbl('SA4010', 'SA4');
        $za4 = ProtheusSqlHelper::tbl('ZA4010', 'ZA4');
        $za4Join = $this->za4JoinFromSc5Sql($pdo);
        $pedmarCol = $this->za4PedmarColumn($pdo);
        $aprovSql = $this->sc5DataAprovacaoSelectSql($pdo, 'DT_APROVACAO', true);
        $idHubSql = $this->idHubSelectSql($pdo);
        $transpCodSql = $this->transpCodExprSql();

        $select = <<<SQL
SELECT
    SF2.F2_FILIAL,
    SF2.F2_DOC,
    SF2.F2_SERIE,
    RTRIM(ISNULL(SF2.F2_CHVNFE, '')) AS F2_CHVNFE,
    RTRIM(SA1.A1_CGC) AS CPF_CNPJ,
    SUBSTRING(SF2.F2_EMISSAO, 7, 2) + '/' +
        SUBSTRING(SF2.F2_EMISSAO, 5, 2) + '/' +
        SUBSTRING(SF2.F2_EMISSAO, 1, 4) AS F2_EMISSAO,
    SF2.F2_VALBRUT,
    RTRIM(ISNULL(GW1.GW1_NRROM, '')) AS ROMANEIO,
    CASE
        WHEN RTRIM(ISNULL(GW1.GW1_DTSAI, '')) = '' THEN ''
        ELSE SUBSTRING(GW1.GW1_DTSAI, 7, 2) + '/' +
            SUBSTRING(GW1.GW1_DTSAI, 5, 2) + '/' +
            SUBSTRING(GW1.GW1_DTSAI, 1, 4)
    END AS DT_SAIDA,
    RTRIM(ISNULL(GW1.GW1_HRSAI, '')) AS HR_SAIDA,
    RTRIM({$transpCodSql}) AS TRANSP_COD,
    RTRIM(ISNULL(SA4.A4_NOME, '')) AS TRANSP_NOME,
    COALESCE(NULLIF(RTRIM(SC5.C5_PEDMAR), ''), NULLIF(RTRIM(ZA4.{$pedmarCol}), '')) AS PED_Marketplace,
    {$aprovSql},
    RTRIM(ISNULL(SA1.A1_NOME, '')) AS RAZAO_SOCIAL,
    {$idHubSql},
    SC5.C5_ZMAKET AS Marketplace,
    SC5.C5_NUM AS PED_INTERNO,
    SF2.F2_CLIENTE,
    SF2.F2_LOJA,
    GW1.GW1_SITINT

SQL;

        $supplementalJoins = <<<SQL
LEFT JOIN {$gw1}
    ON GW1.GW1_FILIAL = SF2.F2_FILIAL
    AND GW1.GW1_NRDC = SF2.F2_DOC
    AND GW1.GW1_SERDC = SF2.F2_SERIE
    AND GW1.D_E_L_E_T_ = ' '
LEFT JOIN {$sa1}
    ON RTRIM(SA1.A1_COD) = RTRIM(SF2.F2_CLIENTE)
    AND RTRIM(SA1.A1_LOJA) = RTRIM(SF2.F2_LOJA)
    AND (
        RTRIM(SA1.A1_FILIAL) = RTRIM(SF2.F2_FILIAL)
        OR RTRIM(ISNULL(SA1.A1_FILIAL, '')) = ''
    )
    AND SA1.D_E_L_E_T_ = ' '
LEFT JOIN {$sa4}
    ON (
        RTRIM(SA4.A4_COD) = RTRIM({$transpCodSql})
        OR RIGHT(REPLICATE('0', 6) + RTRIM(SA4.A4_COD), 6) = RIGHT(REPLICATE('0', 6) + RTRIM({$transpCodSql}), 6)
    )
    AND SA4.D_E_L_E_T_ = ' '
    AND (
        RTRIM(SA4.A4_FILIAL) = RTRIM(SF2.F2_FILIAL)
        OR RTRIM(ISNULL(SA4.A4_FILIAL, '')) = ''
    )
SQL;

        if ($joinLead === 'sc5') {
            $from = <<<SQL
FROM {$sc5}
INNER JOIN {$sf2}
    ON SC5.C5_FILIAL = SF2.F2_FILIAL
    AND SC5.C5_NOTA = SF2.F2_DOC
    AND SC5.C5_SERIE = SF2.F2_SERIE
    AND SF2.D_E_L_E_T_ = ' '
LEFT JOIN {$za4}
    ON {$za4Join}
    AND ZA4.D_E_L_E_T_ = ' '
{$supplementalJoins}
SQL;
        } elseif ($joinLead === 'za4') {
            $from = <<<SQL
FROM {$za4}
INNER JOIN {$sc5}
    ON {$za4Join}
    AND SC5.D_E_L_E_T_ = ' '
INNER JOIN {$sf2}
    ON SC5.C5_FILIAL = SF2.F2_FILIAL
    AND SC5.C5_NOTA = SF2.F2_DOC
    AND SC5.C5_SERIE = SF2.F2_SERIE
    AND SF2.D_E_L_E_T_ = ' '
{$supplementalJoins}
SQL;
        } else {
            $from = <<<SQL
FROM {$sf2}
INNER JOIN {$sc5}
    ON SC5.C5_FILIAL = SF2.F2_FILIAL
    AND SC5.C5_NOTA = SF2.F2_DOC
    AND SC5.C5_SERIE = SF2.F2_SERIE
    AND SC5.D_E_L_E_T_ = ' '
LEFT JOIN {$za4}
    ON {$za4Join}
    AND ZA4.D_E_L_E_T_ = ' '
{$supplementalJoins}
SQL;
        }

        return $select . $from . $this->baseWhereClause($ctx, $pdo, $joinLead, $pedidosFilter);
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
        if ($columnKey === 'DT_APROVACAO') {
            return self::formatDataAprovacao($value);
        }

        $text = $this->cellText($value);

        if ($columnKey === 'ROMANEIO' && $text === '') {
            return 'SEM Nº ROMANEIO';
        }
        if ($columnKey === 'DT_SAIDA' && $text === '') {
            return 'SEM DATA SAIDA';
        }

        return $text;
    }

    public function displayCellHtml(string $columnKey, mixed $value): string
    {
        $raw = $this->cellText($value);

        if ($columnKey === 'ROMANEIO' && $raw === '') {
            return '<span class="cell-empty-alert">SEM Nº ROMANEIO</span>';
        }
        if ($columnKey === 'DT_SAIDA' && $raw === '') {
            return '<span class="cell-empty-alert">SEM DATA SAIDA</span>';
        }

        return htmlspecialchars($this->displayCellText($columnKey, $value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function isEmptyAlertCell(string $columnKey, string $displayText): bool
    {
        return ($columnKey === 'ROMANEIO' && $displayText === 'SEM Nº ROMANEIO')
            || ($columnKey === 'DT_SAIDA' && $displayText === 'SEM DATA SAIDA');
    }

    private function cellText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }
}
