<?php

declare(strict_types=1);

namespace App\Services;

use App\Lib\SimpleZipWriter;
use App\Repositories\SefazDfeRepository;
use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * Baixa da SEFAZ (Ambiente Nacional, serviço CTeDistribuicaoDFe) todos os CT-e e eventos em que o CNPJ da WCT
 * aparece (remetente, tomador etc.), usando o certificado A1 da empresa.
 * Regras da SEFAZ: lotes de até 50 documentos, só os últimos 3 meses, e 1 hora de espera quando não há mais nada novo.
 */
class SefazCteDistribuicaoService
{
    private const ENDPOINT = 'https://www1.cte.fazenda.gov.br/CTeDistribuicaoDFe/CTeDistribuicaoDFe.asmx';
    private const SOAP_ACTION = 'http://www.portalfiscal.inf.br/cte/wsdl/CTeDistribuicaoDFe/cteDistDFeInteresse';
    private const MAX_LOTES_POR_EXECUCAO = 40;
    private const TEMPO_MAX_EXECUCAO = 100;
    private const ESPERA_SEM_DOCUMENTOS = 3660;
    private const ESPERA_SERVICO_PARADO = 900;

    public const UF_CODES = [
        '11' => 'RO', '12' => 'AC', '13' => 'AM', '14' => 'RR', '15' => 'PA', '16' => 'AP', '17' => 'TO',
        '21' => 'MA', '22' => 'PI', '23' => 'CE', '24' => 'RN', '25' => 'PB', '26' => 'PE', '27' => 'AL',
        '28' => 'SE', '29' => 'BA', '31' => 'MG', '32' => 'ES', '33' => 'RJ', '35' => 'SP', '41' => 'PR',
        '42' => 'SC', '43' => 'RS', '50' => 'MS', '51' => 'MT', '52' => 'GO', '53' => 'DF',
    ];

    public function __construct(
        private SefazDfeRepository $repository,
        private string $encryptionKey,
        private bool $keyFromEnv
    ) {
    }

    public function isKeyFromEnv(): bool
    {
        return $this->keyFromEnv;
    }

    /** Situação para a tela (nunca inclui o certificado). */
    public function getStatus(): array
    {
        $settings = $this->repository->getSettings() ?? [];
        $nextSync = !empty($settings['next_sync_at']) ? strtotime((string) $settings['next_sync_at']) : false;
        $validTo = !empty($settings['cert_valid_to']) ? strtotime((string) $settings['cert_valid_to']) : false;
        unset($settings['cert_blob']);

        return $settings + [
            'has_cert' => $this->hasCertificate(),
            'cert_expired' => $validTo !== false && $validTo < time(),
            'can_sync_now' => $nextSync === false || $nextSync <= time(),
            'next_sync_ts' => $nextSync ?: null,
            'cnpj' => '',
            'uf_autor' => '42',
            'ult_nsu' => '0',
            'max_nsu' => '0',
        ];
    }

    public function hasCertificate(): bool
    {
        $settings = $this->repository->getSettings();

        return $settings !== null && trim((string) ($settings['cert_blob'] ?? '')) !== '';
    }

    public function saveConfig(string $cnpj, string $ufCode): void
    {
        $cnpj = preg_replace('/\D/', '', $cnpj) ?? '';
        if (strlen($cnpj) !== 14) {
            throw new RuntimeException('Informe o CNPJ com 14 dígitos.');
        }
        if (!isset(self::UF_CODES[$ufCode])) {
            throw new RuntimeException('UF inválida.');
        }

        $current = $this->repository->getSettings() ?? [];
        $fields = ['cnpj' => $cnpj, 'uf_autor' => $ufCode];
        if (($current['cnpj'] ?? '') !== '' && $current['cnpj'] !== $cnpj) {
            // NSU é sequencial por CNPJ: trocar de empresa recomeça do zero.
            $fields += ['ult_nsu' => '0', 'max_nsu' => '0', 'next_sync_at' => null];
        }
        $this->repository->saveSettings($fields);
    }

