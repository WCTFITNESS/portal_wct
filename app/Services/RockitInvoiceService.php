<?php

declare(strict_types=1);

namespace App\Services;

use App\Lib\SecretBox;
use App\Lib\SimpleZipReader;
use App\Lib\SimpleZipWriter;
use App\Repositories\RockitSettingsRepository;
use RuntimeException;

/**
 * API Rock.IT v2 (ERP do Fulfillment Casas Bahia): lista os pedidos do Full e baixa os XML das notas
 * (venda, retorno simbólico, devolução, remessa por conta e ordem, remessa para armazenagem) e dos cancelamentos.
 */
class RockitInvoiceService
{
    private const BASE_URL = 'https://hk-v2.api-myrockit.com.br';
    private const PAGE_LIMIT = 100;
    private const MAX_PAGES = 30;
    private const DOWNLOAD_CHUNK = 50;

    public const INVOICE_STATUS = [
        '0' => 'Pendente', '1' => 'Erro', '2' => 'Enviada', '3' => 'Emitida',
        '4' => 'Salva', '5' => 'E-mail enviado', '6' => 'Não emitir', '7' => 'Cancelada',
    ];

    /** @var list<array{label: string, status: int, type: string, body: string}> */
    private array $diagnostics = [];

    public function __construct(
        private RockitSettingsRepository $repository,
        private SecretBox $secretBox
    ) {
    }

    public function getStatus(): array
    {
        $row = $this->repository->get() ?? [];
        $companies = json_decode((string) ($row['companies_json'] ?? ''), true);

        return [
            'configured' => trim((string) ($row['email'] ?? '')) !== '' && trim((string) ($row['password_blob'] ?? '')) !== '',
            'email' => (string) ($row['email'] ?? ''),
            'id_company' => (string) ($row['id_company'] ?? ''),
            'companies' => is_array($companies) ? $companies : [],
            'last_status' => (string) ($row['last_status'] ?? ''),
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /** @return list<array{label: string, status: int, type: string, body: string}> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /** Valida o login na Rock.IT e só então guarda e-mail + senha (cifrada). */
    public function saveCredentials(string $email, string $password): array
    {
        $email = trim($email);
        if ($email === '' || $password === '') {
            throw new RuntimeException('Informe e-mail e senha da Rock.IT.');
        }
        $login = $this->login($email, $password);
        $companies = $login['companies'];
        $current = $this->repository->get() ?? [];
        $idCompany = (string) ($current['id_company'] ?? '');
        $ids = array_map(static fn (array $c): string => (string) ($c['IDCompany'] ?? ''), $companies);
        if ($idCompany === '' || !in_array($idCompany, $ids, true)) {
            $idCompany = $ids[0] ?? '';
        }

        $this->repository->save([
            'email' => $email,
            'password_blob' => $this->secretBox->encrypt($password),
            'token_blob' => $this->secretBox->encrypt($login['token']),
            'token_expires_at' => $login['expires_at'],
            'companies_json' => json_encode($companies, JSON_UNESCAPED_UNICODE),
            'id_company' => $idCompany,
            'last_status' => 'Login OK em ' . date('d/m/Y H:i'),
        ]);

        return ['companies' => $companies, 'id_company' => $idCompany];
    }

    public function setCompany(string $idCompany): void
    {
        $status = $this->getStatus();
        $ids = array_map(static fn (array $c): string => (string) ($c['IDCompany'] ?? ''), $status['companies']);
        if (!in_array($idCompany, $ids, true)) {
            throw new RuntimeException('Empresa não pertence a este login.');
        }
        $this->repository->save(['id_company' => $idCompany]);
    }

    public function clearCredentials(): void
    {
        $this->repository->clear();
    }

    /**
     * Lista os pedidos do Full no período (data da compra), percorrendo as páginas.
     *
     * @return array{orders: list<array<string, mixed>>, truncated: bool}
     */
    public function listOrders(string $dateFrom, string $dateTo, string $invoiceStatus = ''): array
    {
        foreach ([$dateFrom, $dateTo] as $d) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                throw new RuntimeException('Informe as datas do período.');
            }
        }
        $query = ['DateFrom' => $dateFrom, 'DateTo' => $dateTo, 'Limit' => self::PAGE_LIMIT];
        if ($invoiceStatus !== '' && isset(self::INVOICE_STATUS[$invoiceStatus])) {
            $query['InvoiceStatus'] = $invoiceStatus;
        }

        $orders = [];
        $truncated = false;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $resp = $this->call('GET', '/orders', $query + ['Page' => $page], null, 'Pedidos página ' . $page);
            if ($resp['status'] === 404) {
                break;
            }
            $this->assertOk($resp, 'consultar pedidos');
            $decoded = json_decode($resp['body'], true);
            if (!is_array($decoded)) {
                throw new RuntimeException('A Rock.IT respondeu algo que não é JSON ao listar pedidos.');
            }
            $batch = $this->findList($decoded);
            foreach ($batch as $row) {
                if (is_array($row)) {
                    $orders[] = $row;
                }
            }
            if (count($batch) < self::PAGE_LIMIT) {
                break;
            }
            if ($page === self::MAX_PAGES - 1) {
                $truncated = true;
            }
        }

