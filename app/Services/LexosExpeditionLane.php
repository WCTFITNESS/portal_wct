<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Agrupa o texto de status (Lexos, webhook ou ML) em etapas semelhantes à Expedição do Lexos Hub.
 * Ajuste os critérios conforme os valores reais retornados pela API / webhook.
 */
final class LexosExpeditionLane
{
    public const LANE_TODOS = 'todos';

    /** @return list<string> Ordem exibida nas abas */
    public static function laneOrder(): array
    {
        return [
            self::LANE_TODOS,
            'aberto',
            'a_combinar',
            'faturado',
            'etiqueta_gerada',
            'aguardando_resolucao',
            'conferir',
            'pronto_envio',
            'atraso',
            'enviado',
            'entregue',
            'outros',
        ];
    }

    /** @return array<string, string> id => rótulo curto para aba */
    public static function laneLabels(): array
    {
        return [
            self::LANE_TODOS => 'Todos',
            'aberto' => 'Aberto',
            'a_combinar' => 'A combinar',
            'faturado' => 'Faturado',
            'etiqueta_gerada' => 'Etiqueta gerada',
            'aguardando_resolucao' => 'Aguardando resolução',
            'conferir' => 'Conferir',
            'pronto_envio' => 'Pronto p/ envio',
            'atraso' => 'Atraso envio',
            'enviado' => 'Enviado',
            'entregue' => 'Entregue',
            'outros' => 'Outros',
        ];
    }

    public static function mapLane(string $status): string
    {
        $s = mb_strtolower(trim($status));
        if ($s === '' || $s === mb_strtolower('Status não identificado')) {
            return 'outros';
        }
        if (str_contains($s, 'cancel') || str_contains($s, 'refund') || str_contains($s, 'reemb')) {
            return 'outros';
        }

        if (self::isEntregue($s)) {
            return 'entregue';
        }
        if (self::isEnviado($s)) {
            return 'enviado';
        }
        if (self::isAtrasoEnvio($s)) {
            return 'atraso';
        }
        if (str_contains($s, 'aguardando resolução') || str_contains($s, 'aguardando resolucao') || str_contains($s, 'aguard. resolu')) {
            return 'aguardando_resolucao';
        }
        if (str_contains($s, 'conferir') || str_contains($s, 'conferência') || str_contains($s, 'conferencia')) {
            return 'conferir';
        }
        if (str_contains($s, 'etiqueta')) {
            return 'etiqueta_gerada';
        }
        if (
            (str_contains($s, 'pronto') && (str_contains($s, 'envio') || str_contains($s, 'enviar')))
            || str_contains($s, 'ready_to_ship')
            || str_contains($s, 'ready to ship')
        ) {
            return 'pronto_envio';
        }
        if (str_contains($s, 'a combinar') || str_contains($s, 'combinar') || str_contains($s, 'to_be_agreed')) {
            return 'a_combinar';
        }
        if (str_contains($s, 'fatur') || str_contains($s, 'nota fiscal') || str_contains($s, 'invoic')) {
            return 'faturado';
        }
        if (
            str_contains($s, 'abert')
            || str_contains($s, 'pend')
            || str_contains($s, 'aguard')
            || str_contains($s, 'separ')
            || str_contains($s, 'aprov')
            || str_contains($s, 'paid')
            || str_contains($s, 'payment')
            || str_contains($s, 'pagamento')
            || str_contains($s, 'handling')
            || str_contains($s, 'confirm')
        ) {
            return 'aberto';
        }

        return 'outros';
    }

    /**
     * @param list<array<string, mixed>> $orders
     * @return array<string, int>
     */
    public static function countByLane(array $orders): array
    {
        $counts = [];
        foreach (self::laneOrder() as $lane) {
            $counts[$lane] = 0;
        }

        foreach ($orders as $item) {
            $status = (string) ($item['status'] ?? '');
            $lane = self::mapLane($status);
            if (!isset($counts[$lane])) {
                $counts[$lane] = 0;
            }
            $counts[$lane]++;
        }

        $counts[self::LANE_TODOS] = count($orders);

        return $counts;
    }

    private static function isEntregue(string $s): bool
    {
        if (str_contains($s, 'not_delivered') || str_contains($s, 'not delivered')) {
            return false;
        }

        return str_contains($s, 'entreg')
            || str_contains($s, 'conclu')
            || str_contains($s, 'delivered')
            || str_contains($s, 'closed');
    }

    private static function isEnviado(string $s): bool
    {
        return str_contains($s, 'envia')
            || str_contains($s, 'postad')
            || str_contains($s, 'post ')
            || str_contains($s, 'shipped')
            || str_contains($s, 'in_transit')
            || str_contains($s, 'in transit')
            || str_contains($s, 'picked_up')
            || str_contains($s, 'out_for_delivery')
            || str_contains($s, 'fulfilled');
    }

    private static function isAtrasoEnvio(string $s): bool
    {
        if (self::isEntregue($s) || self::isEnviado($s)) {
            return false;
        }

        return str_contains($s, 'atras')
            || str_contains($s, 'delayed')
            || str_contains($s, 'not_delivered')
            || str_contains($s, 'not delivered')
            || str_contains($s, 'undelivered');
    }
}