    /**
     * Lê o .pfx, valida e guarda certificado + chave cifrados. A senha não é armazenada.
     *
     * @return array{subject: string, cnpj: string, valid_to: string}
     */
    public function importCertificate(string $pfxBinary, string $password): array
    {
        if ($pfxBinary === '') {
            throw new RuntimeException('Selecione o arquivo do certificado (.pfx ou .p12).');
        }

        $parts = $this->readPkcs12($pfxBinary, $password);
        $info = openssl_x509_parse($parts['cert']);
        if (!is_array($info)) {
            throw new RuntimeException('Não foi possível ler os dados do certificado.');
        }
        if (openssl_x509_check_private_key($parts['cert'], $parts['pkey']) !== true) {
            throw new RuntimeException('A chave privada não corresponde ao certificado.');
        }

        $validTo = (int) ($info['validTo_time_t'] ?? 0);
        if ($validTo > 0 && $validTo < time()) {
            throw new RuntimeException('Este certificado venceu em ' . date('d/m/Y', $validTo) . '.');
        }

        $subject = (string) ($info['subject']['CN'] ?? $info['name'] ?? '');
        $certCnpj = preg_match('/(\d{14})/', $subject, $m) ? $m[1] : '';

        $blob = $this->encrypt(json_encode([
            'cert' => $parts['cert'],
            'pkey' => $parts['pkey'],
            'chain' => $parts['chain'],
        ], JSON_THROW_ON_ERROR));

        $fields = [
            'cert_blob' => $blob,
            'cert_subject' => mb_substr($subject, 0, 255),
            'cert_cnpj' => $certCnpj !== '' ? $certCnpj : null,
            'cert_valid_to' => $validTo > 0 ? date('Y-m-d H:i:s', $validTo) : null,
        ];
        $current = $this->repository->getSettings() ?? [];
        if (trim((string) ($current['cnpj'] ?? '')) === '' && $certCnpj !== '') {
            $fields['cnpj'] = $certCnpj;
        }
        $this->repository->saveSettings($fields);

        return [
            'subject' => $subject,
            'cnpj' => $certCnpj,
            'valid_to' => $validTo > 0 ? date('d/m/Y', $validTo) : '',
        ];
    }

    public function removeCertificate(): void
    {
        $this->repository->saveSettings([
            'cert_blob' => null,
            'cert_subject' => null,
            'cert_cnpj' => null,
            'cert_valid_to' => null,
        ]);
    }

