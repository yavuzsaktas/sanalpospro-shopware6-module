<?php declare(strict_types=1);

namespace SanalposproPayment\Core\Api;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;

#[Route(defaults: ['_routeScope' => ['api']])]
class WebhookController extends AbstractController
{
    private const SIGNATURE_HEADER = 'X-Paythor-Signature';
    private const CONFIG_WEBHOOK_SECRET = 'SanalPosPro.config.webhookSecret';

    /** @var SystemConfigService */
    private $systemConfigService;

    /** @var EntityRepository */
    private $webhookLogRepository;

    /** @var OrderTransactionStateHandler */
    private $transactionStateHandler;

    /** @var Connection */
    private $connection;

    /** @var LoggerInterface */
    private $logger;

    /**
     * Status normalization map — matches Notify.php exactly.
     * Keys are lowercase status strings; values are canonical categories.
     */
    private const STATUS_MAP = [
        // approved
        'success'     => 'approved',
        'approved'    => 'approved',
        'authorized'  => 'approved',
        'tamamlandi'  => 'approved',
        // failed
        'declined'    => 'failed',
        'cancelled'   => 'failed',
        'failed'      => 'failed',
        'reddedildi'  => 'failed',
        // refunded
        'refunded'    => 'refunded',
        'iade edildi' => 'refunded',
        // pending
        'pending'     => 'pending',
        'processing'  => 'pending',
        'baslatildi'  => 'pending',
    ];

    /**
     * Shopware order_transaction states that are considered finalized.
     * When a transaction is already in one of these states, the webhook
     * acknowledges with 200 but performs no further transitions.
     */
    private const FINALIZED_STATES = [
        'paid',
        'cancelled',
        'refunded',
    ];

    public function __construct(
        SystemConfigService $systemConfigService,
        EntityRepository $webhookLogRepository,
        OrderTransactionStateHandler $transactionStateHandler,
        Connection $connection,
        LoggerInterface $logger
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->webhookLogRepository = $webhookLogRepository;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->connection = $connection;
        $this->logger = $logger;
    }

