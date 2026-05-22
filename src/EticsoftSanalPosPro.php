<?php declare(strict_types=1);

namespace SanalposproPayment;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use SanalposproPayment\Service\CustomFieldsInstaller;

class EticsoftSanalPosPro extends Plugin
{
	public function install(InstallContext $installContext): void
	{
		$this->getPaymentMethodInstaller()->install($installContext->getContext());
	}

	public function uninstall(UninstallContext $uninstallContext): void
	{
		parent::uninstall($uninstallContext);

		if ($uninstallContext->keepUserData()) {
			return;
		}

		$this->getPaymentMethodInstaller()->setPaymentMethodIsActive(false, $uninstallContext->getContext());
	}

	public function activate(ActivateContext $activateContext): void
	{
		$this->getPaymentMethodInstaller()->setPaymentMethodIsActive(true, $activateContext->getContext());
		$this->addPaymentMethodToAllSalesChannels($activateContext->getContext());
	}

	private function addPaymentMethodToAllSalesChannels(\Shopware\Core\Framework\Context $context): void
	{
		/** @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository $pmRepo */
		$pmRepo = $this->container->get('payment_method.repository');
		/** @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository $scRepo */
		$scRepo = $this->container->get('sales_channel.repository');

		$criteria = new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria();
		$criteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter(
			'handlerIdentifier',
			\SanalposproPayment\Service\SanalPosProPaymentHandler::class
		));
		$paymentMethodId = $pmRepo->searchIds($criteria, $context)->firstId();

		if (!$paymentMethodId) {
			return;
		}

		$salesChannelIds = $scRepo->searchIds(
			new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria(),
			$context
		)->getIds();

		$updates = [];
		foreach ($salesChannelIds as $scId) {
			$updates[] = [
				'id'             => $scId,
				'paymentMethods' => [['id' => $paymentMethodId]],
			];
		}

		if ($updates !== []) {
			$scRepo->update($updates, $context);
		}
	}

	public function deactivate(DeactivateContext $deactivateContext): void
	{
		$this->getPaymentMethodInstaller()->setPaymentMethodIsActive(false, $deactivateContext->getContext());
	}

	public function update(UpdateContext $updateContext): void
	{
	}

	public function postInstall(InstallContext $installContext): void
	{
	}

	public function postUpdate(UpdateContext $updateContext): void
	{
	}

	private function getCustomFieldsInstaller(): CustomFieldsInstaller
	{
		if ($this->container->has(CustomFieldsInstaller::class)) {
			return $this->container->get(CustomFieldsInstaller::class);
		}

		return new CustomFieldsInstaller(
			$this->container->get('custom_field_set.repository'),
			$this->container->get('custom_field_set_relation.repository')
		);
	}

	private function getPaymentMethodInstaller(): \SanalposproPayment\Util\PaymentMethodInstaller
	{
		if ($this->container->has(\SanalposproPayment\Util\PaymentMethodInstaller::class)) {
			return $this->container->get(\SanalposproPayment\Util\PaymentMethodInstaller::class);
		}

		return new \SanalposproPayment\Util\PaymentMethodInstaller(
			$this->container->get('payment_method.repository'),
			$this->container->get(\Shopware\Core\Framework\Plugin\Util\PluginIdProvider::class)
		);
	}
}