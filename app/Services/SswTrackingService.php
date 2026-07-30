<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Consultas de rastreamento no SSW (ssw.inf.br) como remetente WCT.
 *
 * - Por documentos: POST /2/resultSSW (cnpj + NR + chave)
 * - Últimos N dias: POST /2/ssw_resultSSW (cnpj + senha + limite_dias)
 * - Detalhe API: POST /api/tracking (JSON)
 */
class SswTrackingService
{
    public const DEFAULT_CNPJ = '17751890000176';

    private string $cnpjDefault;
    private string $senhaDefault;
    private int $timeoutSeconds;

    public function __construct(?array $config = null)
    {
        $config = $config ?? [];
        $cnpj = preg_replace('/\D+/', '', (string) ($config['cnpj'] ?? getenv('PORTAL_SSW_CNPJ') ?: self::DEFAULT_CNPJ)) ?? '';
        $this->cnpjDefault = $cnpj !== '' ? $cnpj : self::DEFAULT_CNPJ;
        $this->senhaDefault = (string) ($config['senha'] ?? (getenv('PORTAL_SSW_SENHA') !== false ? getenv('PORTAL_SSW_SENHA') : ''));
        $this->timeoutSeconds = max(10, (int) ($config['timeout'] ?? 40));
    }

    public function defaultCnpj(): string
    {
        return $this->cnpjDefault;
    }

    public function defaultSenha(): string
    {
        return $this->senhaDefault;
    }

    public function hasConfiguredSenha(): bool
    {
        return trim($this->senhaDefault) !== '';
    }

    /**
     * Rastreamento pelo remetente (NFs / pedidos / coletas).
     *
     * @return array{
     *   mode: string,
     *   cnpj: string,
     *   remetente_label: string,
     *   rows: list<array{documento: string, pedido: string, unidade: string, data_hora: string, situacao_titulo: string, situacao_detalhe: string, detalhe_url: string|null}>,
     *   raw_message: string|null
     * }
     */
    public function trackBySenderDocuments(string $cnpj, string $documents, ?string $senha = null): array
    {
        $cnpj = $this->normalizeCnpj($cnpj);
        $docs = $this->normalizeDocumentList($documents);
        if ($docs === '') {
            throw new RuntimeException('Informe ao menos um N Fiscal, Pedido ou Coleta (um por linha).');
        }

        $senha = $senha !== null && trim($senha) !== '' ? trim($senha) : $this->senhaDefault;
        $html = $this->postForm('https://ssw.inf.br/2/resultSSW', [
            'cnpj' => $cnpj,
            'NR' => $docs,
            'chave' => $senha,
        ]);

        return $this->parseResultHtml($html, 'remetente', $cnpj);
    }

    /**
     * Lista rastreamentos dos últimos N dias (exige senha da transportadora).
     *
     * @return array{
     *   mode: string,
     *   cnpj: string,
     *   remetente_label: string,
     *   rows: list<array{documento: string, pedido: string, unidade: string, data_hora: string, situacao_titulo: string, situacao_detalhe: string, detalhe_url: string|null}>,
     *   raw_message: string|null
     * }
     */
    public function trackBySenderDays(string $cnpj, int $days, ?string $senha = null): array
    {
        $cnpj = $this->normalizeCnpj($cnpj);
        $days = max(1, min(30, $days));
        $senha = $senha !== null && trim($senha) !== '' ? trim($senha) : $this->senhaDefault;
        if ($senha === '') {
            throw new RuntimeException('A consulta dos últimos dias exige a senha fornecida pela transportadora (SSW).');
        }

        $html = $this->postForm('https://ssw.inf.br/2/ssw_resultSSW', [
            'cnpj' => $cnpj,
            'senha' => $senha,
            'limite_dias' => (string) $days,
        ]);

        return $this->parseResultHtml($html, 'remetente_dias', $cnpj);
    }