    /**
     * Busca lotes novos a partir do último NSU salvo.
     *
     * @return array{novos: int, lotes: int, mensagem: string, concluido: bool}
     */
    public function sync(): array
    {
        $settings = $this->repository->getSettings();
        if ($settings === null || trim((string) ($settings['cert_blob'] ?? '')) === '') {
            throw new RuntimeException('Nenhum certificado configurado. Um administrador precisa carregar o certificado A1.');
        }
        $cnpj = (string) ($settings['cnpj'] ?? '');
        $uf = (string) ($settings['uf_autor'] ?? '42');
        if (strlen($cnpj) !== 14) {
            throw new RuntimeException('Configure o CNPJ antes de buscar.');
        }

        $next = !empty($settings['next_sync_at']) ? strtotime((string) $settings['next_sync_at']) : false;
        if ($next !== false && $next > time()) {
            return [
                'novos' => 0,
                'lotes' => 0,
                'mensagem' => 'A SEFAZ só libera nova consulta a partir de ' . date('d/m/Y H:i', $next)
                    . ' (regra de 1 hora quando não há documentos novos).',
                'concluido' => true,
            ];
        }

        $pem = json_decode($this->decrypt((string) $settings['cert_blob']), true);
        if (!is_array($pem) || empty($pem['cert']) || empty($pem['pkey'])) {
            throw new RuntimeException('Não foi possível abrir o certificado salvo. Carregue o certificado novamente.');
        }

        $certFile = $this->writeTempSecret($pem['cert'] . "\n" . implode("\n", (array) ($pem['chain'] ?? [])));
        $keyFile = $this->writeTempSecret((string) $pem['pkey']);

        $ult = (string) ($settings['ult_nsu'] ?? '0');
        $max = (string) ($settings['max_nsu'] ?? '0');
        $novos = 0;
        $lotes = 0;
        $mensagem = '';
        $concluido = false;
        $nextSyncAt = null;
        $started = time();

        try {
            while ($lotes < self::MAX_LOTES_POR_EXECUCAO && (time() - $started) < self::TEMPO_MAX_EXECUCAO) {
                $resp = $this->parseResponse($this->callService($ult, $cnpj, $uf, $certFile, $keyFile));
                $lotes++;

                if ($resp['cStat'] === '138') {
                    foreach ($resp['docs'] as $doc) {
                        $parsed = $this->parseDocument($doc['nsu'], $doc['schema'], $doc['xml']);
                        if ($this->repository->insertDocument($parsed)) {
                            $novos++;
                        }
                    }
                    $ult = $resp['ultNSU'] !== '' ? $resp['ultNSU'] : $ult;
                    $max = $resp['maxNSU'] !== '' ? $resp['maxNSU'] : $max;
                    $this->repository->saveSettings(['ult_nsu' => $ult, 'max_nsu' => $max]);

                    if ((int) $ult >= (int) $max) {
                        $concluido = true;
                        $nextSyncAt = time() + self::ESPERA_SEM_DOCUMENTOS;
                        $mensagem = 'Todos os documentos disponíveis foram baixados.';
                        break;
                    }
                    continue;
                }

                if ($resp['cStat'] === '137') {
                    $ult = $resp['ultNSU'] !== '' ? $resp['ultNSU'] : $ult;
                    $max = $resp['maxNSU'] !== '' ? $resp['maxNSU'] : $max;
                    $concluido = true;
                    $nextSyncAt = time() + self::ESPERA_SEM_DOCUMENTOS;
                    $mensagem = 'Nenhum documento novo na SEFAZ.';
                    break;
                }

                $concluido = true;
                if ($resp['cStat'] === '656') {
                    $nextSyncAt = time() + self::ESPERA_SEM_DOCUMENTOS;
                    $mensagem = 'SEFAZ recusou por consumo indevido (consultas seguidas). Aguarde 1 hora.';
                } elseif (in_array($resp['cStat'], ['108', '109'], true)) {
                    $nextSyncAt = time() + self::ESPERA_SERVICO_PARADO;
                    $mensagem = 'Serviço da SEFAZ fora do ar: ' . $resp['xMotivo'];
                } else {
                    $mensagem = 'SEFAZ respondeu ' . $resp['cStat'] . ' — ' . $resp['xMotivo'];
                }
                break;
            }
        } finally {
            @unlink($certFile);
            @unlink($keyFile);
        }

        if (!$concluido) {
            $mensagem = 'Lote parcial baixado. Clique em buscar de novo para continuar.';
        }

        $this->repository->saveSettings([
            'ult_nsu' => $ult,
            'max_nsu' => $max,
            'last_sync_at' => date('Y-m-d H:i:s'),
            'next_sync_at' => $nextSyncAt !== null ? date('Y-m-d H:i:s', $nextSyncAt) : null,
            'last_status' => mb_substr($mensagem . ' (' . $novos . ' novos)', 0, 500),
        ]);

        return ['novos' => $novos, 'lotes' => $lotes, 'mensagem' => $mensagem, 'concluido' => $concluido];
    }

    /**
     * @param list<int> $ids
     * @return array{filename: string, content: string, count: int}
     */
    public function buildZip(array $ids): array
    {
        $rows = $this->repository->findXmlByIds($ids);
        if ($rows === []) {
            throw new RuntimeException('Nenhum documento selecionado.');
        }

        $zip = new SimpleZipWriter();
        $used = [];
        foreach ($rows as $row) {
            $name = $this->fileNameFor($row);
            if (isset($used[$name])) {
                $name = preg_replace('/\.xml$/', '-' . $row['nsu'] . '.xml', $name) ?? $name;
            }
            $used[$name] = true;
            $zip->addFile($name, (string) $row['xml']);
        }

        return [
            'filename' => 'cte-sefaz-' . date('Ymd-His') . '.zip',
            'content' => $zip->output(),
            'count' => $zip->count(),
        ];
    }

    /** @return array{filename: string, content: string}|null */
    public function getXmlFile(int $id): ?array
    {
        $rows = $this->repository->findXmlByIds([$id]);
        if ($rows === []) {
            return null;
        }

        return ['filename' => $this->fileNameFor($rows[0]), 'content' => (string) $rows[0]['xml']];
    }

