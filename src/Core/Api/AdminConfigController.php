<?php declare(strict_types=1);

namespace SanalposproPayment\Core\Api;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Provides the /api/sanalpospro/admin-config endpoint consumed by
 * the administration CDN React app (sanalpospro-connect-index component).
 *
 * Returns xfvv token and target_url so the PayThor UI can communicate
 * with the storefront IAPI bridge.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class AdminConfigController extends AbstractController
{
    private const SHOPWARE_APP_ID_DEFAULT = 106;
    private const MAX_SANE_APP_ID     = 1000;
    private const CONFIG_APP_ID     = 'SanalPosPro.config.appId';
    private const CONFIG_PUBLIC_KEY = 'SanalPosPro.config.publicApiKey';
    private const CONFIG_SECRET_KEY = 'SanalPosPro.config.secretApiKey';

    /** @var SystemConfigService */
    private $systemConfigService;

    public function __construct(SystemConfigService $systemConfigService)
    {
        $this->systemConfigService = $systemConfigService;
    }

    #[Route(path: '/api/sanalpospro/admin-config', name: 'api.sanalpospro.admin-config', methods: ['GET'], defaults: ['auth_required' => true])]
    public function adminConfig(): JsonResponse
    {
        $appId = (int) ($this->systemConfigService->get(self::CONFIG_APP_ID) ?? 0);
        if ($appId <= 0 || $appId > self::MAX_SANE_APP_ID) {
            $appId = self::SHOPWARE_APP_ID_DEFAULT;
        }

        // xfvv = hash derived from the stored API keys.
        // The CDN React app sends this value back to the storefront IAPI
        // bridge so we can verify the request came from an authenticated
        // admin session that has access to the real keys.
        $pub = (string) ($this->systemConfigService->get(self::CONFIG_PUBLIC_KEY) ?? '');
        $sec = (string) ($this->systemConfigService->get(self::CONFIG_SECRET_KEY) ?? '');

        if ($pub !== '' && $sec !== '') {
            $xfvv = hash('sha256', $pub . $sec);
        } else {
            // Fallback — keys not yet configured; CDN app will still load
            // but certain API calls may fail until login completes.
            $xfvv = 'shopware';
        }

        // Hydrate the saved module settings so the CDN React panel can
        // pre-select the correct option in its "Eklenti Ayarları" dropdowns
        // (Sipariş Durumu, Döviz Dönüşümü, Taksit Sekmelerini Göster,
        // Taksit Sekmesi Görünümü). Without this every page load would reset
        // the dropdowns to the hard-coded defaults (no / modern), which is
        // why the installments tab never appeared on the storefront after
        // the merchant saved their preference.
        $settings = [
            'order_status'         => (string) ($this->systemConfigService->get('SanalPosPro.config.orderStatus') ?? 'process'),
            'currency_convert'     => (string) ($this->systemConfigService->get('SanalPosPro.config.currencyConvert') ?? 'no'),
            'showInstallmentsTabs' => (string) ($this->systemConfigService->get('SanalPosPro.config.showInstallmentsTabs') ?? 'no'),
            'paymentPageTheme'     => (string) ($this->systemConfigService->get('SanalPosPro.config.paymentPageTheme') ?? 'modern'),
        ];

        // Normalize empty values to sane fallbacks so the React panel
        // always receives a valid option key.
        if ($settings['order_status'] === '')         { $settings['order_status']         = 'process'; }
        if ($settings['currency_convert'] === '')     { $settings['currency_convert']     = 'no'; }
        if ($settings['showInstallmentsTabs'] === '') { $settings['showInstallmentsTabs'] = 'no'; }
        if ($settings['paymentPageTheme'] === '')     { $settings['paymentPageTheme']     = 'modern'; }

        return new JsonResponse([
            'xfvv'            => $xfvv,
            'app_id'          => $appId,
            'target_url'      => '/sanalpospro/iapi/index',
            'module_settings' => $settings,
        ]);
    }
}
