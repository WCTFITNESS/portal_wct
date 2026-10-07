<?php

declare(strict_types=1);

namespace App\Services;

use App\Lib\SecretBox;
use App\Repositories\SefazDfeRepository;
use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * Baixa da SEFAZ (Ambiente Nacional, NFeDistribuicaoDFe) as NF-e emitidas por terceiros contra cada CNPJ cadastrado
 * e registra automaticamente a Ciência da Operação (evento 210210) para liberar o XML completo, que chega
 * numa consulta seguinte. A SEFAZ não devolve por aqui as notas emitidas pela própria empresa.
 */
class SefazNfeDistribuicaoService
{
    private const DIST_ENDPOINT = 'https://www1.nfe.fazenda.gov.br/NFeDistribuicaoDFe/NFeDistribuicaoDFe.asmx';
    private const DIST_NS = 'http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe';
    private const EVENTO_ENDPOINT = 'https://www.nfe.fazenda.gov.br/NFeRecepcaoEvento4/NFeRecepcaoEvento4.asmx';
    private const EVENTO_NS = 'http://www.portalfiscal.inf.br/nfe/wsdl/NFeRecepcaoEvento4';
    private const NFE_NS = 'http://www.portalfiscal.inf.br/nfe';
    private const DSIG_NS = 'http://www.w3.org/2000/09/xmldsig#';
    private const C14N = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';

    private const MAX_LOTES_POR_EXECUCAO = 40;
    private const TEMPO_MAX_EXECUCAO = 100;
    private const ESPERA_SEM_DOCUMENTOS = 3660;
    private const ESPERA_SERVICO_PARADO = 900;
    private const EVENTOS_POR_LOTE = 20;
    private const CIENCIAS_POR_EXECUCAO = 200;
    private const CSTAT_EVENTO_OK = ['135', '136', '573'];

    public function __construct(
        private SefazDfeRepository $profiles,
        private SecretBox $secretBox,
        private FiscalDocsService $central,
        private SefazCteDistribuicaoService $cteService
    ) {
    }