    /**
     * @return array{cStat: string, xMotivo: string, ultNSU: string, maxNSU: string, docs: list<array{nsu: string, schema: string, xml: string}>}
     */
    public function parseResponse(string $soap): array
    {
        $dom = new DOMDocument();
        if (@$dom->loadXML($soap, LIBXML_NONET) !== true) {
            throw new RuntimeException('Resposta inválida da SEFAZ: ' . mb_substr(trim(strip_tags($soap)), 0, 200));
        }
        $xp = new DOMXPath($dom);
        $ret = $xp->query("//*[local-name()='retDistDFeInt']")->item(0);
        if ($ret === null) {
            $fault = trim((string) $xp->evaluate("string(//*[local-name()='Reason'] | //*[local-name()='faultstring'])"));
            throw new RuntimeException('SEFAZ não retornou a distribuição' . ($fault !== '' ? ': ' . $fault : '.'));
        }

        $text = static fn (string $tag): string => trim((string) $xp->evaluate("string(*[local-name()='{$tag}'])", $ret));
        $docs = [];
        foreach ($xp->query(".//*[local-name()='docZip']", $ret) as $node) {
            $raw = base64_decode(trim($node->textContent), true);
            $xml = $raw !== false ? @gzdecode($raw) : false;
            if ($xml === false) {
                continue;
            }
            $docs[] = [
                'nsu' => ltrim((string) $node->getAttribute('NSU'), '0') ?: '0',
                'schema' => (string) $node->getAttribute('schema'),
                'xml' => $xml,
            ];
        }

        return [
            'cStat' => $text('cStat'),
            'xMotivo' => $text('xMotivo'),
            'ultNSU' => ltrim($text('ultNSU'), '0') ?: ($text('ultNSU') !== '' ? '0' : ''),
            'maxNSU' => ltrim($text('maxNSU'), '0') ?: ($text('maxNSU') !== '' ? '0' : ''),
            'docs' => $docs,
        ];
    }

    /** Extrai os campos de listagem de um procCTe / procCTeOS / procEventoCTe. */
    public function parseDocument(string $nsu, string $schema, string $xml): array
    {
        $doc = [
            'nsu' => $nsu,
            'schema_name' => mb_substr($schema, 0, 60),
            'tipo' => 'outro',
            'xml' => $xml,
        ];

        $dom = new DOMDocument();
        if (@$dom->loadXML($xml, LIBXML_NONET) !== true) {
            return $doc;
        }
        $xp = new DOMXPath($dom);
        $v = static fn (string $path): string => trim((string) $xp->evaluate('string(' . $path . ')'));
        $n = static fn (string $name): string => "*[local-name()='{$name}']";
        $any = static fn (string $name): string => "//*[local-name()='{$name}']";

        if (stripos($schema, 'procEvento') === 0 || $xp->query($any('infEvento'))->length > 0) {
            $infEvento = $any('infEvento');
            $doc['tipo'] = 'evento';
            $doc['chave'] = $v("{$infEvento}/{$n('chCTe')}") ?: null;
            $doc['tp_evento'] = $v("{$infEvento}/{$n('tpEvento')}") ?: null;
            $doc['desc_evento'] = mb_substr($v("({$any('descEvento')})[1]"), 0, 120) ?: null;
            $doc['dh_emi'] = $this->toLocalDateTime($v("({$infEvento}/{$n('dhEvento')})[1]"));
            $doc['emit_cnpj'] = $v("({$infEvento}/{$n('CNPJ')})[1]") ?: null;

            return $doc;
        }

        $infCte = $any('infCte');
        if ($xp->query($infCte)->length === 0) {
            return $doc;
        }

        $doc['tipo'] = 'cte';
        $chave = $v("({$any('protCTe')}//{$n('chCTe')})[1]");
        if ($chave === '') {
            $chave = preg_replace('/\D/', '', $v("({$infCte})[1]/@Id")) ?? '';
        }
        $doc['chave'] = $chave !== '' ? $chave : null;
        $doc['numero'] = $v("{$infCte}/{$n('ide')}/{$n('nCT')}") ?: null;
        $doc['serie'] = $v("{$infCte}/{$n('ide')}/{$n('serie')}") ?: null;
        $doc['modelo'] = $v("{$infCte}/{$n('ide')}/{$n('mod')}") ?: null;
        $doc['dh_emi'] = $this->toLocalDateTime($v("{$infCte}/{$n('ide')}/{$n('dhEmi')}"));
        $doc['emit_cnpj'] = $v("{$infCte}/{$n('emit')}/{$n('CNPJ')}") ?: null;
        $doc['emit_nome'] = mb_substr($v("{$infCte}/{$n('emit')}/{$n('xNome')}"), 0, 255) ?: null;
        $doc['rem_cnpj'] = ($v("{$infCte}/{$n('rem')}/{$n('CNPJ')}") ?: $v("{$infCte}/{$n('rem')}/{$n('CPF')}")) ?: null;
        $doc['rem_nome'] = mb_substr($v("{$infCte}/{$n('rem')}/{$n('xNome')}"), 0, 255) ?: null;
        $doc['dest_nome'] = mb_substr($v("{$infCte}/{$n('dest')}/{$n('xNome')}"), 0, 255) ?: null;
        $valor = $v("{$infCte}/{$n('vPrest')}/{$n('vTPrest')}");
        $doc['valor'] = is_numeric($valor) ? $valor : null;
        $doc['toma_cnpj'] = $this->tomadorCnpj($xp, $infCte) ?: null;

        return $doc;
    }

