<?php

declare(strict_types=1);

namespace App\Services;

use DOMDocument;
use DOMXPath;

/**
 * Lê qualquer XML fiscal que chega na central (NF-e completa ou resumo, CT-e, CT-e OS e eventos)
 * e devolve os campos de pesquisa. Devolve null para XML que não é documento fiscal (retornos, recibos etc.).
 */
class FiscalXmlParser
{
    public const EVENTOS_CANCELAMENTO = ['110111', '110112'];

    /** @return array<string, mixed>|null */
    public function parse(string $xml): ?array
    {
        $xml = ltrim($xml, "\xEF\xBB\xBF \t\r\n");
        $dom = new DOMDocument();
        if ($xml === '' || @$dom->loadXML($xml, LIBXML_NONET) !== true) {
            return null;
        }
        $xp = new DOMXPath($dom);
        $has = static fn (string $name): bool => $xp->query("//*[local-name()='{$name}']")->length > 0;

        if ($has('infNFe')) {
            return $this->parseNfe($xp);
        }
        if ($has('resNFe')) {
            return $this->parseResNfe($xp);
        }
        if ($has('infCte')) {
            return $this->parseCte($xp);
        }
        if ($has('procEventoNFe') || $has('procEventoCTe') || ($has('infEvento') && !$has('retEnvEvento') && !$has('retEvento'))) {
            return $this->parseEvento($xp);
        }
        if ($has('resEvento')) {
            return $this->parseResEvento($xp);
        }

        return null;
    }

    private function parseNfe(DOMXPath $xp): array
    {
        $v = $this->reader($xp);
        $inf = "(//*[local-name()='infNFe'])[1]";
        $chave = $v("(//*[local-name()='protNFe']//*[local-name()='chNFe'])[1]");
        if ($chave === '') {
            $chave = preg_replace('/\D/', '', $v("{$inf}/@Id")) ?? '';
        }
        $ide = "{$inf}/*[local-name()='ide']";

        return [
            'tipo' => 'nfe',
            'completo' => 1,
            'dedupe_key' => 'nfe:' . $chave,
            'chave' => $chave ?: null,
            'modelo' => $v("{$ide}/*[local-name()='mod']") ?: null,
            'numero' => $v("{$ide}/*[local-name()='nNF']") ?: null,
            'serie' => $v("{$ide}/*[local-name()='serie']") ?: null,
            'dh_emi' => $this->toLocal($v("{$ide}/*[local-name()='dhEmi']") ?: $v("{$ide}/*[local-name()='dEmi']")),
            'nat_op' => mb_substr($v("{$ide}/*[local-name()='natOp']"), 0, 120) ?: null,
            'tp_nf' => $v("{$ide}/*[local-name()='tpNF']") ?: null,
            'emit_cnpj' => $this->doc($v, "{$inf}/*[local-name()='emit']"),
            'emit_nome' => mb_substr($v("{$inf}/*[local-name()='emit']/*[local-name()='xNome']"), 0, 255) ?: null,
            'dest_cnpj' => $this->doc($v, "{$inf}/*[local-name()='dest']"),
            'dest_nome' => mb_substr($v("{$inf}/*[local-name()='dest']/*[local-name()='xNome']"), 0, 255) ?: null,
            'valor' => $this->money($v("{$inf}/*[local-name()='total']/*[local-name()='ICMSTot']/*[local-name()='vNF']")),
            'situacao' => 'autorizada',
        ];
    }

    private function parseResNfe(DOMXPath $xp): array
    {
        $v = $this->reader($xp);
        $res = "(//*[local-name()='resNFe'])[1]";
        $chave = $v("{$res}/*[local-name()='chNFe']");
        $sit = ['1' => 'autorizada', '2' => 'denegada', '3' => 'cancelada'];

        return [
            'tipo' => 'nfe_resumo',
            'completo' => 0,
            'dedupe_key' => 'nfe:' . $chave,
            'chave' => $chave ?: null,
            'modelo' => strlen($chave) === 44 ? substr($chave, 20, 2) : null,
            'numero' => strlen($chave) === 44 ? (ltrim(substr($chave, 25, 9), '0') ?: '0') : null,
            'serie' => strlen($chave) === 44 ? (ltrim(substr($chave, 22, 3), '0') ?: '0') : null,
            'dh_emi' => $this->toLocal($v("{$res}/*[local-name()='dhEmi']")),
            'tp_nf' => $v("{$res}/*[local-name()='tpNF']") ?: null,
            'emit_cnpj' => $this->doc($v, $res),
            'emit_nome' => mb_substr($v("{$res}/*[local-name()='xNome']"), 0, 255) ?: null,
            'valor' => $this->money($v("{$res}/*[local-name()='vNF']")),
            'situacao' => $sit[$v("{$res}/*[local-name()='cSitNFe']")] ?? null,
        ];
    }