        return ['orders' => $orders, 'truncated' => $truncated];
    }

    /** Campos de exibição de um pedido, tolerando variações de nome. */
    public static function summarizeOrder(array $order): array
    {
        $pick = static function (array $row, array $keys): string {
            $lower = array_change_key_case($row, CASE_LOWER);
            foreach ($keys as $k) {
                $v = $lower[strtolower($k)] ?? null;
                if ($v !== null && $v !== '' && !is_array($v)) {
                    return (string) $v;
                }
            }

            return '';
        };
        $date = '';
        foreach ($order as $k => $v) {
            if (is_string($v) && preg_match('/date|data|created/i', (string) $k) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
                $date = $v;
                break;
            }
        }

        return [
            'id_order' => $pick($order, ['IDOrder', 'IdOrder', 'id_order', 'id']),
            'order_from' => $pick($order, ['OrderFrom', 'order_from']),
            'order' => $pick($order, ['Order', 'order']),
            'type' => $pick($order, ['TypeOrder', 'OrderType', 'type_order']),
            'nfe_number' => $pick($order, ['NfeNumber', 'NFeNumber', 'InvoiceNumber', 'nfe_number']),
            'chave' => $pick($order, ['ChaveNfe', 'ChaveNFe', 'InvoiceKey', 'chave_nfe']),
            'invoice_status' => $pick($order, ['InvoiceStatusDescription', 'InvoiceStatusName', 'InvoiceStatus']),
            'order_status' => $pick($order, ['OrderStatusDescription', 'OrderStatusName', 'OrderStatus']),
            'consumer' => $pick($order, ['ConsumerName', 'Consumer', 'ClientName', 'CustomerName']),
            'date' => $date,
        ];
    }

    /**
     * Baixa os XML (ou os de cancelamento) dos pedidos e devolve um ZIP.
     *
     * @param list<string> $idOrders
     * @return array{filename: string, content: string, count: int}
     */
    public function downloadZip(array $idOrders, bool $canceled = false): array
    {
        $idOrders = array_values(array_unique(array_filter(array_map(
            static fn ($v): string => preg_replace('/\D/', '', (string) $v) ?? '',
            $idOrders
        ))));
        if ($idOrders === []) {
            throw new RuntimeException('Selecione pelo menos um pedido.');
        }

        $zip = new SimpleZipWriter();
        $used = [];
        foreach (array_chunk($idOrders, self::DOWNLOAD_CHUNK) as $chunk) {
            foreach ($this->fetchXmlFiles($chunk, $canceled) as $file) {
                $name = $this->fileNameFor($file['xml'], $file['hint'], $canceled);
                $n = 2;
                $base = $name;
                while (isset($used[$name])) {
                    $name = preg_replace('/\.xml$/', '-' . $n++ . '.xml', $base) ?? $base;
                }
                $used[$name] = true;
                $zip->addFile($name, $file['xml']);
            }
        }

        if ($zip->count() === 0) {
            throw new RuntimeException($canceled
                ? 'Nenhum XML de cancelamento retornado para os pedidos escolhidos.'
                : 'A Rock.IT não devolveu XML para os pedidos escolhidos (a nota pode ainda não ter sido emitida).');
        }

        return [
            'filename' => ($canceled ? 'casasbahia-full-cancelamentos-' : 'casasbahia-full-xml-') . date('Ymd-His') . '.zip',
            'content' => $zip->output(),
            'count' => $zip->count(),
        ];
    }

    /** Chama o download para 1 pedido só e guarda a resposta crua no diagnóstico (para ajustar a integração). */
    public function diagnoseDownload(string $idOrder, bool $canceled = false): int
    {
        $files = $this->fetchXmlFiles([preg_replace('/\D/', '', $idOrder) ?? ''], $canceled);

        return count($files);
    }

    /**
     * @param list<string> $idOrders
     * @return list<array{hint: string, xml: string}>
     */
    private function fetchXmlFiles(array $idOrders, bool $canceled): array
    {
        $path = $canceled ? '/orders/xml/canceled/download' : '/orders/xml/download';
        $resp = $this->call('POST', $path, [], ['IDOrder' => array_map('intval', $idOrders)], 'Download ' . implode(',', array_slice($idOrders, 0, 3)));
        if ($resp['status'] === 404) {
            return [];
        }
        $this->assertOk($resp, 'baixar XML');

        $out = [];
        $this->collectXml($resp['body'], count($idOrders) === 1 ? 'pedido-' . $idOrders[0] : 'pedido', $out, true);

        return $out;
    }

    /** @param list<array{hint: string, xml: string}> $out */
    private function collectXml(mixed $node, string $hint, array &$out, bool $raw = false): void
    {
        if (is_array($node)) {
            foreach (['IDOrder', 'IdOrder', 'idOrder'] as $k) {
                if (isset($node[$k]) && is_scalar($node[$k])) {
                    $hint = 'pedido-' . $node[$k];
                    break;
                }
            }
            foreach ($node as $value) {
                $this->collectXml($value, $hint, $out);
            }

            return;
        }
        if (!is_string($node) || $node === '') {
            return;
        }

        $s = ltrim($node, "\xEF\xBB\xBF \t\r\n");
        if (str_starts_with($s, '<')) {
            if (preg_match('/<(nfeProc|NFe|procEventoNFe|retEnvEvento|procInutNFe|evento)\b/', $s)) {
                $out[] = ['hint' => $hint, 'xml' => $s];
            }

            return;
        }
        if (str_starts_with($s, 'PK')) {
            foreach (SimpleZipReader::extract($s) as $name => $content) {
                $this->collectXml($content, pathinfo($name, PATHINFO_FILENAME) ?: $hint, $out);
            }

            return;
        }
        if (str_starts_with($s, "\x1f\x8b")) {
            $inflated = @gzdecode($s);
            if ($inflated !== false) {
                $this->collectXml($inflated, $hint, $out);
            }

            return;
        }
        if ($raw && ($s[0] === '{' || $s[0] === '[')) {
            $decoded = json_decode($s, true);
            if (is_array($decoded)) {
                $this->collectXml($decoded, $hint, $out);
            }

            return;
        }
        if (strlen($s) >= 40 && preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $s)) {
            $bin = base64_decode($s, true);
            if ($bin !== false && $bin !== '') {
                $this->collectXml($bin, $hint, $out);
            }
        }
    }

    private function fileNameFor(string $xml, string $hint, bool $canceled): string
    {
        $chave = '';
        if (preg_match('/<chNFe>(\d{44})<\/chNFe>/', $xml, $m) || preg_match('/Id="NFe(\d{44})"/', $xml, $m)) {
            $chave = $m[1];
        }
        $safeHint = preg_replace('/[^A-Za-z0-9_-]+/', '-', $hint) ?: 'nota';
        if ($canceled || str_contains($xml, '<procEventoNFe') || str_contains($xml, '<evento')) {
            return ($chave !== '' ? $chave : $safeHint) . '-cancelamento.xml';
        }

        return ($chave !== '' ? $chave : $safeHint) . '-nfe.xml';
    }

    /** @return list<mixed> */
    private function findList(array $decoded): array
    {
        if (array_is_list($decoded)) {
            return $decoded;
        }
        foreach (['data', 'orders', 'Orders', 'items', 'Items', 'result', 'results', 'content', 'list'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                return $this->findList($decoded[$key]);
            }
        }

        return [];
    }

    /** @return array{token: string, expires_at: ?string, companies: list<array<string, mixed>>} */
    private function login(string $email, string $password): array
    {
        $resp = $this->call('POST', '/login', [], ['email' => $email, 'password' => $password], 'Login', false);
        if ($resp['status'] === 401 || $resp['status'] === 400) {
            throw new RuntimeException('Rock.IT recusou o login: confira e-mail e senha.');
        }
        $this->assertOk($resp, 'fazer login');
        $decoded = json_decode($resp['body'], true);
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : (is_array($decoded) ? $decoded : []);
        $token = (string) ($data['token'] ?? $data['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('Rock.IT não devolveu token no login: ' . mb_substr(strip_tags($resp['body']), 0, 200));
        }
        $expires = isset($data['expiresIn']) ? strtotime((string) $data['expiresIn']) : false;

        return [
            'token' => $token,
            'expires_at' => date('Y-m-d H:i:s', ($expires !== false ? min($expires, time() + 4 * 3600) : time() + 3 * 3600) - 600),
            'companies' => array_values(array_filter((array) ($data['companyList'] ?? []), 'is_array')),
        ];
    }

    private function token(bool $forceLogin = false): string
    {
        $row = $this->repository->get();
        if ($row === null || trim((string) ($row['password_blob'] ?? '')) === '') {
            throw new RuntimeException('Cadastre o login da Rock.IT primeiro.');
        }
        $expires = !empty($row['token_expires_at']) ? strtotime((string) $row['token_expires_at']) : false;
        if (!$forceLogin && !empty($row['token_blob']) && $expires !== false && $expires > time()) {
            return $this->secretBox->decrypt((string) $row['token_blob']);
        }

        $login = $this->login((string) $row['email'], $this->secretBox->decrypt((string) $row['password_blob']));
        $this->repository->save([
            'token_blob' => $this->secretBox->encrypt($login['token']),
            'token_expires_at' => $login['expires_at'],
            'companies_json' => json_encode($login['companies'], JSON_UNESCAPED_UNICODE),
        ]);

        return $login['token'];
    }

    /** @return array{status: int, type: string, body: string} */
    private function call(string $method, string $path, array $query, ?array $json, string $label, bool $auth = true, bool $retry = true): array
    {
        $headers = ['Accept: application/json', 'User-Agent: Mozilla/5.0 (compatible; WCT-Portal/1.0)'];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($auth) {
            $headers[] = 'Authorization: Bearer ' . $this->token();
            $idCompany = (string) (($this->repository->get() ?? [])['id_company'] ?? '');
            if ($idCompany !== '') {
                $headers[] = 'IDCompany: ' . $idCompany;
            }
        }

        $ch = curl_init(self::BASE_URL . $path . ($query !== [] ? '?' . http_build_query($query) : ''));
        if ($ch === false) {
            throw new RuntimeException('Falha ao iniciar a conexão com a Rock.IT.');
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_ENCODING => '',
        ]);
        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Sem resposta da Rock.IT: ' . $error);
        }
        $body = (string) $body;

        $this->diagnostics[] = [
            'label' => $label,
            'status' => $status,
            'type' => $type,
            'body' => $path === '/login' ? preg_replace('/"token"\s*:\s*"[^"]+"/', '"token":"***"', mb_substr($body, 0, 4000)) ?? '' : mb_substr($body, 0, 4000),
        ];

        if ($auth && $retry && $status === 401) {
            $this->token(true);

            return $this->call($method, $path, $query, $json, $label, $auth, false);
        }

        return ['status' => $status, 'type' => $type, 'body' => $body];
    }

    /** @param array{status: int, type: string, body: string} $resp */
    private function assertOk(array $resp, string $action): void
    {
        if ($resp['status'] >= 200 && $resp['status'] < 300) {
            return;
        }
        if ($resp['status'] === 403 && str_contains($resp['body'], 'Algo deu errado')) {
            $ip = preg_match('/Client IP:\s*(?:<\/b>)?\s*([0-9a-f.:]+)/i', $resp['body'], $m) ? $m[1] : '';
            throw new RuntimeException(
                'O firewall da Casas Bahia bloqueou o acesso à Rock.IT a partir do servidor do portal'
                . ($ip !== '' ? ' (IP ' . $ip . ')' : '') . '. Peça no chamado a liberação desse IP.'
            );
        }
        $decoded = json_decode($resp['body'], true);
        $msg = is_array($decoded) ? (string) ($decoded['message'] ?? $decoded['error'] ?? $decoded['msg'] ?? '') : '';

        throw new RuntimeException(
            'Rock.IT respondeu HTTP ' . $resp['status'] . ' ao ' . $action
            . ($msg !== '' ? ': ' . $msg : ': ' . mb_substr(trim(strip_tags($resp['body'])), 0, 200))
        );
    }
}