    private function tomadorCnpj(DOMXPath $xp, string $infCte): string
    {
        $v = static fn (string $path): string => trim((string) $xp->evaluate('string(' . $path . ')'));
        $toma4 = $v("{$infCte}/*[local-name()='ide']/*[local-name()='toma4']/*[local-name()='CNPJ']");
        if ($toma4 !== '') {
            return $toma4;
        }
        $tomaOs = $v("{$infCte}/*[local-name()='toma']/*[local-name()='CNPJ']");
        if ($tomaOs !== '') {
            return $tomaOs;
        }
        $parties = ['0' => 'rem', '1' => 'exped', '2' => 'receb', '3' => 'dest'];
        $code = $v("{$infCte}/*[local-name()='ide']/*[local-name()='toma3']/*[local-name()='toma']");
        if (isset($parties[$code])) {
            return $v("{$infCte}/*[local-name()='{$parties[$code]}']/*[local-name()='CNPJ']");
        }

        return '';
    }

    private function callService(string $ultNsu, string $cnpj, string $uf, string $certFile, string $keyFile): string
    {
        $distDFeInt = '<distDFeInt xmlns="http://www.portalfiscal.inf.br/cte" versao="1.00">'
            . '<tpAmb>1</tpAmb>'
            . '<cUFAutor>' . $uf . '</cUFAutor>'
            . '<CNPJ>' . $cnpj . '</CNPJ>'
            . '<distNSU><ultNSU>' . str_pad($ultNsu, 15, '0', STR_PAD_LEFT) . '</ultNSU></distNSU>'
            . '</distDFeInt>';

        $envelope = '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap12:Envelope xmlns:soap12="http://www.w3.org/2003/05/soap-envelope">'
            . '<soap12:Body>'
            . '<cteDistDFeInteresse xmlns="http://www.portalfiscal.inf.br/cte/wsdl/CTeDistribuicaoDFe">'
            . '<cteDadosMsg>' . $distDFeInt . '</cteDadosMsg>'
            . '</cteDistDFeInteresse>'
            . '</soap12:Body>'
            . '</soap12:Envelope>';

        $ch = curl_init(self::ENDPOINT);
        if ($ch === false) {
            throw new RuntimeException('Falha ao iniciar a conexão com a SEFAZ.');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $envelope,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/soap+xml; charset=utf-8; action="' . self::SOAP_ACTION . '"',
            ],
            CURLOPT_SSLCERT => $certFile,
            CURLOPT_SSLCERTTYPE => 'PEM',
            CURLOPT_SSLKEY => $keyFile,
            CURLOPT_SSLKEYTYPE => 'PEM',
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 60,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $body === '') {
            throw new RuntimeException('Sem resposta da SEFAZ: ' . ($error !== '' ? $error : 'HTTP ' . $status));
        }
        if ($status === 403) {
            throw new RuntimeException('SEFAZ recusou o certificado (HTTP 403). Confira se é o e-CNPJ A1 da empresa e se está válido.');
        }

