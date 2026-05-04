<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\MessageLogRepository;

class MessageService
{
    public function __construct(
        private TokenService $tokenService,
        private MercadoLivreClient $client,
        private MessageLogRepository $messageLogRepository
    ) {
    }

    public function sendThanksMessage(string $orderId, string $senderId, string $receiverId, string $messageBody): void
    {
        if ($this->messageLogRepository->wasOrderAlreadySent($orderId)) {
            return;
        }

        try {
            $result = $this->sendPostSaleMessageByOrder($orderId, $senderId, $messageBody);
            $status = ($result['status'] >= 200 && $result['status'] < 300) ? 'sent' : 'error';

            $this->messageLogRepository->register(
                $orderId,
                $receiverId,
                $messageBody,
                $status,
                $result['raw'] ?? null
            );
        } catch (\Throwable $exception) {
            $this->messageLogRepository->register(
                $orderId,
                $receiverId,
                $messageBody,
                'error',
                $exception->getMessage()
            );
        }
    }

    public function sendManual(string $senderId, string $orderId, string $messageBody): array
    {
        $orderId = trim($orderId);
        if ($orderId === '' || !ctype_digit($orderId)) {
            throw new \RuntimeException('Informe um ID de pedido válido para envio manual.');
        }

        return $this->sendPostSaleMessageByOrder($orderId, $senderId, $messageBody);
    }

    /**
     * @param list<string> $orderIds
     * @return array{
     *   total: int,
     *   sent: int,
     *   errors: int,
     *   results: list<array{order_id: string, http_status: int, status: string, api_response: string}>
     * }
     */
    public function sendManualBatch(string $senderId, array $orderIds, string $messageBody): array
    {
        $results = [];
        $sent = 0;
        $errors = 0;

        foreach ($orderIds as $orderIdRaw) {
            $orderId = trim((string) $orderIdRaw);
            if ($orderId === '') {
                continue;
            }

            try {
                $result = $this->sendManual($senderId, $orderId, $messageBody);
                $httpStatus = (int) ($result['status'] ?? 0);
                $ok = $httpStatus >= 200 && $httpStatus < 300;
                $apiRaw = (string) ($result['raw'] ?? '');
                $this->messageLogRepository->registerManual(
                    $orderId,
                    $senderId,
                    $messageBody,
                    $ok ? 'sent' : 'error',
                    $apiRaw
                );

                if ($ok) {
                    $sent++;
                } else {
                    $errors++;
                }

                $results[] = [
                    'order_id' => $orderId,
                    'http_status' => $httpStatus,
                    'status' => $ok ? 'sent' : 'error',
                    'api_response' => $apiRaw,
                ];
            } catch (\Throwable $e) {
                $errors++;
                $this->messageLogRepository->registerManual(
                    $orderId,
                    $senderId,
                    $messageBody,
                    'error',
                    $e->getMessage()
                );
                $results[] = [
                    'order_id' => $orderId,
                    'http_status' => 0,
                    'status' => 'error',
                    'api_response' => $e->getMessage(),
                ];
            }
        }

        return [
            'total' => count($results),
            'sent' => $sent,
            'errors' => $errors,
            'results' => $results,
        ];
    }

    private function sendPostSaleMessageByOrder(string $orderId, string $sellerId, string $messageBody): array
    {
        $accessToken = $this->tokenService->getValidAccessToken();
        $orderResult = $this->client->get('/orders/' . rawurlencode($orderId), $accessToken);
        if (($orderResult['status'] ?? 0) < 200 || ($orderResult['status'] ?? 0) >= 300 || !is_array($orderResult['body'] ?? null)) {
            $raw = substr((string) ($orderResult['raw'] ?? ''), 0, 300);
            throw new \RuntimeException(
                'Não foi possível carregar o pedido para envio da mensagem. HTTP: ' . (int) ($orderResult['status'] ?? 0)
                . ($raw !== '' ? ' | Resposta API: ' . $raw : '')
            );
        }

        $orderBody = $orderResult['body'];
        $packId = trim((string) ($orderBody['pack_id'] ?? ''));
        if ($packId === '') {
            $packId = $orderId;
        }
        $buyerId = trim((string) ($orderBody['buyer']['id'] ?? ''));
        if ($buyerId === '' || !ctype_digit($buyerId)) {
            throw new \RuntimeException('Pedido sem comprador válido para envio da mensagem.');
        }

        $payload = [
            'from' => ['user_id' => (int) $sellerId],
            'to' => ['user_id' => (int) $buyerId],
            'text' => $messageBody,
        ];

        $path = '/messages/packs/' . rawurlencode($packId) . '/sellers/' . rawurlencode($sellerId) . '?tag=post_sale';

        return $this->client->post($path, $payload, $accessToken);
    }
}