    /**
     * Detalhe via WebAPI oficial (um documento).
     *
     * @return array{success: bool, message: string|null, data: mixed}
     */
    public function trackViaApi(string $cnpj, string $docType, string $docValue, ?string $senha = null): array
    {
        $cnpj = $this->normalizeCnpj($cnpj);
        $docValue = trim($docValue);
        if ($docValue === '') {
            throw new RuntimeException('Informe o documento para a API.');
        }

        $senha = $senha !== null && trim($senha) !== '' ? trim($senha) : $this->senhaDefault;
        $payload = ['cnpj' => $cnpj];
        if ($senha !== '') {
            $payload['senha'] = $senha;
        }

        $docType = strtolower(trim($docType));
        match ($docType) {
            'nro_nf', 'nf' => $payload['nro_nf'] = (int) preg_replace('/\D+/', '', $docValue),
            'pedido' => $payload['pedido'] = $docValue,
            'chave_nfe', 'chave' => $payload['chave_nfe'] = preg_replace('/\D+/', '', $docValue) ?? $docValue,
            'nro_coleta', 'coleta' => $payload['nro_coleta'] = (int) preg_replace('/\D+/', '', $docValue),
            default => throw new RuntimeException('Tipo de documento inválido para API.'),
        };

        $json = $this->postJson('https://ssw.inf.br/api/tracking', $payload);
        $success = (bool) ($json['success'] ?? false);

        return [
            'success' => $success,
            'message' => isset($json['message']) ? (string) $json['message'] : null,
            'data' => $json,
        ];
    }

    private function normalizeCnpj(string $cnpj): string
    {
        $digits = preg_replace('/\D+/', '', $cnpj) ?? '';
        if ($digits === '') {
            $digits = $this->cnpjDefault;
        }
        if (strlen($digits) < 11 || strlen($digits) > 14) {
            throw new RuntimeException('CNPJ/CPF do remetente inválido.');
        }

        return $digits;
    }