        return (string) $body;
    }

    /** @return array{cert: string, pkey: string, chain: list<string>} */
    private function readPkcs12(string $pfx, string $password): array
    {
        $certs = [];
        if (openssl_pkcs12_read($pfx, $certs, $password)) {
            return [
                'cert' => (string) $certs['cert'],
                'pkey' => (string) $certs['pkey'],
                'chain' => array_values(array_map('strval', (array) ($certs['extracerts'] ?? []))),
            ];
        }

        $opensslError = '';
        while (($msg = openssl_error_string()) !== false) {
            $opensslError .= $msg . ' ';
        }
        if (stripos($opensslError, 'mac verify') !== false) {
            throw new RuntimeException('Senha do certificado incorreta.');
        }

        // Certificados antigos usam RC2/3DES, que o OpenSSL 3 só lê com o provider "legacy".
        $legacy = $this->readPkcs12WithCli($pfx, $password);
        if ($legacy !== null) {
            return $legacy;
        }

        throw new RuntimeException(
            'Não foi possível abrir o certificado. Confira a senha. Se estiver correta, o arquivo usa criptografia antiga: '
            . 'peça para reexportar o .pfx com criptografia AES256-SHA256.'
        );
    }

    /** @return array{cert: string, pkey: string, chain: list<string>}|null */
    private function readPkcs12WithCli(string $pfx, string $password): ?array
    {
        $binaries = ['openssl'];
        if (DIRECTORY_SEPARATOR === '\\') {
            array_unshift($binaries, 'C:\\xampp\\apache\\bin\\openssl.exe');
        }

        $pfxFile = $this->writeTempSecret($pfx);
        try {
            foreach ($binaries as $bin) {
                $cmd = escapeshellarg($bin) . ' pkcs12 -legacy -nodes -in ' . escapeshellarg($pfxFile) . ' -passin env:WCT_PFX_PASS';
                $env = array_merge(getenv() ?: [], ['WCT_PFX_PASS' => $password]);
                $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
                if (!is_resource($proc)) {
                    continue;
                }
                $out = (string) stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                if (proc_close($proc) !== 0) {
                    continue;
                }

                preg_match('/-----BEGIN (?:ENCRYPTED |RSA )?PRIVATE KEY-----.+?-----END (?:ENCRYPTED |RSA )?PRIVATE KEY-----/s', $out, $key);
                preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $out, $certs);
                if (empty($key[0]) || empty($certs[0])) {
                    continue;
                }

                foreach ($certs[0] as $i => $certPem) {
                    if (openssl_x509_check_private_key($certPem, $key[0]) === true) {
                        $chain = $certs[0];
                        unset($chain[$i]);

                        return ['cert' => $certPem, 'pkey' => $key[0], 'chain' => array_values($chain)];
                    }
                }
            }
        } finally {
            @unlink($pfxFile);
        }

        return null;
    }

    private function writeTempSecret(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'wctdfe');
        if ($file === false) {
            throw new RuntimeException('Não foi possível criar arquivo temporário.');
        }
        @chmod($file, 0600);
        file_put_contents($file, $contents);

        return $file;
    }

    private function encrypt(string $plain): string
    {
        $key = hash('sha256', $this->encryptionKey, true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Falha ao proteger o certificado.');
        }

        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    private function decrypt(string $blob): string
    {
        if (!str_starts_with($blob, 'v1:')) {
            throw new RuntimeException('Formato do certificado salvo desconhecido. Carregue o certificado novamente.');
        }
        $raw = base64_decode(substr($blob, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('Certificado salvo corrompido. Carregue o certificado novamente.');
        }
        $key = hash('sha256', $this->encryptionKey, true);
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            throw new RuntimeException(
                'Não foi possível abrir o certificado salvo (a chave de criptografia do portal mudou). Carregue o certificado novamente.'
            );
        }

        return $plain;
    }

    private function toLocalDateTime(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable($value);

            return $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    /** @param array<string, mixed> $row */
    private function fileNameFor(array $row): string
    {
        $chave = preg_replace('/\D/', '', (string) ($row['chave'] ?? '')) ?? '';
        $nsu = preg_replace('/\D/', '', (string) ($row['nsu'] ?? '')) ?? '';
        if (($row['tipo'] ?? '') === 'cte' && $chave !== '') {
            return $chave . '-cte.xml';
        }
        if (($row['tipo'] ?? '') === 'evento' && $chave !== '') {
            $tp = preg_replace('/\D/', '', (string) ($row['tp_evento'] ?? '')) ?? '';

            return $chave . '-evento-' . ($tp !== '' ? $tp : 'x') . '-' . $nsu . '.xml';
        }

        return 'nsu-' . $nsu . '.xml';
    }
}
