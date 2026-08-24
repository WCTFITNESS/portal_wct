<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Ler EDI — parse de arquivo OCOREN 5.0 (TIVIT) e cruzamento com pedido Protheus via NF (SF2/SC5/ZA4).
 */
class ProtheusLerEdiService
{
    private const DELETED_ACTIVE = ' ';
    private const RECORD_LEN = 250;
    private const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;
    private const QUERY_TIMEOUT_SEC = 30;
    private const LOOKUP_CHUNK = 40;

    /** @var array<string, string> */
    private const OCORRENCIA_LABELS = [
        '000' => 'Processo de Transporte ja Iniciado',
        '001' => 'Entrega Realizada Normalmente',
        '002' => 'Entrega Fora da Data Programada',
        '003' => 'Recusa por Falta de Pedido de Compra',
        '004' => 'Recusa por Pedido de Compra Cancelado',
        '005' => 'Falta de Espaco Fisico no Deposito do Cliente Destino',
        '006' => 'Endereco do Cliente Destino nao Localizado',
        '007' => 'Devolucao nao Autorizada pelo Cliente',
        '008' => 'Preco Mercadoria em Desacordo com o Pedido Compra',
        '009' => 'Mercadoria em Desacordo com o Pedido Compra',
        '010' => 'Cliente Destino somente Recebe Mercadoria com Frete Pago',
        '011' => 'Recusa por Deficiencia Embalagem Mercadoria',
        '014' => 'Mercadoria Sinistrada',
        '019' => 'Reentrega Solicitada pelo Cliente',
        '020' => 'Entrega Prejudicada por Horario/Falta de Tempo Habil',
        '021' => 'Estabelecimento Fechado',
        '023' => 'Extravio de Mercadoria em Transito',
        '024' => 'Mercadoria Reentregue ao Cliente Destino',
        '025' => 'Mercadoria Devolvida ao Cliente de Origem',
        '027' => 'Roubo de Carga',
        '046' => 'Responsavel de Recebimento Ausente',
        '052' => 'Mercadoria Redespachada (Entregue para Redespacho)',
        '060' => 'Endereco de Entrega Errado',
        '078' => 'Avaria Total',
        '079' => 'Avaria Parcial',
        '080' => 'Extravio Total',
        '081' => 'Extravio Parcial',
        '091' => 'Entrega Programada',
        '098' => 'Chegada na cidade ou filial de destino',
        '099' => 'Outros tipos de ocorrencias nao especificados',
        '100' => 'Devolucao por nao cumprimento do agendamento',
        '101' => 'Devolucao/nao entrega a pedido do embarcador',
        '102' => 'Devolucao/nao entrega a pedido do destinatario',
    ];

    /** @var array<string, string> */
    private const OBS_LABELS = [
        '01' => 'Devolucao/recusa total',
        '02' => 'Devolucao/recusa parcial',
        '03' => 'Aceite/entrega por acordo',
        '04' => 'Devolucao/recusa total com NF devolucao',
        '05' => 'Devolucao/recusa parcial com NF devolucao',
    ];

    public function __construct(
        private ProtheusConnectionService $connectionService
    ) {
    }

    /**
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
     * @return array{
     *   meta: array<string, mixed>,
     *   rows: list<array<string, mixed>>,
     *   summary: array<string, int>
     * }
     */
    public function processUpload(array $file, string $filial = '0101', bool $crossProtheus = true): array
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(180);

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException($this->uploadErrorMessage($error));
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        $name = (string) ($file['name'] ?? 'ocoren.txt');
        $size = (int) ($file['size'] ?? 0);

