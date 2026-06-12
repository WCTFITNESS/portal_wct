<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Shuchkin\SimpleXLSXGen;

class ProtheusNfeMonitorService
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

  private const COLOR_HEADER = 'F1F5F9';
  private const COLOR_ROW_AUTORIZADA = 'DCFCE7';
  private const COLOR_ROW_REJEITADA = 'FEE2E2';
  private const COLOR_ROW_PENDENTE = 'FEF9C3';

  /** cStat SEFAZ e status TSS (SPED050) considerados autorizados. */
  private const SEFAZ_COD_AUTORIZADO = ['100', '150', '135', '151', '6'];

  /** Status TSS / retornos tipicos de erro ou rejeicao. */
  private const SEFAZ_COD_REJEITADO = ['3', '5'];

  /** @var array<string, mixed>|false|null */
  private array|false|null $sefazLogConfig = null;

  public function __construct(
    private ProtheusConnectionService $connectionService
  ) {
  }

  /** @return array<string, string> */
  public static function exportColumns(): array
  {
    return [
      'F2_FILIAL' => 'Filial',
      'F2_DOC' => 'Doc',
      'F2_SERIE' => 'Serie',
      'F2_CLIENTE' => 'Cliente',
      'F2_LOJA' => 'Loja',
      'CPF_CNPJ' => 'CPF/CNPJ',
      'F2_EMISSAO' => 'Emissao',
      'F2_VALBRUT' => 'Valor bruto',
      'F2_CHVNFE' => 'Chave NF-e',
      'F2_FIMP' => 'Sit. transmissao',
      'SEFAZ_STATUS' => 'Status SEFAZ',
      'SEFAZ_COD_RET' => 'Cod. retorno',
      'SEFAZ_MSG' => 'Mensagem SEFAZ',
      'PED_Marketplace' => 'Ped. marketplace',
      'Marketplace' => 'Marketplace',
      'IDLEXOS' => 'ID Lexos',
    ];
  }

  /** @return list<string> */
  public static function statusFilterOptions(): array
  {
    return ['', 'autorizada', 'rejeitada', 'pendente'];
  }

  public static function formatCpfCnpj(mixed $value): string
  {
    return ProtheusRomaneioMonitorService::formatCpfCnpj($value);
  }

  public static function formatF2FimpLabel(mixed $value): string
  {
    return match (strtoupper(trim((string) ($value ?? '')))) {
      'S' => 'S-Transmitido',
      'N' => 'N-Negado',
      'D' => 'D-Uso denegado',
      default => trim((string) ($value ?? '')),
    };
  }

  /**
   * @param array<string, mixed> $row
   */
  public static function resolveSefazStatus(array $row): string
  {
    $fimp = strtoupper(trim((string) ($row['F2_FIMP'] ?? '')));
    if ($fimp === 'N' || $fimp === 'D') {
      return 'rejeitada';
    }
    if ($fimp === 'S') {
      $chave = preg_replace('/\D/', '', (string) ($row['F2_CHVNFE'] ?? ''));
      if (strlen($chave) === 44) {
        return 'autorizada';
      }

      return 'pendente';
    }

    $chave = preg_replace('/\D/', '', (string) ($row['F2_CHVNFE'] ?? ''));
    if (strlen($chave) === 44) {
      return 'autorizada';
    }

    $cod = trim((string) ($row['SEFAZ_COD_RET'] ?? ''));
    if ($cod !== '' && in_array($cod, self::SEFAZ_COD_REJEITADO, true)) {
      return 'rejeitada';
    }
    if ($cod !== '' && in_array($cod, self::SEFAZ_COD_AUTORIZADO, true)) {
      return 'autorizada';
    }
    if ($cod !== '' && !in_array($cod, self::SEFAZ_COD_AUTORIZADO, true)) {
      return 'rejeitada';
    }

    return 'pendente';
  }

  public static function formatSefazStatusLabel(string $status): string
  {
    return match ($status) {
      'autorizada' => 'Autorizada',
      'rejeitada' => 'Rejeitada',
      default => 'Pendente',
    };
  }

  /**
   * @param array<string, mixed> $row
   */
  public function rowAlertClass(array $row): string
  {
    return match (self::resolveSefazStatus($row)) {
      'autorizada' => 'row-sefaz-autorizada',
      'rejeitada' => 'row-sefaz-rejeitada',
      default => 'row-sefaz-pendente',
    };
  }

  public function exportToXlsx(
    string $filial,
    string $emissaoDe,
    string $emissaoAte,
    string $statusFilter = '',
    string $marketplace = '',
    string $pedidosCsv = ''
  ): string {
    $rows = $this->listAll($filial, $emissaoDe, $emissaoAte, $statusFilter, $marketplace, $pedidosCsv);
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
      $row['SEFAZ_STATUS'] = self::formatSefazStatusLabel(self::resolveSefazStatus($row));
      $line = [];
      foreach (array_keys($columns) as $key) {
        $text = $this->displayCellText((string) $key, $row[$key] ?? null, $row);
        $line[] = $this->exportDataCell($row, (string) $key, $text);
      }
      $sheet[] = $line;
    }

    $dir = $this->exportDirectory();
    $safeFilial = preg_replace('/[^0-9]/', '', $this->normalizeFilial($filial)) ?: 'filial';
    $fileName = sprintf('monitor_nfe_%s_%s.xlsx', $safeFilial, date('Ymd_His'));
    $fullPath = $dir . DIRECTORY_SEPARATOR . $fileName;

    require_once __DIR__ . '/../Lib/SimpleXLSXGen.php';
    $xlsx = SimpleXLSXGen::fromArray($sheet, 'NF-e SEFAZ');
    if (!$xlsx->saveAs($fullPath)) {
      throw new \RuntimeException('Nao foi possivel gravar o arquivo de exportacao.');
    }

    return $fullPath;
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function listAll(
    string $filial,
    string $emissaoDe,
    string $emissaoAte,
    string $statusFilter = '',
    string $marketplace = '',
    string $pedidosCsv = ''
  ): array {
    $pdo = $this->connectionService->connect();
    $ctx = $this->buildFilterContext($filial, $emissaoDe, $emissaoAte, $marketplace, $pedidosCsv);

    $sql = $this->baseSql($pdo)
      . $this->statusWhereSql($pdo, $statusFilter)
      . $this->marketplacePedidoWhereSql($ctx)
      . ' ORDER BY SF2.F2_EMISSAO DESC, SF2.F2_DOC, SF2.F2_SERIE';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(ProtheusSqlHelper::paramsForSql($sql, $ctx['params']));
    $rows = $stmt->fetchAll();

    return is_array($rows) ? $rows : [];
  }

  /**
   * @return array{
   *   rows: list<array<string, mixed>>,
   *   total: int,
   *   page: int,
   *   per_page: int,
   *   total_pages: int,
   *   has_sefaz_log: bool,
   *   sefaz_log_table: string|null
   * }
   */
  public function listNotas(
    string $filial,
    string $emissaoDe,
    string $emissaoAte,
    string $statusFilter = '',
    int $page = 1,
    int $perPage = 50,
    string $marketplace = '',
    string $pedidosCsv = ''
  ): array {
    $page = max(1, $page);
    $perPage = max(10, min(200, $perPage));
    $offset = ($page - 1) * $perPage;

    $pdo = $this->connectionService->connect();
    $ctx = $this->buildFilterContext($filial, $emissaoDe, $emissaoAte, $marketplace, $pedidosCsv);
    $params = $ctx['params'];
    $statusWhere = $this->statusWhereSql($pdo, $statusFilter);
    $extraWhere = $this->marketplacePedidoWhereSql($ctx);
    $logConfig = $this->resolveSefazLogConfig($pdo);

    $countSql = 'SELECT COUNT(1) AS total FROM (' . $this->baseSql($pdo) . $statusWhere . $extraWhere . ') AS q';
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute(ProtheusSqlHelper::paramsForSql($countSql, $params));
    $total = (int) ($countStmt->fetch()['total'] ?? 0);

    $sql = $this->baseSql($pdo)
      . $statusWhere
      . $extraWhere
      . ' ORDER BY SF2.F2_EMISSAO DESC, SF2.F2_DOC, SF2.F2_SERIE'
      . ' OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY';

    $stmt = $pdo->prepare($sql);
    foreach (ProtheusSqlHelper::paramsForSql($sql, $params) as $key => $value) {
      $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;

    return [
      'rows' => is_array($rows) ? $rows : [],
      'total' => $total,
      'page' => $page,
      'per_page' => $perPage,
      'total_pages' => $totalPages,
      'has_sefaz_log' => $logConfig !== null,
      'sefaz_log_table' => $logConfig['table'] ?? null,
    ];
  }

  /**
   * @param array<string, mixed>|null $row
   */
  public function displayCellText(string $columnKey, mixed $value, ?array $row = null): string
  {
    if ($columnKey === 'CPF_CNPJ') {
      return self::formatCpfCnpj($value);
    }
    if ($columnKey === 'F2_FIMP') {
      return self::formatF2FimpLabel($value);
    }
    if ($columnKey === 'SEFAZ_STATUS' && is_array($row)) {
      return self::formatSefazStatusLabel(self::resolveSefazStatus($row));
    }

    return $this->cellText($value);
  }

  /**
   * @param array<string, mixed>|null $row
   */
  public function displayCellHtml(string $columnKey, mixed $value, ?array $row = null): string
  {
    if ($columnKey === 'F2_FIMP') {
      $label = self::formatF2FimpLabel($value);
      $class = match (strtoupper(trim((string) ($value ?? '')))) {
        'S' => 'sefaz-status-autorizada',
        'N', 'D' => 'sefaz-status-rejeitada',
        default => 'sefaz-status-pendente',
      };

      return '<span class="' . $class . '">' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
    }
    if ($columnKey === 'SEFAZ_STATUS' && is_array($row)) {
      $status = self::resolveSefazStatus($row);
      $label = self::formatSefazStatusLabel($status);
      $class = match ($status) {
        'autorizada' => 'sefaz-status-autorizada',
        'rejeitada' => 'sefaz-status-rejeitada',
        default => 'sefaz-status-pendente',
      };

      return '<span class="' . $class . '">' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
    }

    return htmlspecialchars($this->displayCellText($columnKey, $value, $row), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  private function exportHeaderCell(string $label): string
  {
    $safe = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return '<style bgcolor="#' . self::COLOR_HEADER . '"><b>' . $safe . '</b></style>';
  }

  /**
   * @param array<string, mixed> $row
   */
  private function exportDataCell(array $row, string $columnKey, string $value): string
  {
    $alert = $this->rowAlertClass($row);
    $bg = match ($alert) {
      'row-sefaz-autorizada' => self::COLOR_ROW_AUTORIZADA,
      'row-sefaz-rejeitada' => self::COLOR_ROW_REJEITADA,
      'row-sefaz-pendente' => self::COLOR_ROW_PENDENTE,
      default => null,
    };

    $safe = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    if ($bg === null) {
      return $safe;
    }

    return '<style bgcolor="#' . $bg . '">' . $safe . '</style>';
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
   * @return list<string>
   */
  public function listMarketplaces(string $filial, string $emissaoDe, string $emissaoAte): array
  {
    $pdo = $this->connectionService->connect();
    $ctx = $this->buildFilterContext($filial, $emissaoDe, $emissaoAte, '', '');

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
    AND SF2.F2_EMISSAO BETWEEN :emissao_de AND :emissao_ate
    AND UPPER(RTRIM(ISNULL(SF2.F2_FIMP, \'\'))) IN (\'S\', \'N\', \'D\')
    AND RTRIM(ISNULL(SC5.C5_ZMAKET, \'\')) <> \'\'
ORDER BY marketplace';

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
   * @return array{params: array<string, string>, pedidos: list<string>}
   */
  private function buildFilterContext(
    string $filial,
    string $emissaoDe,
    string $emissaoAte,
    string $marketplace = '',
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

    $pedidos = $this->parseBatchFilter($pedidosCsv);
    foreach ($pedidos as $i => $ped) {
      $params[':ped_' . $i] = $ped;
    }

    return [
      'params' => $params,
      'pedidos' => $pedidos,
    ];
  }

  /**
   * @param array{params: array<string, string>, pedidos: list<string>} $ctx
   */
  private function marketplacePedidoWhereSql(array $ctx): string
  {
    $sql = '';
    if (isset($ctx['params'][':marketplace'])) {
      $sql .= ProtheusSqlHelper::marketplaceAndSql($ctx['params'][':marketplace']);
    }

    $pedidos = $ctx['pedidos'];
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

  private function baseSql(PDO $pdo): string
  {
    $logConfig = $this->resolveSefazLogConfig($pdo);
    if ($logConfig !== null) {
      $codCol = $logConfig['cod_col'];
      $msgCol = $logConfig['msg_col'];
      $table = $logConfig['table'];
      $joinWhere = $logConfig['join_where'];
      $msgSelect = $msgCol !== null
        ? "RTRIM(SEFAZ_LAST.{$msgCol}) AS SEFAZ_MSG"
        : "CAST('' AS VARCHAR(500)) AS SEFAZ_MSG";
      $sefazSelect = <<<SQL
    RTRIM(SEFAZ_LAST.{$codCol}) AS SEFAZ_COD_RET,
    {$msgSelect},
SQL;
      $sefazJoin = <<<SQL
OUTER APPLY (
    SELECT TOP 1 SPED.*
    FROM {$table} SPED WITH (NOLOCK)
    WHERE SPED.D_E_L_E_T_ = ' '
        AND {$joinWhere}
    ORDER BY SPED.R_E_C_N_O_ DESC
) SEFAZ_LAST
SQL;
    } else {
      $sefazSelect = <<<'SQL'
    CAST('' AS VARCHAR(20)) AS SEFAZ_COD_RET,
    CAST('' AS VARCHAR(500)) AS SEFAZ_MSG,
SQL;
      $sefazJoin = '';
    }

    $sf2 = ProtheusSqlHelper::tbl('SF2010', 'SF2');
    $sc5 = ProtheusSqlHelper::tbl('SC5010', 'SC5');
    $sa1 = ProtheusSqlHelper::tbl('SA1010', 'SA1');

    return <<<SQL
SELECT
    SF2.F2_FILIAL,
    SF2.F2_DOC,
    SF2.F2_SERIE,
    SF2.F2_CLIENTE,
    SF2.F2_LOJA,
    RTRIM(SA1.A1_CGC) AS CPF_CNPJ,
    SUBSTRING(SF2.F2_EMISSAO, 7, 2) + '/' +
        SUBSTRING(SF2.F2_EMISSAO, 5, 2) + '/' +
        SUBSTRING(SF2.F2_EMISSAO, 1, 4) AS F2_EMISSAO,
    SF2.F2_VALBRUT,
    RTRIM(SF2.F2_CHVNFE) AS F2_CHVNFE,
    RTRIM(SF2.F2_FIMP) AS F2_FIMP,
    {$sefazSelect}
    SC5.C5_PEDMAR AS PED_Marketplace,
    SC5.C5_ZMAKET AS Marketplace,
    SC5.C5_ZIDLEX AS IDLEXOS
FROM {$sf2}
LEFT JOIN {$sc5}
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
{$sefazJoin}
WHERE SF2.D_E_L_E_T_ = ' '
    AND SF2.F2_FILIAL = :filial
    AND SF2.F2_EMISSAO BETWEEN :emissao_de AND :emissao_ate
    AND UPPER(RTRIM(ISNULL(SF2.F2_FIMP, ''))) IN ('S', 'N', 'D')
SQL;
  }

  private function fimpColumnExpr(): string
  {
    return "UPPER(RTRIM(ISNULL(SF2.F2_FIMP, '')))";
  }

  private function statusWhereSql(PDO $pdo, string $statusFilter): string
  {
    $statusFilter = strtolower(trim($statusFilter));
    if (!in_array($statusFilter, ['autorizada', 'rejeitada', 'pendente'], true)) {
      return '';
    }

    $fimp = $this->fimpColumnExpr();
    $chaveOk = "LEN(REPLACE(REPLACE(RTRIM(ISNULL(SF2.F2_CHVNFE, '')), ' ', ''), '-', '')) = 44";

    if ($statusFilter === 'autorizada') {
      return " AND {$fimp} = 'S' AND " . $chaveOk;
    }

    if ($statusFilter === 'pendente') {
      return " AND {$fimp} = 'S' AND NOT (" . $chaveOk . ')';
    }

    // rejeitada: negado ou uso denegado
    return " AND {$fimp} IN ('N', 'D')";
  }

  /**
   * @param array{table: string, cod_col: string, msg_col: ?string, join_where: string} $logConfig
   */
  private function rejeitadaSqlCondition(array $logConfig): string
  {
    $codCol = $logConfig['cod_col'];
    $codExpr = "RTRIM(ISNULL(SEFAZ_LAST.{$codCol}, ''))";
    $rejeitados = "'" . implode("','", self::SEFAZ_COD_REJEITADO) . "'";
    $autorizados = "'" . implode("','", self::SEFAZ_COD_AUTORIZADO) . "'";

    return "({$codExpr} IN ({$rejeitados}) OR ({$codExpr} <> '' AND {$codExpr} NOT IN ({$autorizados})))";
  }

  /**
   * Log SEFAZ: SPED050/SPED054 (TSS). SFT010 e livro fiscal por item — nao usar.
   *
   * @return array{table: string, cod_col: string, msg_col: ?string, join_where: string}|null
   */
  private function resolveSefazLogConfig(PDO $pdo): ?array
  {
    if ($this->sefazLogConfig === false) {
      return null;
    }
    if (is_array($this->sefazLogConfig)) {
      return $this->sefazLogConfig;
    }

    foreach (['SPED05010', 'SPED050', 'SPED05410', 'SPED054'] as $table) {
      if (!$this->tableExists($pdo, $table)) {
        continue;
      }

      $cols = $this->tableColumns($pdo, $table);
      $codCol = $this->pickColumn($cols, ['STATUS', 'CSTAT', 'CODRET', 'COD_RS', 'FT_CODRET']);
      $docCol = $this->pickColumn($cols, ['NNF', 'NUMNF', 'F2_DOC', 'DOC', 'NOTA', 'NUMERO', 'NFISCAL']);
      if ($codCol === null || $docCol === null) {
        continue;
      }

      $msgCol = $this->pickColumn($cols, ['STATUSDESC', 'MOTIVO', 'MSGRET', 'XMOTIVO', 'DESCRI', 'MSG', 'FT_MSGRET']);
      $chaveCol = $this->pickColumn($cols, ['CHVNFE', 'F2_CHVNFE', 'NFE_ID', 'CHAVE', 'CHAVEACESSO']);
      $serieCol = $this->pickColumn($cols, ['SERIE', 'F2_SERIE', 'SERIEDOC']);
      $filialCol = $this->pickColumn($cols, ['FILIAL', 'F2_FILIAL', 'BRANCH']);

      if ($chaveCol !== null) {
        $joinParts = [
          "RTRIM(SPED.{$chaveCol}) <> ''",
          "RTRIM(SPED.{$chaveCol}) = RTRIM(SF2.F2_CHVNFE)",
        ];
      } else {
        $joinParts = ["RTRIM(SPED.{$docCol}) = RTRIM(SF2.F2_DOC)"];
        if ($serieCol !== null) {
          $joinParts[] = "RTRIM(SPED.{$serieCol}) = RTRIM(SF2.F2_SERIE)";
        }
        if ($filialCol !== null) {
          $joinParts[] = "RTRIM(SPED.{$filialCol}) = RTRIM(SF2.F2_FILIAL)";
        }
      }

      $this->sefazLogConfig = [
        'table' => $table,
        'cod_col' => $codCol,
        'msg_col' => $msgCol,
        'join_where' => implode(' AND ', $joinParts),
      ];

      return $this->sefazLogConfig;
    }

    $this->sefazLogConfig = false;

    return null;
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
