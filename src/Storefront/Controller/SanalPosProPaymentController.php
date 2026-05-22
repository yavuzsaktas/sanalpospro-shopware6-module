<?php declare(strict_types=1);

namespace SanalposproPayment\Storefront\Controller;

use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Context;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class SanalPosProPaymentController extends StorefrontController
{
    /** @var EntityRepository */
    private $orderTransactionRepository;

    public function __construct(EntityRepository $orderTransactionRepository)
    {
        $this->orderTransactionRepository = $orderTransactionRepository;
    }

    #[Route(path: '/sanalpospro/payment/{transactionId}', name: 'frontend.sanalpospro.payment.page', defaults: ['XmlHttpRequest' => true], methods: ['GET', 'POST'])]
    public function paymentPage(string $transactionId, Request $request, Context $context): Response
    {
        $returnUrl = $request->query->get('returnUrl');

        return $this->renderStorefront('@EticsoftSanalPosPro/storefront/page/sanalpospro/payment.html.twig', [
            'transactionId' => $transactionId,
            'returnUrl' => $returnUrl
        ]);
    }
}