    /**
     * @return list<array{empresa: string, novos: int, completos: int, ciencias: int, mensagem: string}>
     */
    public function syncAll(int $maxSeconds = self::TEMPO_MAX_EXECUCAO): array
    {
        $results = [];
        $deadline = time() + $maxSeconds;
        foreach ($this->cteService->listProfilesStatus() as $profile) {
            if (!$profile['has_cert'] || $profile['cert_expired']) {
                continue;
            }
            if (time() >= $deadline) {
                $results[] = ['empresa' => $profile['label'], 'novos' => 0, 'completos' => 0, 'ciencias' => 0, 'mensagem' => 'Ficou para a próxima busca (tempo esgotado).'];
                continue;
            }
            try {
                $r = $this->sync((int) $profile['id'], max(10, $deadline - time()));
                $results[] = ['empresa' => $profile['label']] + $r;
            } catch (RuntimeException $e) {
                $results[] = ['empresa' => $profile['label'], 'novos' => 0, 'completos' => 0, 'ciencias' => 0, 'mensagem' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * @return array{novos: int, completos: int, ciencias: int, mensagem: string}
     */
    public function sync(int $profileId, int $maxSeconds = self::TEMPO_MAX_EXECUCAO): array
    {
        $settings = $this->profiles->getProfile($profileId);
        if ($settings === null) {
            throw new RuntimeException('Empresa não encontrada.');
        }
        if (trim((string) ($settings['cert_blob'] ?? '')) === '') {
            throw new RuntimeException('Nenhum certificado configurado para esta empresa.');
        }
        $cnpj = (string) ($settings['cnpj'] ?? '');
        $uf = (string) ($settings['uf_autor'] ?? '42');
        if (strlen($cnpj) !== 14) {
            throw new RuntimeException('Configure o CNPJ antes de buscar.');
        }

        $pem = json_decode($this->secretBox->decrypt((string) $settings['cert_blob']), true);
        if (!is_array($pem) || empty($pem['cert']) || empty($pem['pkey'])) {
            throw new RuntimeException('Não foi possível abrir o certificado salvo. Carregue o certificado novamente.');
        }
        $certFile = $this->writeTempSecret($pem['cert'] . "\n" . implode("\n", (array) ($pem['chain'] ?? [])));
        $keyFile = $this->writeTempSecret((string) $pem['pkey']);

        $started = time();
        $deadline = $started + $maxSeconds;
        $novos = 0;
        $completos = 0;
        $ciencias = 0;
        $mensagem = '';
        $ult = (string) ($settings['nfe_ult_nsu'] ?? '0');
        $max = (string) ($settings['nfe_max_nsu'] ?? '0');
        $nextSyncAt = null;
        $consultou = false;

        try {
            $next = !empty($settings['nfe_next_sync_at']) ? strtotime((string) $settings['nfe_next_sync_at']) : false;
            if ($next !== false && $next > time()) {
                $nextSyncAt = $next;
                $mensagem = 'A SEFAZ só libera nova consulta de NF-e a partir de ' . date('d/m/Y H:i', $next) . '.';
            } else {
                $consultou = true;
                $concluido = false;
                $lotes = 0;
                while ($lotes < self::MAX_LOTES_POR_EXECUCAO && time() < $deadline) {
                    $resp = $this->cteService->parseResponse($this->callDistribuicao($ult, $cnpj, $uf, $certFile, $keyFile));
                    $lotes++;

                    if ($resp['cStat'] === '138') {
                        foreach ($resp['docs'] as $doc) {
                            $r = $this->central->store('sefaz_nfe', $doc['xml'], $profileId, $doc['nsu']);
                            if ($r === 'inserido') {
                                $novos++;
                            } elseif ($r === 'atualizado') {
                                $completos++;
                            }
                        }
                        $ult = $resp['ultNSU'] !== '' ? $resp['ultNSU'] : $ult;
                        $max = $resp['maxNSU'] !== '' ? $resp['maxNSU'] : $max;
                        $this->profiles->updateProfile($profileId, ['nfe_ult_nsu' => $ult, 'nfe_max_nsu' => $max]);
                        if ((int) $ult >= (int) $max) {
                            $concluido = true;
                            $nextSyncAt = time() + self::ESPERA_SEM_DOCUMENTOS;
                            $mensagem = 'Todas as NF-e disponíveis foram baixadas.';
                            break;
                        }
                        continue;
                    }

                    $concluido = true;
                    if ($resp['cStat'] === '137') {
                        $ult = $resp['ultNSU'] !== '' ? $resp['ultNSU'] : $ult;
                        $max = $resp['maxNSU'] !== '' ? $resp['maxNSU'] : $max;
                        $nextSyncAt = time() + self::ESPERA_SEM_DOCUMENTOS;
                        $mensagem = 'Nenhuma NF-e nova na SEFAZ.';
                    } elseif ($resp['cStat'] === '656') {
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
                if (!$concluido) {
                    $mensagem = 'Lote parcial de NF-e baixado. Busque de novo para continuar.';
                }
            }

            $ciencia = $this->sendPendingCiencia($profileId, $cnpj, $pem, $certFile, $keyFile, max($deadline, time() + 20));
            $ciencias = $ciencia['ok'];
            if ($ciencia['erro'] !== '') {
                $mensagem .= ' Ciência da Operação: ' . $ciencia['erro'];
            }
        } finally {
            @unlink($certFile);
            @unlink($keyFile);
        }

        $fields = [
            'nfe_ult_nsu' => $ult,
            'nfe_max_nsu' => $max,
            'nfe_last_status' => mb_substr(trim($mensagem) . ' (' . $novos . ' novas, ' . $completos . ' XML completos, ' . $ciencias . ' ciências)', 0, 500),
        ];
        if ($consultou) {
            $fields['nfe_last_sync_at'] = date('Y-m-d H:i:s');
            $fields['nfe_next_sync_at'] = $nextSyncAt !== null ? date('Y-m-d H:i:s', $nextSyncAt) : null;
        }
        $this->profiles->updateProfile($profileId, $fields);

        return ['novos' => $novos, 'completos' => $completos, 'ciencias' => $ciencias, 'mensagem' => trim($mensagem)];
    }

    /**
     * Registra a Ciência da Operação dos resumos pendentes, em lotes de 20.
     *
     * @param array{cert: string, pkey: string} $pem
     * @return array{ok: int, erro: string}
     */
    private function sendPendingCiencia(int $profileId, string $cnpj, array $pem, string $certFile, string $keyFile, int $deadline): array
    {
        $repo = $this->central->repository();
        $ok = 0;
        $sent = 0;
        $erro = '';
        while ($sent < self::CIENCIAS_POR_EXECUCAO && time() < $deadline) {
            $pending = $repo->pendingCiencia($profileId, self::EVENTOS_POR_LOTE);
            if ($pending === []) {
                break;
            }
            $sent += count($pending);
            $eventos = [];
            foreach ($pending as $row) {
                $eventos[] = $this->buildCienciaEvento($cnpj, (string) $row['chave'], $pem);
            }

            try {
                $result = $this->parseEventoResponse($this->callEvento($eventos, $certFile, $keyFile));
            } catch (RuntimeException $e) {
                return ['ok' => $ok, 'erro' => $e->getMessage()];
            }

            if ($result['cStat'] !== '128') {
                foreach ($pending as $row) {
                    $repo->setManifest((int) $row['id'], 'erro', $result['cStat'] . ' — ' . $result['xMotivo']);
                }

                return ['ok' => $ok, 'erro' => 'SEFAZ recusou o lote (' . $result['cStat'] . ' — ' . $result['xMotivo'] . ').'];
            }

            foreach ($pending as $row) {
                $ret = $result['eventos'][(string) $row['chave']] ?? null;
                if ($ret !== null && in_array($ret['cStat'], self::CSTAT_EVENTO_OK, true)) {
                    $repo->setManifest((int) $row['id'], 'ciencia', $ret['xMotivo']);
                    $ok++;
                } else {
                    $repo->setManifest((int) $row['id'], 'erro', $ret !== null ? $ret['cStat'] . ' — ' . $ret['xMotivo'] : 'Sem retorno da SEFAZ para esta chave.');
                    $erro = 'algumas notas foram recusadas (veja a coluna Ciência).';
                }
            }
        }

        return ['ok' => $ok, 'erro' => $erro];
    }

    /**
     * Monta e assina (XMLDSig, RSA-SHA1, C14N) o evento 210210 de uma NF-e.
     *
     * @param array{cert: string, pkey: string} $pem
     */
    public function buildCienciaEvento(string $cnpj, string $chave, array $pem, ?DateTimeImmutable $when = null): string
    {
        if (!preg_match('/^\d{44}$/', $chave) || !preg_match('/^\d{14}$/', $cnpj)) {
            throw new RuntimeException('Chave ou CNPJ inválido para a Ciência da Operação.');
        }
        $id = 'ID210210' . $chave . '01';
        $dh = ($when ?? new DateTimeImmutable('-1 minute'))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d\TH:i:sP');

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadXML(
            '<evento xmlns="' . self::NFE_NS . '" versao="1.00"><infEvento Id="' . $id . '">'
            . '<cOrgao>91</cOrgao><tpAmb>1</tpAmb><CNPJ>' . $cnpj . '</CNPJ><chNFe>' . $chave . '</chNFe>'
            . '<dhEvento>' . $dh . '</dhEvento><tpEvento>210210</tpEvento><nSeqEvento>1</nSeqEvento><verEvento>1.00</verEvento>'
            . '<detEvento versao="1.00"><descEvento>Ciencia da Operacao</descEvento></detEvento>'
            . '</infEvento></evento>'
        );
        $infEvento = $dom->getElementsByTagName('infEvento')->item(0);
        $digest = base64_encode(sha1($infEvento->C14N(false, false), true));

        $certBody = preg_replace('/-----[^-]+-----|\s+/', '', (string) $pem['cert']) ?? '';
        $sigDoc = new DOMDocument('1.0', 'UTF-8');
        $sigDoc->loadXML(
            '<Signature xmlns="' . self::DSIG_NS . '"><SignedInfo>'
            . '<CanonicalizationMethod Algorithm="' . self::C14N . '"/>'
            . '<SignatureMethod Algorithm="http://www.w3.org/2000/09/xmldsig#rsa-sha1"/>'
            . '<Reference URI="#' . $id . '"><Transforms>'
            . '<Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/>'
            . '<Transform Algorithm="' . self::C14N . '"/>'
            . '</Transforms><DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"/>'
            . '<DigestValue>' . $digest . '</DigestValue></Reference></SignedInfo>'
            . '<SignatureValue></SignatureValue>'
            . '<KeyInfo><X509Data><X509Certificate>' . $certBody . '</X509Certificate></X509Data></KeyInfo></Signature>'
        );
        $signature = $dom->importNode($sigDoc->documentElement, true);
        $dom->documentElement->appendChild($signature);

        $signedInfo = $signature->getElementsByTagName('SignedInfo')->item(0);
        $rawSignature = '';
        if (!openssl_sign($signedInfo->C14N(false, false), $rawSignature, (string) $pem['pkey'], OPENSSL_ALGO_SHA1)) {
            throw new RuntimeException('Não foi possível assinar o evento com o certificado.');
        }
        $signature->getElementsByTagName('SignatureValue')->item(0)->nodeValue = base64_encode($rawSignature);

        return (string) $dom->saveXML($dom->documentElement);
    }

    /**
     * @return array{cStat: string, xMotivo: string, eventos: array<string, array{cStat: string, xMotivo: string}>}
     */
    public function parseEventoResponse(string $soap): array
    {
        $dom = new DOMDocument();
        if (@$dom->loadXML($soap, LIBXML_NONET) !== true) {
            throw new RuntimeException('Resposta inválida da SEFAZ no evento: ' . mb_substr(trim(strip_tags($soap)), 0, 200));
        }
        $xp = new DOMXPath($dom);
        $ret = $xp->query("//*[local-name()='retEnvEvento']")->item(0);
        if ($ret === null) {
            $fault = trim((string) $xp->evaluate("string(//*[local-name()='Reason'] | //*[local-name()='faultstring'])"));
            throw new RuntimeException('SEFAZ não retornou o resultado do evento' . ($fault !== '' ? ': ' . $fault : '.'));
        }
        $text = static fn (string $tag, \DOMNode $ctx): string => trim((string) $xp->evaluate("string(*[local-name()='{$tag}'])", $ctx));
        $eventos = [];
        foreach ($xp->query(".//*[local-name()='retEvento']/*[local-name()='infEvento']", $ret) as $inf) {
            $eventos[$text('chNFe', $inf)] = ['cStat' => $text('cStat', $inf), 'xMotivo' => $text('xMotivo', $inf)];
        }

        return ['cStat' => $text('cStat', $ret), 'xMotivo' => $text('xMotivo', $ret), 'eventos' => $eventos];
    }

    /** @param list<string> $eventos */
    private function callEvento(array $eventos, string $certFile, string $keyFile): string
    {
        $idLote = date('ymdHis') . random_int(100, 999);
        $env = '<envEvento xmlns="' . self::NFE_NS . '" versao="1.00"><idLote>' . $idLote . '</idLote>' . implode('', $eventos) . '</envEvento>';

        return $this->post(
            self::EVENTO_ENDPOINT,
            self::EVENTO_NS . '/nfeRecepcaoEvento',
            '<nfeDadosMsg xmlns="' . self::EVENTO_NS . '">' . $env . '</nfeDadosMsg>',
            $certFile,
            $keyFile
        );
    }

    private function callDistribuicao(string $ultNsu, string $cnpj, string $uf, string $certFile, string $keyFile): string
    {
        $distDFeInt = '<distDFeInt xmlns="' . self::NFE_NS . '" versao="1.01">'
            . '<tpAmb>1</tpAmb><cUFAutor>' . $uf . '</cUFAutor><CNPJ>' . $cnpj . '</CNPJ>'
            . '<distNSU><ultNSU>' . str_pad($ultNsu, 15, '0', STR_PAD_LEFT) . '</ultNSU></distNSU>'
            . '</distDFeInt>';

        return $this->post(
            self::DIST_ENDPOINT,
            self::DIST_NS . '/nfeDistDFeInteresse',
            '<nfeDistDFeInteresse xmlns="' . self::DIST_NS . '"><nfeDadosMsg>' . $distDFeInt . '</nfeDadosMsg></nfeDistDFeInteresse>',
            $certFile,
            $keyFile
        );
    }

    private function post(string $endpoint, string $action, string $body, string $certFile, string $keyFile): string
    {
        $envelope = '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap12:Envelope xmlns:soap12="http://www.w3.org/2003/05/soap-envelope"><soap12:Body>'
            . $body
            . '</soap12:Body></soap12:Envelope>';

        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new RuntimeException('Falha ao iniciar a conexão com a SEFAZ.');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $envelope,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/soap+xml; charset=utf-8; action="' . $action . '"'],
            CURLOPT_SSLCERT => $certFile,
            CURLOPT_SSLCERTTYPE => 'PEM',
            CURLOPT_SSLKEY => $keyFile,
            CURLOPT_SSLKEYTYPE => 'PEM',
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 60,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $response === '') {
            throw new RuntimeException('Sem resposta da SEFAZ: ' . ($error !== '' ? $error : 'HTTP ' . $status));
        }
        if ($status === 403) {
            throw new RuntimeException('SEFAZ recusou o certificado (HTTP 403). Confira se é o e-CNPJ A1 da empresa e se está válido.');
        }

        return (string) $response;
    }

    private function writeTempSecret(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'wctnfe');
        if ($file === false) {
            throw new RuntimeException('Não foi possível criar arquivo temporário.');
        }
        @chmod($file, 0600);
        file_put_contents($file, $contents);

        return $file;
    }
}
