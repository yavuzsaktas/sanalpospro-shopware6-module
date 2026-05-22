<?php declare(strict_types=1);

namespace SanalposproPayment\Util;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use SanalposproPayment\Service\SanalPosProPaymentHandler;

class PaymentMethodInstaller
{
    /** @var EntityRepository */
    private $paymentMethodRepository;

    /** @var PluginIdProvider */
    private $pluginIdProvider;

    public function __construct(EntityRepository $paymentMethodRepository, PluginIdProvider $pluginIdProvider)
    {
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->pluginIdProvider = $pluginIdProvider;
    }

    public function install(Context $context): void
    {
        $paymentMethodId = $this->getPaymentMethodId($context);
        if ($paymentMethodId) {
            return; // Already installed
        }

        $pluginId = $this->pluginIdProvider->getPluginIdByBaseClass(\SanalposproPayment\EticsoftSanalPosPro::class, $context);

        $paymentData = [
            'handlerIdentifier' => SanalPosProPaymentHandler::class,
            'name' => 'SanalPos Pro',
            'technicalName' => 'payment_sanalpospro',
            'description' => 'Pay with SanalPos Pro (Credit/Debit Card)',
            'pluginId' => $pluginId,
            'afterOrderEnabled' => true,
            'active' => true,
        ];

        $this->paymentMethodRepository->create([$paymentData], $context);
    }

    public function setPaymentMethodIsActive(bool $active, Context $context): void
    {
        $paymentMethodId = $this->getPaymentMethodId($context);

        if (!$paymentMethodId) {
            return;
        }

        $paymentData = [
            'id' => $paymentMethodId,
            'active' => $active,
        ];

        $this->paymentMethodRepository->update([$paymentData], $context);
    }

    private function getPaymentMethodId(Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', SanalPosProPaymentHandler::class));

        return $this->paymentMethodRepository->searchIds($criteria, $context)->firstId();
    }
}