    private function parseCte(DOMXPath $xp): array
    {
        $v = $this->reader($xp);
        $inf = "(//*[local-name()='infCte'])[1]";
        $chave = $v("(//*[local-name()='protCTe']//*[local-name()='chCTe'])[1]");
        if ($chave === '') {
            $chave = preg_replace('/\D/', '', $v("{$inf}/@Id")) ?? '';
        }
        $ide = "{$inf}/*[local-name()='ide']";
        $destNome = $v("{$inf}/*[local-name()='dest']/*[local-name()='xNome']");
        $tomaNome = $v("{$inf}/*[local-name()='toma']/*[local-name()='xNome']");

        return [
            'tipo' => 'cte',
            'completo' => 1,
            'dedupe_key' => 'cte:' . $chave,
            'chave' => $chave ?: null,
            'modelo' => $v("{$ide}/*[local-name()='mod']") ?: null,
            'numero' => $v("{$ide}/*[local-name()='nCT']") ?: null,
            'serie' => $v("{$ide}/*[local-name()='serie']") ?: null,
            'dh_emi' => $this->toLocal($v("{$ide}/*[local-name()='dhEmi']")),
            'nat_op' => mb_substr($v("{$ide}/*[local-name()='natOp']"), 0, 120) ?: null,
            'emit_cnpj' => $this->doc($v, "{$inf}/*[local-name()='emit']"),
            'emit_nome' => mb_substr($v("{$inf}/*[local-name()='emit']/*[local-name()='xNome']"), 0, 255) ?: null,
            'dest_cnpj' => $this->doc($v, "{$inf}/*[local-name()='dest']") ?? $this->doc($v, "{$inf}/*[local-name()='toma']"),
            'dest_nome' => mb_substr($destNome !== '' ? $destNome : $tomaNome, 0, 255) ?: null,
            'rem_nome' => mb_substr($v("{$inf}/*[local-name()='rem']/*[local-name()='xNome']"), 0, 255) ?: null,
            'valor' => $this->money($v("{$inf}/*[local-name()='vPrest']/*[local-name()='vTPrest']")),
            'situacao' => 'autorizada',
        ];
    }

    private function parseEvento(DOMXPath $xp): array
    {
        $v = $this->reader($xp);
        $inf = "(//*[local-name()='infEvento'])[1]";
        $chave = $v("{$inf}/*[local-name()='chNFe']") ?: $v("{$inf}/*[local-name()='chCTe']");
        $tp = $v("{$inf}/*[local-name()='tpEvento']");
        $seq = $v("{$inf}/*[local-name()='nSeqEvento']") ?: '1';
        $desc = $v("({$inf}//*[local-name()='descEvento'])[1]");

        return [
            'tipo' => 'evento',
            'completo' => 1,
            'dedupe_key' => 'evento:' . $chave . ':' . $tp . ':' . $seq,
            'chave' => $chave ?: null,
            'modelo' => strlen($chave) === 44 ? substr($chave, 20, 2) : null,
            'numero' => strlen($chave) === 44 ? (ltrim(substr($chave, 25, 9), '0') ?: '0') : null,
            'dh_emi' => $this->toLocal($v("{$inf}/*[local-name()='dhEvento']")),
            'emit_cnpj' => $this->doc($v, $inf),
            'tp_evento' => $tp ?: null,
            'desc_evento' => mb_substr($desc !== '' ? $desc : 'Evento ' . $tp, 0, 120),
        ];
    }

    private function parseResEvento(DOMXPath $xp): array
    {
        $v = $this->reader($xp);
        $res = "(//*[local-name()='resEvento'])[1]";
        $chave = $v("{$res}/*[local-name()='chNFe']");
        $tp = $v("{$res}/*[local-name()='tpEvento']");
        $seq = $v("{$res}/*[local-name()='nSeqEvento']") ?: '1';
        $desc = $v("{$res}/*[local-name()='xEvento']");

        return [
            'tipo' => 'evento',
            'completo' => 0,
            'dedupe_key' => 'evento:' . $chave . ':' . $tp . ':' . $seq,
            'chave' => $chave ?: null,
            'modelo' => strlen($chave) === 44 ? substr($chave, 20, 2) : null,
            'numero' => strlen($chave) === 44 ? (ltrim(substr($chave, 25, 9), '0') ?: '0') : null,
            'dh_emi' => $this->toLocal($v("{$res}/*[local-name()='dhEvento']")),
            'emit_cnpj' => $this->doc($v, $res),
            'tp_evento' => $tp ?: null,
            'desc_evento' => mb_substr($desc !== '' ? $desc : 'Evento ' . $tp, 0, 120),
        ];
    }

    /** @return callable(string): string */
    private function reader(DOMXPath $xp): callable
    {
        return static fn (string $path): string => trim((string) $xp->evaluate('string(' . $path . ')'));
    }

    private function doc(callable $v, string $parent): ?string
    {
        $doc = $v("{$parent}/*[local-name()='CNPJ']") ?: $v("{$parent}/*[local-name()='CPF']");

        return $doc !== '' ? $doc : null;
    }

    private function money(string $value): ?string
    {
        return is_numeric($value) ? $value : null;
    }

    private function toLocal(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                ->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }
}