        if ($tmp === '' || !is_file($tmp) || !is_readable($tmp)) {
            throw new \RuntimeException('Upload invalido.');
        }
        if ($size <= 0) {
            $size = (int) filesize($tmp);
        }
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            throw new \RuntimeException('Arquivo vazio ou maior que 20 MB.');
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['txt', 'edi', 'ocoren', ''], true)) {
            throw new \RuntimeException('Envie um arquivo OCOREN (.txt / .edi).');
        }

        $raw = file_get_contents($tmp);
        if ($raw === false || $raw === '') {
            throw new \RuntimeException('Nao foi possivel ler o arquivo enviado.');
        }

        $parsed = $this->parseOcoren($raw, $name);
        unset($raw);
        $parsed['warning'] = '';

        if ($crossProtheus) {
            try {
                $parsed['rows'] = $this->enrichWithProtheus($parsed['rows'], $filial);
            } catch (\Throwable $e) {
                foreach ($parsed['rows'] as &$row) {
                    $row['PEDIDO_PROTHEUS'] = '';
                    $row['IDLEXOS'] = '';
                    $row['PED_MAR'] = '';
                    $row['MARKETPLACE'] = '';
                    $row['F2_IDHUB'] = '';
                    $row['MATCH_PROTHEUS'] = 'nao';
                }
                unset($row);
                $parsed['warning'] = 'Arquivo lido, mas o cruzamento Protheus falhou: ' . $e->getMessage();
            }
        } else {
            foreach ($parsed['rows'] as &$row) {
                $row['PEDIDO_PROTHEUS'] = '';
                $row['IDLEXOS'] = '';
                $row['PED_MAR'] = '';
                $row['MARKETPLACE'] = '';
                $row['F2_IDHUB'] = '';
                $row['MATCH_PROTHEUS'] = 'nao';
            }
            unset($row);
        }

        $parsed['rows'] = $this->slimRows($parsed['rows']);

        $matched = 0;
        $unmatched = 0;
        foreach ($parsed['rows'] as $row) {
            if (($row['MATCH_PROTHEUS'] ?? '') === 'sim') {
                $matched++;
            } else {
                $unmatched++;
            }
        }

        $parsed['summary'] = [
            'ocorrencias' => count($parsed['rows']),
            'matched' => $matched,
            'unmatched' => $unmatched,
        ];

        return $parsed;
    }

    /**
     * Persiste resultado do parse para exibicao paginada apos redirect.
     *
     * @param array<string, mixed> $result
     */
    public function saveResult(array $result): string
    {
        $dir = $this->resultDirectory();
        $token = bin2hex(random_bytes(16));
        $path = $dir . DIRECTORY_SEPARATOR . $token . '.json';

        if (isset($result['rows']) && is_array($result['rows'])) {
            $result['rows'] = $this->slimRows($result['rows']);
        }

        $json = json_encode(
            $result,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($json === false) {
            throw new \RuntimeException('Falha ao serializar resultado do EDI.');
        }
        if (file_put_contents($path, $json) === false) {
            throw new \RuntimeException('Falha ao gravar resultado temporario do EDI.');
        }
        unset($json);

        return $token;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadResult(string $token): ?array
    {
        $token = preg_replace('/[^a-f0-9]/', '', strtolower($token)) ?? '';
        if (strlen($token) < 16) {
            return null;
        }
        $path = $this->resultDirectory() . DIRECTORY_SEPARATOR . $token . '.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    public function resultDirectory(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ml-portal-ler-edi';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Nao foi possivel criar pasta temporaria do Ler EDI.');
        }

        return $dir;
    }

    /**
     * Exporta o resultado filtrado do job para XLSX.
     *
     * @param array<string, string> $filters
     */
    public function exportJobToXlsx(string $token, array $filters = []): string
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(180);

        $result = $this->loadResult($token);
        if ($result === null) {
            throw new \RuntimeException('Resultado do upload nao encontrado ou expirado. Envie o arquivo novamente.');
        }

        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
        $rows = $this->filterRows($rows, $filters);
        $columns = self::tableColumns();

        $header = [];
        foreach (array_values($columns) as $label) {
            $safe = htmlspecialchars((string) $label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $header[] = '<style bgcolor="#F1F5F9"><b>' . $safe . '</b></style>';
        }
        $sheet = [$header];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $line = [];
            $bg = match ($this->rowAlertClass($row)) {
                'row-edi-erro' => 'FEE2E2',
                'row-edi-alerta' => 'FFEDD5',
                default => 'FFFFFF',
            };
            foreach (array_keys($columns) as $key) {
                $text = trim((string) ($row[$key] ?? ''));
                if ($key === 'MATCH_PROTHEUS') {
                    $text = $text === 'sim' ? 'Sim' : 'Nao';
                }
                $safe = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $line[] = '<style bgcolor="#' . $bg . '">' . $safe . '</style>';
            }
            $sheet[] = $line;
        }

        $dir = $this->resultDirectory();
        $fileName = sprintf('ler_edi_ocoren_%s.xlsx', date('Ymd_His'));
        $fullPath = $dir . DIRECTORY_SEPARATOR . $fileName;

        require_once __DIR__ . '/../Lib/SimpleXLSXGen.php';
        $xlsx = \Shuchkin\SimpleXLSXGen::fromArray($sheet, 'Ler EDI OCOREN');
        if (!$xlsx->saveAs($fullPath)) {
            throw new \RuntimeException('Nao foi possivel gravar o arquivo de exportacao.');
        }

        return $fullPath;
    }

    /**
     * @return array{meta: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    public function parseOcoren(string $raw, string $fileName = ''): array
    {
        $text = $this->normalizeEncoding($raw);
        unset($raw);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);
        unset($text);

        $meta = [
            'arquivo' => $fileName,
            'remetente' => '',
            'destinatario' => '',
            'data_intercambio' => '',
            'hora_intercambio' => '',
            'id_intercambio' => '',
            'id_documento' => '',
            'cnpj_transportadora' => '',
            'razao_transportadora' => '',
            'linhas_lidas' => 0,
            'registros_542' => 0,
            'registros_549_total' => 0,
        ];

        /** @var list<array<string, mixed>> $rows */
        $rows = [];
        $current = null;

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $meta['linhas_lidas']++;
            $rec = $this->padRecord($line);
            $id = substr($rec, 0, 3);

            if ($id === '000') {
                $meta['remetente'] = rtrim(substr($rec, 3, 35));
                $meta['destinatario'] = rtrim(substr($rec, 38, 35));
                $meta['data_intercambio'] = $this->formatDateDdmmaa(substr($rec, 73, 6));
                $meta['hora_intercambio'] = $this->formatTimeHhmm(substr($rec, 79, 4));
                $meta['id_intercambio'] = rtrim(substr($rec, 83, 12));
                continue;
            }

            if ($id === '540') {
                $meta['id_documento'] = rtrim(substr($rec, 3, 14));
                continue;
            }

            if ($id === '541') {
                $meta['cnpj_transportadora'] = rtrim(substr($rec, 3, 14));
                $meta['razao_transportadora'] = rtrim(substr($rec, 17, 50));
                continue;
            }

            if ($id === '542') {
                if ($current !== null) {
                    $rows[] = $current;
                }
                $current = $this->parse542($rec);
                $meta['registros_542']++;
                continue;
            }

            if ($id === '543' && $current !== null) {
                $t1 = rtrim(substr($rec, 3, 70));
                $t2 = rtrim(substr($rec, 73, 70));
                $t3 = rtrim(substr($rec, 143, 70));
                $parts = array_values(array_filter([$t1, $t2, $t3], static fn ($v) => $v !== ''));
                $current['TEXTO'] = trim(implode(' ', $parts));
                continue;
            }

            if ($id === '544' && $current !== null) {
                $current['QTD_VOLUMES'] = $this->formatDecimal62(substr($rec, 3, 8));
                $current['QTD_ENTREGUES'] = $this->formatDecimal62(substr($rec, 11, 8));
                $current['COD_ITEM'] = rtrim(substr($rec, 19, 20));
                $current['DESC_ITEM'] = rtrim(substr($rec, 39, 50));
                continue;
            }

            if ($id === '545' && $current !== null) {
                $current['REDESPACHO_CNPJ_CONTRATANTE'] = rtrim(substr($rec, 3, 14));
                $current['REDESPACHO_CNPJ_CTE'] = rtrim(substr($rec, 17, 14));
                $current['REDESPACHO_FILIAL_CTE'] = rtrim(substr($rec, 31, 10));
                $current['REDESPACHO_SERIE_CTE'] = rtrim(substr($rec, 41, 5));
                $current['REDESPACHO_NUM_CTE'] = rtrim(substr($rec, 46, 12));
                continue;
            }

            if ($id === '549') {
                if ($current !== null) {
                    $rows[] = $current;
                    $current = null;
                }
                $meta['registros_549_total'] = (int) ltrim(substr($rec, 3, 4), '0');
                if ($meta['registros_549_total'] === 0 && substr($rec, 3, 4) === '0000') {
                    $meta['registros_549_total'] = 0;
                }
            }
        }

        if ($current !== null) {
            $rows[] = $current;
        }
        unset($lines, $current);

        if ($rows === []) {
            throw new \RuntimeException('Nenhum registro 542 (ocorrencia) encontrado no arquivo.');
        }

        return [
            'meta' => $meta,
            'rows' => $rows,
        ];
    }

    /** @return array<string, string> */
    public static function tableColumns(): array
    {
        return [
            'MATCH_PROTHEUS' => 'Protheus',
            'PEDIDO_PROTHEUS' => 'Pedido Protheus',
            'MARKETPLACE' => 'Marketplace',
            'PED_MAR' => 'Ped. marketplace',
            'IDLEXOS' => 'ID Lexos',
            'NOTA_FISCAL' => 'NF',
            'SERIE_NF' => 'Serie',
            'COD_OCORRENCIA' => 'Cod.',
            'DESC_OCORRENCIA' => 'Ocorrencia',
            'DATA_OCORRENCIA' => 'Data',
            'HORA_OCORRENCIA' => 'Hora',
            'TEXTO' => 'Texto complementar',
            'NUM_CTE' => 'CTE',
            'SERIE_CTE' => 'Serie CTE',
            'FILIAL_CTE' => 'Filial CTE',
            'ROMANEIO' => 'Romaneio/embarque',
            'COD_OBS' => 'Cod. obs',
            'DESC_OBS' => 'Obs',
            'TIPO_ENTREGA' => 'Tipo entrega',
            'QTD_VOLUMES' => 'Vol. NF',
            'QTD_ENTREGUES' => 'Vol. entregues',
            'CNPJ_EMISSOR' => 'CNPJ emissor NF',
            'F2_IDHUB' => 'ID Hub',
            'DATA_CHEGADA' => 'Chegada dest.',
            'HORA_CHEGADA' => 'Hora chegada',
        ];
    }

    /**
     * Filtra as linhas ja parseadas (resultado do upload).
     *
     * @param list<array<string, mixed>> $rows
     * @param array{
     *   q?: string,
     *   nota_fiscal?: string,
     *   pedido?: string,
     *   ped_mar?: string,
     *   marketplace?: string,
     *   idlexo?: string,
     *   cod_ocorrencia?: string,
     *   ocorrencia?: string,
     *   cte?: string,
     *   match?: string,
     *   texto?: string
     * } $filters
     * @return list<array<string, mixed>>
     */
    public function filterRows(array $rows, array $filters): array
    {
        $q = mb_strtolower(trim((string) ($filters['q'] ?? '')), 'UTF-8');
        $nota = $this->digitsOnly((string) ($filters['nota_fiscal'] ?? ''));
        $pedido = trim((string) ($filters['pedido'] ?? ''));
        $pedMar = trim((string) ($filters['ped_mar'] ?? ''));
        $marketplace = trim((string) ($filters['marketplace'] ?? ''));
        $idlexo = trim((string) ($filters['idlexo'] ?? ''));
        $cod = trim((string) ($filters['cod_ocorrencia'] ?? ''));
        $ocorrencia = mb_strtolower(trim((string) ($filters['ocorrencia'] ?? '')), 'UTF-8');
        $cte = trim((string) ($filters['cte'] ?? ''));
        $match = strtolower(trim((string) ($filters['match'] ?? '')));
        $texto = mb_strtolower(trim((string) ($filters['texto'] ?? '')), 'UTF-8');

        if (
            $q === ''
            && $nota === ''
            && $pedido === ''
            && $pedMar === ''
            && $marketplace === ''
            && $idlexo === ''
            && $cod === ''
            && $ocorrencia === ''
            && $cte === ''
            && $match === ''
            && $texto === ''
        ) {
            return $rows;
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if ($match === 'sim' || $match === 'nao') {
                if (strtolower((string) ($row['MATCH_PROTHEUS'] ?? '')) !== $match) {
                    continue;
                }
            }

            if ($nota !== '') {
                $rowNota = $this->digitsOnly((string) ($row['NOTA_FISCAL'] ?? ''));
                if ($rowNota === '' || !str_contains($rowNota, $nota)) {
                    continue;
                }
            }

            if ($pedido !== '' && !$this->containsInsensitive((string) ($row['PEDIDO_PROTHEUS'] ?? ''), $pedido)) {
                continue;
            }
            if ($pedMar !== '' && !$this->containsInsensitive((string) ($row['PED_MAR'] ?? ''), $pedMar)) {
                continue;
            }
            if ($marketplace !== '' && !$this->containsInsensitive((string) ($row['MARKETPLACE'] ?? ''), $marketplace)) {
                continue;
            }
            if ($idlexo !== '' && !$this->containsInsensitive((string) ($row['IDLEXOS'] ?? ''), $idlexo)) {
                continue;
            }
            if ($cod !== '') {
                $rowCod = trim((string) ($row['COD_OCORRENCIA'] ?? ''));
                if ($rowCod !== $cod && !str_contains($rowCod, $cod)) {
                    continue;
                }
            }
            if ($ocorrencia !== '') {
                $rowOcorrencia = mb_strtolower(trim((string) ($row['DESC_OCORRENCIA'] ?? '')), 'UTF-8');
                if ($rowOcorrencia === '' || !str_contains($rowOcorrencia, $ocorrencia)) {
                    continue;
                }
            }
            if ($cte !== '' && !$this->containsInsensitive((string) ($row['NUM_CTE'] ?? ''), $cte)) {
                continue;
            }
            if ($texto !== '') {
                $hay = mb_strtolower(
                    trim((string) ($row['TEXTO'] ?? '')) . ' ' . trim((string) ($row['DESC_OCORRENCIA'] ?? '')),
                    'UTF-8'
                );
                if ($hay === '' || !str_contains($hay, $texto)) {
                    continue;
                }
            }

            if ($q !== '') {
                $blob = mb_strtolower(implode(' ', [
                    (string) ($row['NOTA_FISCAL'] ?? ''),
                    (string) ($row['PEDIDO_PROTHEUS'] ?? ''),
                    (string) ($row['PED_MAR'] ?? ''),
                    (string) ($row['MARKETPLACE'] ?? ''),
                    (string) ($row['IDLEXOS'] ?? ''),
                    (string) ($row['COD_OCORRENCIA'] ?? ''),
                    (string) ($row['DESC_OCORRENCIA'] ?? ''),
                    (string) ($row['TEXTO'] ?? ''),
                    (string) ($row['NUM_CTE'] ?? ''),
                    (string) ($row['ROMANEIO'] ?? ''),
                    (string) ($row['F2_IDHUB'] ?? ''),
                ]), 'UTF-8');
                if (!str_contains($blob, $q)) {
                    continue;
                }
            }

            $out[] = $row;
        }

        return $out;
    }

    private function digitsOnly(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function containsInsensitive(string $haystack, string $needle): bool
    {
        $haystack = mb_strtolower(trim($haystack), 'UTF-8');
        $needle = mb_strtolower(trim($needle), 'UTF-8');
        if ($needle === '') {
            return true;
        }

        return $haystack !== '' && str_contains($haystack, $needle);
    }

    public function rowAlertClass(array $row): string
    {
        if (($row['MATCH_PROTHEUS'] ?? '') !== 'sim') {
            return 'row-edi-erro';
        }
        $cod = (string) ($row['COD_OCORRENCIA'] ?? '');
        if ($cod !== '' && $cod !== '001') {
            return 'row-edi-alerta';
        }

        return '';
    }

    public function displayCellHtml(string $key, mixed $value): string
    {
        $text = trim((string) ($value ?? ''));
        if ($key === 'MATCH_PROTHEUS') {
            if ($text === 'sim') {
                return '<span class="badge-ok">Sim</span>';
            }

            return '<span class="badge-err">Nao</span>';
        }
        if ($key === 'TEXTO' || $key === 'DESC_OCORRENCIA') {
            if ($text === '') {
                return '';
            }

            return '<span class="cell-desc">' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
        }

        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @return array<string, mixed>
     */
    private function parse542(string $rec): array
    {
        $cod = substr($rec, 29, 3);
        $obs = substr($rec, 44, 2);
        $tipoEntrega = substr($rec, 153, 1);

        return [
            'CNPJ_EMISSOR' => rtrim(substr($rec, 3, 14)),
            'SERIE_NF' => rtrim(substr($rec, 17, 3)),
            'NOTA_FISCAL' => rtrim(substr($rec, 20, 9)),
            'NOTA_FISCAL_NUM' => ltrim(rtrim(substr($rec, 20, 9)), '0') ?: '0',
            'COD_OCORRENCIA' => $cod,
            'DESC_OCORRENCIA' => self::OCORRENCIA_LABELS[$cod] ?? ('Codigo ' . $cod),
            'DATA_OCORRENCIA' => $this->formatDateDdmmaaaa(substr($rec, 32, 8)),
            'HORA_OCORRENCIA' => $this->formatTimeHhmm(substr($rec, 40, 4)),
            'COD_OBS' => rtrim($obs),
            'DESC_OBS' => self::OBS_LABELS[$obs] ?? '',
            'ROMANEIO' => rtrim(substr($rec, 46, 20)),
            'ID_EMBARQUE_1' => rtrim(substr($rec, 66, 20)),
            'ID_EMBARQUE_2' => rtrim(substr($rec, 86, 20)),
            'ID_EMBARQUE_3' => rtrim(substr($rec, 106, 20)),
            'FILIAL_CTE' => rtrim(substr($rec, 126, 10)),
            'SERIE_CTE' => rtrim(substr($rec, 136, 5)),
            'NUM_CTE' => rtrim(substr($rec, 141, 12)),
            'TIPO_ENTREGA' => $this->tipoEntregaLabel($tipoEntrega),
            'COD_EMPRESA_NF' => rtrim(substr($rec, 154, 5)),
            'COD_FILIAL_NF' => rtrim(substr($rec, 159, 5)),
            'DATA_CHEGADA' => $this->formatDateDdmmaaaa(substr($rec, 164, 8)),
            'HORA_CHEGADA' => $this->formatTimeHhmm(substr($rec, 172, 4)),
            'DATA_INI_DESC' => $this->formatDateDdmmaaaa(substr($rec, 176, 8)),
            'HORA_INI_DESC' => $this->formatTimeHhmm(substr($rec, 184, 4)),
            'DATA_FIM_DESC' => $this->formatDateDdmmaaaa(substr($rec, 188, 8)),
            'HORA_FIM_DESC' => $this->formatTimeHhmm(substr($rec, 196, 4)),
            'DATA_SAIDA' => $this->formatDateDdmmaaaa(substr($rec, 200, 8)),
            'HORA_SAIDA' => $this->formatTimeHhmm(substr($rec, 208, 4)),
            'CNPJ_NF_DEV' => rtrim(substr($rec, 212, 14)),
            'SERIE_NF_DEV' => rtrim(substr($rec, 226, 3)),
            'NUM_NF_DEV' => rtrim(substr($rec, 229, 9)),
            'TEXTO' => '',
            'QTD_VOLUMES' => '',
            'QTD_ENTREGUES' => '',
            'COD_ITEM' => '',
            'DESC_ITEM' => '',
            'PEDIDO_PROTHEUS' => '',
            'IDLEXOS' => '',
            'PED_MAR' => '',
            'MARKETPLACE' => '',
            'F2_IDHUB' => '',
            'MATCH_PROTHEUS' => 'nao',
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function enrichWithProtheus(array $rows, string $filial): array
    {
        $filial = $this->normalizeFilial($filial);
        if (!$this->connectionService->isDriverAvailable()) {
            throw new \RuntimeException('Driver SQL Server nao disponivel neste PHP.');
        }

        $pdo = $this->connectionService->connect();
        $this->applyQueryTimeout($pdo);

        foreach (['SF2010', 'SC5010'] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new \RuntimeException('Tabela ' . $table . ' nao encontrada no Protheus.');
            }
        }
        $hasZa4 = $this->tableExists($pdo, 'ZA4010');

        $docs = [];
        foreach ($rows as $row) {
            $doc = $this->normalizeDoc((string) ($row['NOTA_FISCAL'] ?? ''));
            if ($doc !== '') {
                $docs[$doc] = true;
            }
        }
        $docList = array_keys($docs);
        unset($docs);
        if ($docList === []) {
            return $rows;
        }

        $map = [];
        foreach (array_chunk($docList, self::LOOKUP_CHUNK) as $chunk) {
            $partial = $this->lookupPedidosByNf($pdo, $filial, $chunk);
            foreach ($partial as $key => $info) {
                if (!isset($map[$key])) {
                    $map[$key] = $info;
                }
            }
            unset($partial);
        }
        unset($docList);

        if ($hasZa4) {
            $idlexos = [];
            foreach ($map as $info) {
                $id = trim((string) ($info['IDLEXOS'] ?? ''));
                if ($id !== '') {
                    $idlexos[$id] = true;
                }
            }
            $idList = array_keys($idlexos);
            unset($idlexos);
            if ($idList !== []) {
                $pedMarByLex = [];
                foreach (array_chunk($idList, self::LOOKUP_CHUNK) as $chunk) {
                    foreach ($this->lookupPedMarByIdlexo($pdo, $filial, $chunk) as $id => $pedMar) {
                        if (!isset($pedMarByLex[$id])) {
                            $pedMarByLex[$id] = $pedMar;
                        }
                    }
                }
                unset($idList);
                if ($pedMarByLex !== []) {
                    foreach ($map as &$info) {
                        $id = trim((string) ($info['IDLEXOS'] ?? ''));
                        if ($id !== '' && ($info['PED_MAR'] ?? '') === '' && isset($pedMarByLex[$id])) {
                            $info['PED_MAR'] = $pedMarByLex[$id];
                        }
                    }
                    unset($info);
                }
                unset($pedMarByLex);
            }
        }

        foreach ($rows as &$row) {
            $doc = $this->normalizeDoc((string) ($row['NOTA_FISCAL'] ?? ''));
            $serie = trim((string) ($row['SERIE_NF'] ?? ''));
            $keys = [
                $doc . '|' . $serie,
                $doc . '|' . ltrim($serie, '0'),
                $doc . '|*',
            ];
            $hit = null;
            foreach ($keys as $key) {
                if (isset($map[$key])) {
                    $hit = $map[$key];
                    break;
                }
            }
            if ($hit === null) {
                continue;
            }
            $row['PEDIDO_PROTHEUS'] = (string) ($hit['PEDIDO_PROTHEUS'] ?? '');
            $row['IDLEXOS'] = (string) ($hit['IDLEXOS'] ?? '');
            $row['PED_MAR'] = (string) ($hit['PED_MAR'] ?? '');
            $row['MARKETPLACE'] = (string) ($hit['MARKETPLACE'] ?? '');
            $row['F2_IDHUB'] = (string) ($hit['F2_IDHUB'] ?? '');
            $row['MATCH_PROTHEUS'] = 'sim';
        }
        unset($row, $map);

        return $rows;
    }

    /**
     * @param list<string> $docs
     * @return array<string, array<string, string>>
     */
    private function lookupPedidosByNf(PDO $pdo, string $filial, array $docs): array
    {
        $sf2 = ProtheusSqlHelper::tbl('SF2010', 'SF2');
        $sc5 = ProtheusSqlHelper::tbl('SC5010', 'SC5');

        $placeholders = [];
        $params = [':filial_sf2' => $filial];
        foreach ($docs as $i => $doc) {
            $ph = ':d' . $i;
            $placeholders[] = $ph;
            // CHAR do Protheus costuma ser 9 posicoes; evita RTRIM no WHERE (que explode o plano).
            $params[$ph] = $this->normalizeDoc($doc);
        }

        $inList = implode(', ', $placeholders);
        $sql = <<<SQL
SELECT
    RTRIM(SF2.F2_DOC) AS NOTAFISCAL,
    RTRIM(SF2.F2_SERIE) AS SERIE,
    RTRIM(SC5.C5_NUM) AS PEDIDO_PROTHEUS,
    RTRIM(SC5.C5_ZIDLEX) AS IDLEXOS,
    RTRIM(SC5.C5_PEDMAR) AS PED_MAR,
    RTRIM(SC5.C5_ZMAKET) AS MARKETPLACE,
    RTRIM(SF2.F2_IDHUB) AS F2_IDHUB
FROM {$sf2}
INNER JOIN {$sc5}
    ON SF2.F2_FILIAL = SC5.C5_FILIAL
   AND SF2.F2_DOC = SC5.C5_NOTA
   AND SF2.F2_SERIE = SC5.C5_SERIE
   AND SF2.F2_CLIENTE = SC5.C5_CLIENTE
   AND SF2.F2_LOJA = SC5.C5_LOJACLI
   AND SC5.D_E_L_E_T_ = ' '
WHERE SF2.F2_FILIAL = :filial_sf2
  AND SF2.D_E_L_E_T_ = ' '
  AND SF2.F2_DOC IN ({$inList})
ORDER BY SC5.C5_NUM DESC
SQL;

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        $map = [];
        $rowCount = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $rowCount++;
            // Protecao: join anomalo nao pode estourar memoria.
            if ($rowCount > 5000) {
                break;
            }

            $doc = $this->normalizeDoc((string) ($row['NOTAFISCAL'] ?? ''));
            $serie = trim((string) ($row['SERIE'] ?? ''));
            $info = [
                'PEDIDO_PROTHEUS' => trim((string) ($row['PEDIDO_PROTHEUS'] ?? '')),
                'IDLEXOS' => trim((string) ($row['IDLEXOS'] ?? '')),
                'PED_MAR' => trim((string) ($row['PED_MAR'] ?? '')),
                'MARKETPLACE' => trim((string) ($row['MARKETPLACE'] ?? '')),
                'F2_IDHUB' => trim((string) ($row['F2_IDHUB'] ?? '')),
            ];
            $exact = $doc . '|' . $serie;
            $serieAlt = $doc . '|' . ltrim($serie, '0');
            $any = $doc . '|*';
            // ORDER BY C5_NUM DESC: primeira ocorrencia ganha.
            if (!isset($map[$exact])) {
                $map[$exact] = $info;
            }
            if (!isset($map[$serieAlt])) {
                $map[$serieAlt] = $info;
            }
            if (!isset($map[$any])) {
                $map[$any] = $info;
            }
        }
        $stmt->closeCursor();

        return $map;
    }

    /**
     * @param list<string> $idlexos
     * @return array<string, string>
     */
    private function lookupPedMarByIdlexo(PDO $pdo, string $filial, array $idlexos): array
    {
        $za4 = ProtheusSqlHelper::tbl('ZA4010', 'ZA4');
        $placeholders = [];
        $params = [':filial_za4' => $filial];
        foreach ($idlexos as $i => $id) {
            $ph = ':z' . $i;
            $placeholders[] = $ph;
            $params[$ph] = $id;
        }
        $inList = implode(', ', $placeholders);

        $sql = <<<SQL
SELECT
    RTRIM(ZA4.ZA4_IDLEXO) AS IDLEXOS,
    RTRIM(ZA4.ZA4_PEDMAR) AS PED_MAR
FROM {$za4}
WHERE ZA4.ZA4_FILIAL = :filial_za4
  AND ZA4.D_E_L_E_T_ = ' '
  AND ZA4.ZA4_IDLEXO IN ({$inList})
  AND RTRIM(ISNULL(ZA4.ZA4_PEDMAR, '')) <> ''
SQL;

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['IDLEXOS'] ?? ''));
            $ped = trim((string) ($row['PED_MAR'] ?? ''));
            if ($id !== '' && $ped !== '' && !isset($map[$id])) {
                $map[$id] = $ped;
            }
        }
        $stmt->closeCursor();

        return $map;
    }

    /**
     * Mantem so as colunas exibidas na tela (reduz JSON/memoria).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, string>>
     */
    private function slimRows(array $rows): array
    {
        $keys = array_keys(self::tableColumns());
        $slim = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out = [];
            foreach ($keys as $key) {
                $out[$key] = (string) ($row[$key] ?? '');
            }
            $slim[] = $out;
        }

        return $slim;
    }

    private function padRecord(string $line): string
    {
        if (strlen($line) >= self::RECORD_LEN) {
            return substr($line, 0, self::RECORD_LEN);
        }

        return str_pad($line, self::RECORD_LEN, ' ');
    }

    private function normalizeEncoding(string $raw): string
    {
        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }
        $converted = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');

        return is_string($converted) && $converted !== '' ? $converted : $raw;
    }

    private function formatDateDdmmaa(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^\d{6}$/', $value)) {
            return $value;
        }

        return substr($value, 0, 2) . '/' . substr($value, 2, 2) . '/20' . substr($value, 4, 2);
    }

    private function formatDateDdmmaaaa(string $value): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^0+$/', $value) === 1) {
            return '';
        }
        if (!preg_match('/^\d{8}$/', $value)) {
            return $value;
        }

        return substr($value, 0, 2) . '/' . substr($value, 2, 2) . '/' . substr($value, 4, 4);
    }

    private function formatTimeHhmm(string $value): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^0+$/', $value) === 1) {
            return '';
        }
        if (!preg_match('/^\d{4}$/', $value)) {
            return $value;
        }

        return substr($value, 0, 2) . ':' . substr($value, 2, 2);
    }

    private function formatDecimal62(string $value): string
    {
        $value = trim($value);
        if ($value === '' || !ctype_digit($value) || strlen($value) < 3) {
            return $value;
        }
        $int = substr($value, 0, -2);
        $dec = substr($value, -2);

        return ltrim($int, '0') !== '' ? ((int) $int) . ',' . $dec : '0,' . $dec;
    }

    private function tipoEntregaLabel(string $code): string
    {
        return match (trim($code)) {
            '1' => '1 - Primeira entrega',
            '2' => '2 - Reentrega embarcador',
            '3' => '3 - Reentrega destinatario',
            '4' => '4 - Reentrega transp. origem',
            default => trim($code),
        };
    }

    private function normalizeFilial(string $filial): string
    {
        $filial = preg_replace('/\D+/', '', $filial) ?? '';

        return $filial !== '' ? str_pad(substr($filial, 0, 4), 4, '0', STR_PAD_LEFT) : '0101';
    }

    private function normalizeDoc(string $doc): string
    {
        $doc = preg_replace('/\D+/', '', $doc) ?? '';
        if ($doc === '') {
            return '';
        }

        return str_pad(substr($doc, -9), 9, '0', STR_PAD_LEFT);
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            "SELECT 1 AS ok FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = :t"
        );
        $stmt->execute([':t' => $table]);
        $row = $stmt->fetch();

        return is_array($row);
    }

    private function applyQueryTimeout(PDO $pdo): void
    {
        try {
            $pdo->exec('SET LOCK_TIMEOUT ' . (self::QUERY_TIMEOUT_SEC * 1000));
        } catch (\Throwable) {
            // ignore
        }
        if (extension_loaded('pdo_sqlsrv')) {
            try {
                $pdo->setAttribute(\PDO::SQLSRV_ATTR_QUERY_TIMEOUT, self::QUERY_TIMEOUT_SEC);
            } catch (\Throwable) {
                // ignore
            }
        }
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Arquivo excede o tamanho maximo permitido.',
            UPLOAD_ERR_PARTIAL => 'Upload incompleto. Tente novamente.',
            UPLOAD_ERR_NO_FILE => 'Nenhum arquivo enviado.',
            default => 'Falha no upload (codigo ' . $error . ').',
        };
    }
}
