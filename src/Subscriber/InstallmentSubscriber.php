<?php declare(strict_types=1);

namespace SanalposproPayment\Subscriber;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Injects installment data into the product detail page so the Twig template
 * can render an installment table — mirrors Magento's Installments block and
 * OpenCart's catalog controller logic.
 */
class InstallmentSubscriber implements EventSubscriberInterface
{
    /** Card families rendered in tab order (matches WC / Magento / OpenCart). */
    private const CARD_FAMILIES = [
        'world', 'axess', 'bonus', 'cardfinans', 'maximum',
        'paraf', 'saglamcard', 'advantage', 'combo', 'miles-smiles',
    ];

    /** @var SystemConfigService */
    private $systemConfigService;

    public function __construct(SystemConfigService $systemConfigService)
    {
        $this->systemConfigService = $systemConfigService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductPageLoadedEvent::class => 'onProductPageLoaded',
        ];
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        // Check if installments tab is enabled. Accept all common truthy
        // shapes the React admin panel may persist ('yes', '1', 'true').
        $showTabs = strtolower(trim((string) ($this->systemConfigService->get('SanalPosPro.config.showInstallmentsTabs') ?? 'no')));
        if (!in_array($showTabs, ['yes', '1', 'true', 'on'], true)) {
            return;
        }

        // Read the installment config (JSON saved by the CDN React admin panel)
        $raw  = (string) ($this->systemConfigService->get('SanalPosPro.config.installments') ?? '');
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data)) {
            return;
        }

        // Determine which card families have at least one active installment
        $activeFamilies = [];
        foreach (self::CARD_FAMILIES as $family) {
            if ($this->familyHasInstallment($data[$family] ?? [])) {
                $activeFamilies[] = $family;
            }
        }
        if (empty($activeFamilies) && $this->familyHasInstallment($data['default'] ?? [])) {
            $activeFamilies[] = 'default';
        }

        if (empty($activeFamilies)) {
            return;
        }

        // Get product price
        $product = $event->getPage()->getProduct();
        $price   = 0.0;

        $calculatedPrice = $product->getCalculatedPrice();
        if ($calculatedPrice !== null) {
            $price = $calculatedPrice->getUnitPrice();
        }

        if ($price <= 0) {
            return;
        }

        // Get theme setting
        $theme = strtolower((string) ($this->systemConfigService->get('SanalPosPro.config.paymentPageTheme') ?? 'modern'));
        if ($theme !== 'classic') {
            $theme = 'modern';
        }

        // Build per-family installment rows
        $familyRows = [];
        foreach ($activeFamilies as $family) {
            $familyRows[$family] = $this->buildFamilyRows($data[$family] ?? [], $price);
        }

        // Get currency symbol from sales channel context
        $currencySymbol = '₺';
        try {
            $currency = $event->getSalesChannelContext()->getCurrency();
            $currencySymbol = $currency->getSymbol();
        } catch (\Throwable $e) {
            // Fallback to TRY symbol
        }

        // Inject data as page extensions
        $page = $event->getPage();
        $page->addExtension('sanalposproInstallments', new \Shopware\Core\Framework\Struct\ArrayStruct([
            'enabled'        => true,
            'theme'          => $theme,
            'activeFamilies' => $activeFamilies,
            'familyRows'     => $familyRows,
            'currencySymbol' => $currencySymbol,
            'productPrice'   => $price,
        ]));
    }

    private function familyHasInstallment(array $rows): bool
    {
        foreach ($rows as $row) {
            if (($row['gateway'] ?? 'off') !== 'off') {
                return true;
            }
        }
        return false;
    }

    /**
     * Build a 12-row installment table for one card family.
     *
     * For months whose gateway == 'off', the row is still included but marked
     * inactive (monthly/total = '-') — matching the classic theme.
     * For the modern theme the template will filter these out.
     *
     * Formula mirrors Magento Block/Product/Installments.php:
     *   - month 1, buyer_fee 0% → total = price
     *   - otherwise → total = price * 100 / (100 - buyer_fee_percent)
     *   - monthly = total / months
     */
    private function buildFamilyRows(array $rows, float $price): array
    {
        // Index installments by month number
        $byMonth = [];
        foreach ($rows as $installment) {
            if (($installment['gateway'] ?? 'off') === 'off') {
                continue;
            }
            $months = (int) ($installment['months'] ?? 0);
            if ($months >= 1 && $months <= 12) {
                $byMonth[$months] = (float) ($installment['buyer_fee_percent'] ?? 0);
            }
        }

        $result = [];
        for ($i = 1; $i <= 12; $i++) {
            if (!array_key_exists($i, $byMonth)) {
                $result[] = [
                    'months'  => $i,
                    'monthly' => '-',
                    'total'   => '-',
                    'active'  => false,
                ];
                continue;
            }

            $buyerFee = $byMonth[$i];
            if ($i === 1 && $buyerFee == 0.0) {
                $total = $price;
            } else {
                $denominator = 100 - $buyerFee;
                $total = $denominator > 0 ? ($price * 100) / $denominator : $price;
            }
            $monthly = $total / $i;

            $result[] = [
                'months'  => $i,
                'monthly' => number_format($monthly, 2, ',', '.'),
                'total'   => number_format($total, 2, ',', '.'),
                'active'  => true,
            ];
        }

        return $result;
    }
}
