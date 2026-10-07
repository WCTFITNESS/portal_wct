<?php

declare(strict_types=1);

namespace App\Services;

use App\Lib\SimpleZipReader;
use App\Lib\SimpleZipWriter;
use App\Repositories\FiscalDocsRepository;
use App\Repositories\SefazDfeRepository;
use RuntimeException;

/**
 * Central de documentos fiscais: recebe XML de qualquer origem (SEFAZ NF-e, SEFAZ CT-e, Mercado Livre, Rock.IT),
 * evita duplicados pela chave e marca se a nota foi emitida pela própria WCT (mesma raiz de CNPJ).
 */
class FiscalDocsService
{
    public const ORIGENS = [
        'sefaz_nfe' => 'SEFAZ NF-e',
        'sefaz_cte' => 'SEFAZ CT-e',
        'ml' => 'Mercado Livre',
        'rockit' => 'Casas Bahia (Rock.IT)',
    ];

    /** @var list<string>|null */
    private ?array $ownRoots = null;

    public function __construct(
        private FiscalDocsRepository $repository,
        private FiscalXmlParser $parser,
        private SefazDfeRepository $dfeRepository,
        private string $mainCnpj = '',
        private ?MlInvoiceBatchService $ml = null,
        private ?RockitInvoiceService $rockit = null
    ) {
    }

    public function repository(): FiscalDocsRepository
    {
        return $this->repository;
    }

    /**
     * Baixa do Mercado Livre o ZIP das notas do período e grava os XML na central.
     *
     * @param list<string> $tipos chaves de MlInvoiceBatchService::TIPOS
     * @return array{novos: int, atualizados: int, existentes: int, ignorados: int}
     */
    public function importMl(string $de, string $ate, array $tipos): array
    {
        if ($this->ml === null) {
            throw new RuntimeException('Integração com o Mercado Livre indisponível.');
        }

        return $this->importZip('ml', $this->ml->fetchZip($de, $ate, $tipos));
    }

    /**
     * Lista os pedidos do Full Casas Bahia no período e grava na central os XML das notas emitidas
     * e dos cancelamentos.
     *
     * @return array{pedidos: int, novos: int, atualizados: int, existentes: int, ignorados: int, aviso: string}
     */
    public function importRockit(string $de, string $ate): array
    {
        if ($this->rockit === null) {
            throw new RuntimeException('Integração com a Rock.IT indisponível.');
        }
        $idsOf = static fn (array $orders): array => array_values(array_filter(array_map(
            static fn (array $o): string => RockitInvoiceService::summarizeOrder($o)['id_order'],
            $orders
        )));

        $emitidas = $idsOf($this->rockit->listOrders($de, $ate, '3')['orders']);
        $count = $this->importXmlList('rockit', $emitidas !== [] ? $this->rockit->fetchXmlForOrders($emitidas) : []);

        $aviso = '';
        $canceladas = [];
        try {
            $canceladas = $idsOf($this->rockit->listOrders($de, $ate, '7')['orders']);
            if ($canceladas !== []) {
                $extra = $this->importXmlList('rockit', array_merge(
                    $this->rockit->fetchXmlForOrders($canceladas),
                    $this->rockit->fetchXmlForOrders($canceladas, true)
                ));
                foreach ($extra as $k => $n) {
                    $count[$k] += $n;
                }
            }
        } catch (RuntimeException $e) {
            $aviso = 'Notas canceladas não importadas: ' . $e->getMessage();
        }

        return ['pedidos' => count($emitidas) + count($canceladas)] + $count + ['aviso' => $aviso];
    }

    /**
     * Grava um XML na central. Resumos de NF-e recebidos da SEFAZ ficam "pendente" de Ciência da Operação.
     *
     * @return 'inserido'|'atualizado'|'existente'|'ignorado'
     */
    public function store(string $origem, string $xml, ?int $settingsId = null, ?string $nsu = null): string
    {
        $doc = $this->parser->parse($xml);
        if ($doc === null || empty($doc['chave'])) {
            return 'ignorado';
        }

        $doc['origem'] = $origem;
        $doc['settings_id'] = $settingsId;
        $doc['nsu'] = $nsu;
        $doc['xml'] = $xml;
        $doc['propria'] = $this->isOwn((string) ($doc['emit_cnpj'] ?? '')) ? 1 : 0;
        if ($origem === 'sefaz_nfe' && $doc['tipo'] === 'nfe_resumo' && ($doc['situacao'] ?? '') === 'autorizada' && $settingsId !== null) {
            $doc['manifest_status'] = 'pendente';
        }

        $result = $this->repository->upsert($doc);
        if ($doc['tipo'] === 'evento' && in_array((string) ($doc['tp_evento'] ?? ''), FiscalXmlParser::EVENTOS_CANCELAMENTO, true)) {
            $this->repository->markCancelled((string) $doc['chave']);
        }

        return $result;
    }

