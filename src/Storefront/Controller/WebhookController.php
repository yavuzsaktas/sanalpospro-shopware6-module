<?php declare(strict_types=1);

namespace SanalposproPayment\Storefront\Controller;

use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class WebhookController extends StorefrontController
{
    /** @var OrderTransactionStateHandler */
    private $transactionStateHandler;

    public function __construct(OrderTransactionStateHandler $transactionStateHandler)
    {
        $this->transactionStateHandler = $transactionStateHandler;
    }

    // CSRF Protection is disabled because this route is accessed by external PayThor Live-API servers.
    #[Route(path: '/sanalpospro/webhook', name: 'frontend.sanalpospro.webhook', defaults: ['csrf_protected' => false, 'XmlHttpRequest' => true], methods: ['POST'])]
    public function webhook(Request $request, Context $context): JsonResponse
    {
        $content = $request->getContent();
        $data = json_decode($content, true);

        // Validating the payload from PayThor API
        if (isset($data['transaction_id']) && isset($data['status'])) {
            $transactionId = $data['transaction_id'];
            
            try {
                // Change Shopware order transaction status based on API response
                if ($data['status'] === 'success' || $data['status'] === 'paid') {
                    $this->transactionStateHandler->paid($transactionId, $context);
                } elseif ($data['status'] === 'failed') {
                    $this->transactionStateHandler->fail($transactionId, $context);
                }
                
                return new JsonResponse(['success' => true, 'message' => 'Transaction state successfully synchronized with PayThor']);
            } catch (\Exception $e) {
                return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
            }
        }

        return new JsonResponse(['success' => false, 'message' => 'Invalid or missing webhook payload data'], 400);
    }
}