    #[Route(path: '/api/sanalpospro/webhook', name: 'api.sanalpospro.webhook', methods: ['POST'], defaults: ['auth_required' => false, 'csrf_protected' => false])]
    public function handle(Request $request, Context $context): JsonResponse
    {
        $rawBody   = (string) $request->getContent();
        $signature = (string) $request->headers->get(self::SIGNATURE_HEADER, '');

        // -- Step 1: Signature verification (HMAC-SHA256 + hash_equals) --------
        if (!$this->verifySignature($rawBody, $signature)) {
            $this->logger->warning('SanalPosPro webhook: signature verification FAILED', [
                'remote_ip'     => $request->getClientIp(),
                'has_signature' => $signature !== '',
                'body_length'   => strlen($rawBody),
            ]);

            return new JsonResponse(
                ['success' => false, 'message' => 'Invalid signature.'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        // -- Step 2: Decode payload -------------------------------------------
        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning('SanalPosPro webhook: malformed JSON', [
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(
                ['success' => false, 'message' => 'Malformed payload.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // -- Step 3: Extract & validate required fields -----------------------
        $merchantOrderId = (string) ($payload['merchant_order_id'] ?? '');
        $transactionId   = (string) ($payload['transaction_id'] ?? '');
        $eventStatus     = (string) ($payload['status'] ?? '');
        $message         = (string) ($payload['message'] ?? '');

        if ($merchantOrderId === '' || $eventStatus === '') {
            return new JsonResponse(
                ['success' => false, 'message' => 'Missing required fields.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // -- Step 4: Idempotency guard ----------------------------------------
        $currentStateName = $this->getTransactionStateName($merchantOrderId);

        if ($currentStateName === null) {
            $this->logger->warning('SanalPosPro webhook: order_transaction not found', [
                'merchant_order_id' => $merchantOrderId,
            ]);

            // Log the attempt even if the transaction doesn't exist
            $this->writeLog($merchantOrderId, $transactionId, $eventStatus, $rawBody, $context);

            return new JsonResponse(
                ['success' => true, 'message' => 'Order transaction not found, ignored.'],
                Response::HTTP_OK
            );
        }

        if (in_array($currentStateName, self::FINALIZED_STATES, true)) {
            // Log the duplicate attempt for audit purposes
            $this->writeLog($merchantOrderId, $transactionId, $eventStatus, $rawBody, $context);

            return new JsonResponse(
                ['success' => true, 'message' => 'Already finalized.'],
                Response::HTTP_OK
            );
        }

        // -- Step 5: Status routing -------------------------------------------
        $normalizedStatus = mb_strtolower(trim($eventStatus), 'UTF-8');
        $category         = self::STATUS_MAP[$normalizedStatus] ?? 'unknown';

        try {
            switch ($category) {
                case 'approved':
                    $this->transactionStateHandler->paid($merchantOrderId, $context);
                    break;

                case 'failed':
                    $this->transactionStateHandler->cancel($merchantOrderId, $context);
                    break;

                case 'refunded':
                    $this->transactionStateHandler->refund($merchantOrderId, $context);
                    break;

                case 'pending':
                    // Log only, no state transition — same as Notify.php line 162
                    $this->logger->info('SanalPosPro webhook: pending status received', [
                        'merchant_order_id' => $merchantOrderId,
                        'status'            => $eventStatus,
                    ]);
                    break;

                default:
                    // Unknown status — log a warning, no transition
                    $this->logger->warning('SanalPosPro webhook: unrecognised status', [
                        'merchant_order_id' => $merchantOrderId,
                        'status'            => $eventStatus,
                    ]);
                    break;
            }
        } catch (\Throwable $e) {
            $this->logger->error('SanalPosPro webhook: state transition error', [
                'merchant_order_id' => $merchantOrderId,
                'message'           => $e->getMessage(),
                'trace'             => $e->getTraceAsString(),
            ]);

            // Still log the webhook attempt before returning error
            $this->writeLog($merchantOrderId, $transactionId, $eventStatus, $rawBody, $context);

            return new JsonResponse(
                ['success' => false, 'message' => 'Internal error.'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        // -- Step 6: Always log regardless of outcome -------------------------
        $this->writeLog($merchantOrderId, $transactionId, $category, $rawBody, $context);

        return new JsonResponse(['success' => true, 'message' => 'OK']);
    }

    /**
     * Verify HMAC-SHA256 signature using hash_equals() for constant-time comparison.
     */
    private function verifySignature(string $rawBody, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        $webhookSecret = (string) $this->systemConfigService->get(self::CONFIG_WEBHOOK_SECRET);

        if ($webhookSecret === '') {
            $this->logger->error('SanalPosPro webhook: webhookSecret is not configured');
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $webhookSecret);

        return hash_equals($expected, $signature);
    }

    /**
     * Look up the current state technical name for an order_transaction by its ID.
     * Uses raw DBAL to avoid loading full entity graphs.
     */
    private function getTransactionStateName(string $orderTransactionId): ?string
    {
        try {
            $result = $this->connection->fetchOne(
                'SELECT sms.technical_name '
                . 'FROM order_transaction ot '
                . 'INNER JOIN state_machine_state sms ON ot.state_id = sms.id '
                . 'WHERE ot.id = :id '
                . 'LIMIT 1',
                ['id' => Uuid::fromHexToBytes($orderTransactionId)]
            );

            return $result !== false ? (string) $result : null;
        } catch (\Throwable $e) {
            $this->logger->warning('SanalPosPro webhook: failed to look up transaction state', [
                'order_transaction_id' => $orderTransactionId,
                'error'               => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Write an audit record into sanalpospro_webhook_log.
     */
    private function writeLog(
        string $orderTxId,
        string $paythorTxId,
        string $status,
        string $rawPayload,
        Context $context
    ): void {
        try {
            $this->webhookLogRepository->create([
                [
                    'id'          => Uuid::randomHex(),
                    'orderTxId'   => $orderTxId,
                    'paythorTxId' => $paythorTxId !== '' ? $paythorTxId : null,
                    'action'      => 'webhook',
                    'status'      => $status,
                    'rawPayload'  => $rawPayload,
                    'createdAt'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
                ],
            ], $context);
        } catch (\Throwable $e) {
            // Never let a log write failure break the webhook response
            $this->logger->error('SanalPosPro webhook: failed to write log', [
                'order_tx_id' => $orderTxId,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
