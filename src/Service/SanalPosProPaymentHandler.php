<?php declare(strict_types=1);

namespace SanalposproPayment\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

class SanalPosProPaymentHandler extends AbstractPaymentHandler
{
    /** @var OrderTransactionStateHandler */
    private $transactionStateHandler;

    /** @var RouterInterface */
    private $router;

    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        RouterInterface $router
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->router = $router;
    }

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        return false;
    }

    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct
    ): ?RedirectResponse {
        $transactionId = $transaction->getOrderTransactionId();
        $returnUrl     = $transaction->getReturnUrl();

        $redirectUrl = $this->router->generate('frontend.sanalpospro.iframe', [
            'transactionId' => $transactionId,
            'returnUrl'     => $returnUrl,
        ]);

        return new RedirectResponse($redirectUrl);
    }

    public function finalize(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context
    ): void {
        // p_id is appended by our callback controller only when PayThor confirmed
        // the payment (approved or status-unknown). Absence means the customer
        // went back without completing payment → fail the transaction so Shopware
        // shows the retry UI in My Account > Orders.
        $pId = trim((string) $request->query->get('p_id', ''));

        if ($pId !== '') {
            $this->transactionStateHandler->paid($transaction->getOrderTransactionId(), $context);
        } else {
            $this->transactionStateHandler->fail($transaction->getOrderTransactionId(), $context);
        }
    }
}