    /**
     * Grava todos os XML de um ZIP (ignora PDFs e outros arquivos).
     *
     * @return array{novos: int, atualizados: int, existentes: int, ignorados: int}
     */
    public function importZip(string $origem, string $zipContent): array
    {
        $files = [];
        foreach (SimpleZipReader::extract($zipContent) as $name => $content) {
            if (str_ends_with(strtolower((string) $name), '.xml')) {
                $files[] = $content;
            }
        }

        return $this->importXmlList($origem, $files);
    }

    /**
     * @param list<string> $xmls
     * @return array{novos: int, atualizados: int, existentes: int, ignorados: int}
     */
    public function importXmlList(string $origem, array $xmls): array
    {
        $count = ['novos' => 0, 'atualizados' => 0, 'existentes' => 0, 'ignorados' => 0];
        $keys = ['inserido' => 'novos', 'atualizado' => 'atualizados', 'existente' => 'existentes', 'ignorado' => 'ignorados'];
        foreach ($xmls as $xml) {
            $count[$keys[$this->store($origem, $xml)]]++;
        }

        return $count;
    }

    /** Copia para a central os CT-e que já tinham sido baixados antes dela existir (roda uma vez). */
    public function backfillCteIfNeeded(): int
    {
        if ($this->repository->countByOrigem('sefaz_cte') > 0 || $this->dfeRepository->countDocuments([]) === 0) {
            return 0;
        }
        $copied = 0;
        $afterId = 0;
        while (true) {
            $rows = $this->dfeRepository->listXmlAfter($afterId, 200);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $afterId = (int) $row['id'];
                if ($this->store('sefaz_cte', (string) $row['xml'], (int) $row['settings_id'] ?: null, (string) $row['nsu']) === 'inserido') {
                    $copied++;
                }
            }
        }

        return $copied;
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
            $base = $name;
            $n = 2;
            while (isset($used[$name])) {
                $name = preg_replace('/\.xml$/', '-' . $n++ . '.xml', $base) ?? $base;
            }
            $used[$name] = true;
            $zip->addFile($name, (string) $row['xml']);
        }

        return [
            'filename' => 'documentos-fiscais-' . date('Ymd-His') . '.zip',
            'content' => $zip->output(),
            'count' => $zip->count(),
        ];
    }

    /** @return array{filename: string, content: string}|null */
    public function getXmlFile(int $id): ?array
    {
        $rows = $this->repository->findXmlByIds([$id]);

        return $rows === [] ? null : ['filename' => $this->fileNameFor($rows[0]), 'content' => (string) $rows[0]['xml']];
    }

    /** @param array<string, mixed> $row */
    private function fileNameFor(array $row): string
    {
        $chave = preg_replace('/\D/', '', (string) ($row['chave'] ?? '')) ?? '';

        return match ((string) $row['tipo']) {
            'nfe' => $chave . '-nfe.xml',
            'nfe_resumo' => $chave . '-nfe-resumo.xml',
            'cte' => $chave . '-cte.xml',
            'evento' => $chave . '-evento-' . (preg_replace('/\D/', '', (string) ($row['tp_evento'] ?? '')) ?: 'x')
                . ((int) ($row['completo'] ?? 1) === 0 ? '-resumo' : '') . '.xml',
            default => 'documento-' . (int) $row['id'] . '.xml',
        };
    }

    private function isOwn(string $cnpj): bool
    {
        $cnpj = preg_replace('/\D/', '', $cnpj) ?? '';
        if (strlen($cnpj) !== 14) {
            return false;
        }

        return in_array(substr($cnpj, 0, 8), $this->ownRoots(), true);
    }

    /** @return list<string> raízes de CNPJ das empresas cadastradas + CNPJ principal da WCT */
    private function ownRoots(): array
    {
        if ($this->ownRoots === null) {
            $roots = [];
            $cnpjs = array_merge([$this->mainCnpj], array_map(static fn (array $p): string => (string) ($p['cnpj'] ?? ''), $this->dfeRepository->listProfiles()));
            foreach ($cnpjs as $cnpj) {
                $digits = preg_replace('/\D/', '', $cnpj) ?? '';
                if (strlen($digits) === 14) {
                    $roots[substr($digits, 0, 8)] = true;
                }
            }
            $this->ownRoots = array_map('strval', array_keys($roots));
        }

        return $this->ownRoots;
    }
}