    private function normalizeDocumentList(string $documents): string
    {
        $lines = preg_split('/[\r\n,;]+/', $documents) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $clean[] = $line;
            }
        }

        return implode("\n", array_slice($clean, 0, 100));
    }

    /**
     * @param array<string, string> $fields
     */
    private function postForm(string $url, array $fields): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Extensão cURL do PHP não está disponível.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT => 'PortalWCT-SSW/1.0',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: text/html,application/xhtml+xml',
            ],
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $errno !== 0) {
            throw new RuntimeException('Falha ao consultar SSW: ' . ($error !== '' ? $error : 'erro de rede'));
        }
        if ($status >= 400) {
            throw new RuntimeException('SSW retornou HTTP ' . $status . '.');
        }

        return (string) $body;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $payload): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Extensão cURL do PHP não está disponível.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT => 'PortalWCT-SSW/1.0',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $errno !== 0) {
            throw new RuntimeException('Falha na API SSW: ' . ($error !== '' ? $error : 'erro de rede'));
        }
        if ($status >= 400) {
            throw new RuntimeException('API SSW retornou HTTP ' . $status . '.');
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Resposta JSON inválida da API SSW.');
        }

        return $decoded;
    }

    /**
     * @return array{
     *   mode: string,
     *   cnpj: string,
     *   remetente_label: string,
     *   rows: list<array{documento: string, pedido: string, unidade: string, data_hora: string, situacao_titulo: string, situacao_detalhe: string, detalhe_url: string|null}>,
     *   raw_message: string|null
     * }
     */
    private function parseResultHtml(string $html, string $mode, string $cnpj): array
    {
        $remetenteLabel = '';
        if (preg_match('/Remetente:\s*<\/span>\s*<div[^>]*>\s*<span[^>]*>(.*?)<\/span>/is', $html, $m)) {
            $remetenteLabel = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $rows = [];
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);
        /** @var \DOMNodeList<\DOMElement> $trs */
        $trs = $xpath->query('//tr[.//td[contains(concat(" ", normalize-space(@class), " "), " rastreamento ")]]');
        if ($trs !== false) {
            foreach ($trs as $tr) {
                $tds = [];
                foreach ($tr->childNodes as $child) {
                    if ($child instanceof \DOMElement && strtolower($child->tagName) === 'td') {
                        $tds[] = $child;
                    }
                }
                if (count($tds) < 3) {
                    continue;
                }

                $docCell = $this->nodeText($tds[0]);
                $unitCell = $this->nodeHtmlLines($tds[1]);
                $sitTitulo = '';
                $sitDetalhe = '';
                $detalheUrl = null;

                $tituloNodes = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " titulo ")]', $tds[2]);
                if ($tituloNodes !== false && $tituloNodes->length > 0) {
                    $sitTitulo = $this->nodeText($tituloNodes->item(0));
                }

                $detailParts = [];
                foreach ($tds[2]->childNodes as $child) {
                    if ($child instanceof \DOMElement) {
                        $class = ' ' . $child->getAttribute('class') . ' ';
                        if (str_contains($class, ' titulo ')) {
                            continue;
                        }
                        if (strtolower($child->tagName) === 'a') {
                            $href = trim($child->getAttribute('href'));
                            $onclick = trim($child->getAttribute('onclick'));
                            $label = $this->nodeText($child);
                            if (stripos($label, 'detalhe') !== false || stripos($onclick, 'op') === 0) {
                                if ($onclick !== '' && preg_match('/\([\'\"]([^\'\"]+)[\'\"]/', $onclick, $om)) {
                                    $detalheUrl = 'https://ssw.inf.br/2/SSWDetalhado?' . ltrim($om[1], '?');
                                } elseif ($href !== '' && $href !== '#') {
                                    $detalheUrl = str_starts_with($href, 'http') ? $href : 'https://ssw.inf.br' . $href;
                                }
                            }
                            continue;
                        }
                    }
                    $txt = trim(html_entity_decode(strip_tags($dom->saveHTML($child) ?: ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    $txt = preg_replace('/\s+/u', ' ', $txt) ?? $txt;
                    if ($txt !== '' && $txt !== "\xc2\xa0") {
                        $detailParts[] = $txt;
                    }
                }
                $sitDetalhe = trim(implode("\n", $detailParts));
                if ($sitTitulo === '' && $sitDetalhe !== '') {
                    $sitTitulo = strtok($sitDetalhe, "\n") ?: $sitDetalhe;
                }

                $docLines = preg_split('/\R+/', $docCell) ?: [];
                $documento = trim((string) ($docLines[0] ?? ''));
                $pedido = trim((string) ($docLines[1] ?? ''));
                if ($documento === '' && $pedido === '') {
                    continue;
                }

                $unidade = trim((string) ($unitCell[0] ?? ''));
                $dataHora = trim((string) ($unitCell[1] ?? ''));
                if ($dataHora === '' && isset($unitCell[0]) && preg_match('/\d{2}\/\d{2}\/\d{2}/', $unitCell[0])) {
                    $dataHora = $unidade;
                    $unidade = '';
                }

                $rows[] = [
                    'documento' => $documento,
                    'pedido' => $pedido,
                    'unidade' => $unidade,
                    'data_hora' => $dataHora,
                    'situacao_titulo' => $sitTitulo,
                    'situacao_detalhe' => $sitDetalhe,
                    'detalhe_url' => $detalheUrl,
                ];
            }
        }

        $rawMessage = null;
        if ($rows === []) {
            if (stripos($html, 'senha') !== false && (stripos($html, 'inválid') !== false || stripos($html, 'invalida') !== false)) {
                $rawMessage = 'Senha inválida ou não autorizada pela transportadora.';
            } elseif (stripos($html, 'nenhum') !== false) {
                $rawMessage = 'Nenhum documento localizado no SSW para os filtros informados.';
            } else {
                $rawMessage = 'Nenhum resultado retornado. Confira CNPJ, documentos e senha.';
            }
        }

        return [
            'mode' => $mode,
            'cnpj' => $cnpj,
            'remetente_label' => $remetenteLabel !== '' ? $remetenteLabel : $this->formatCnpj($cnpj),
            'rows' => $rows,
            'raw_message' => $rawMessage,
        ];
    }

    private function nodeText(?\DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }
        $text = html_entity_decode($node->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xc2\xa0", ' ', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\R\s*/u', "\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * @return list<string>
     */
    private function nodeHtmlLines(\DOMNode $node): array
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $child->ownerDocument?->saveHTML($child) ?? '';
        }
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $text = trim(strip_tags($html));
        $text = str_replace("\xc2\xa0", ' ', $text);
        $lines = preg_split('/\R+/', $text) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    private function formatCnpj(string $digits): string
    {
        if (strlen($digits) === 14) {
            return substr($digits, 0, 2) . '.' . substr($digits, 2, 3) . '.' . substr($digits, 5, 3)
                . '/' . substr($digits, 8, 4) . '-' . substr($digits, 12, 2);
        }

        return $digits;
    }
}
