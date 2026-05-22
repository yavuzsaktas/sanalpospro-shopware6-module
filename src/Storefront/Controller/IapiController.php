<?php declare(strict_types=1);

namespace SanalposproPayment\Storefront\Controller;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class IapiController extends StorefrontController
{
    private const PAYTHOR_API_BASE        = 'https://live-api.sanalpospro.com';
    private const SHOPWARE_APP_ID_DEFAULT = 106;
    private const MAX_SANE_APP_ID         = 1000;
    private const PROGRAM_ID             = 1;
    private const CONFIG_APP_ID          = 'SanalPosPro.config.appId';
    private const CONFIG_ACCESS_TOKEN    = 'SanalPosPro.config.accessToken';
    private const CONFIG_PUBLIC_KEY      = 'SanalPosPro.config.publicApiKey';
    private const CONFIG_SECRET_KEY      = 'SanalPosPro.config.secretApiKey';

    /** @var SystemConfigService */
    private $systemConfigService;

    /** @var HttpClientInterface */
    private $httpClient;

    /** @var LoggerInterface */
    private $logger;

    /** @var EntityRepository */
    private $orderTransactionRepository;

    /** @var Context|null */
    private $requestContext = null;

    /** @var string */
    private $requestIp = '127.0.0.1';

    /** @var string */
    private $requestHost = '';

    public function __construct(
        SystemConfigService $systemConfigService,
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
        EntityRepository $orderTransactionRepository
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        $this->orderTransactionRepository = $orderTransactionRepository;
    }

    #[Route(path: '/sanalpospro/iapi/index', name: 'frontend.sanalpospro.iapi', defaults: ['csrf_protected' => false, 'XmlHttpRequest' => true], methods: ['POST', 'OPTIONS'])]
    public function index(Request $request, Context $context): JsonResponse
    {
        $corsHeaders = [
            'Access-Control-Allow-Origin'  => '*',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, etc-program-id, etc-app-id',
        ];

        if ($request->getMethod() === 'OPTIONS') {
            $response = new JsonResponse(null, 204, $corsHeaders);
            $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
            return $response;
        }

        $this->requestContext = $context;
        $this->requestIp     = $request->getClientIp() ?? '127.0.0.1';
        $this->requestHost   = strtolower((string) $request->getHost());

        $action     = (string) $request->request->get('iapi_action', '');
        $rawParams  = (string) $request->request->get('iapi_params', '{}');
        $iapiParams = $this->decodeIapiParams($rawParams);

        if ($action === '') {
            return new JsonResponse($this->error('Action not specified.'), 200, $corsHeaders);
        }

        $method = 'action' . ucfirst($action);
        if (!method_exists($this, $method)) {
            return new JsonResponse($this->error('Unknown action: ' . $action), 200, $corsHeaders);
        }

        return new JsonResponse($this->$method($iapiParams), 200, $corsHeaders);
    }

    #[Route(path: '/sanalpospro/iapi/config', name: 'frontend.sanalpospro.iapi.config', defaults: ['csrf_protected' => false], methods: ['GET'])]
    public function config(): JsonResponse
    {
        return new JsonResponse(
            ['app_id' => $this->savedAppId()],
            200,
            ['Access-Control-Allow-Origin' => '*']
        );
    }

    // ── Actions (mirror Magento Handler.php) ──────────────────────────────────

    private function actionCheckApiKeys(array $params): array
    {
        $token = $this->readAccessTokenFromParams($params);
        if ($token === '') {
            return array_merge($this->error('No access token provided.'), ['data' => []]);
        }

        $previousToken   = $this->getSavedAccessToken();
        $isAccountSwitch = $previousToken !== '' && $previousToken !== $token;

        if ($isAccountSwitch) {
            // Guard against stale app ids (for example installed app row ids)
            // left from another account before re-login.
            $this->systemConfigService->set(self::CONFIG_APP_ID, self::SHOPWARE_APP_ID_DEFAULT);
        }

        // Keep latest access token so we can self-heal later hash/key errors.
        $this->systemConfigService->set(self::CONFIG_ACCESS_TOKEN, $token);

        $pub = trim((string) ($this->systemConfigService->get(self::CONFIG_PUBLIC_KEY) ?? ''));
        $sec = trim((string) ($this->systemConfigService->get(self::CONFIG_SECRET_KEY) ?? ''));

        // During account switch, keep Woo flow: frontend should continue with
        // all/my/getApiKeys/saveApiKeys instead of backend auto-sync here.
        // For normal flow, still auto-fetch when keys are missing.
        if ($pub === '' || $sec === '') {
            if ($isAccountSwitch) {
                return $this->error('Merchant ID mismatch!');
            }

            $fetchResult = $this->fetchAndSaveApiKeys($token);
            if ($fetchResult['status'] !== 'success') {
                return $fetchResult;
            }
            $pub = trim((string) ($this->systemConfigService->get(self::CONFIG_PUBLIC_KEY) ?? ''));
            $sec = trim((string) ($this->systemConfigService->get(self::CONFIG_SECRET_KEY) ?? ''));
        }

        if ($pub === '' || $sec === '') {
            return $this->error('API keys could not be retrieved automatically.');
        }

        $data = $this->callCheckAccessToken($token, $pub, $sec);

        // On account switch we intentionally do not auto-heal mismatch/hash.
        // Frontend expects an error first, then performs all/my/getApiKeys/save.
        if (($data['status'] ?? '') !== 'success' && $isAccountSwitch) {
            if (
                $this->isTransportErrorResponse($data)
                || $this->isHashErrorResponse($data)
                || $this->isMerchantMismatchResponse($data)
            ) {
                return $this->error('Merchant ID mismatch!');
            }

            return $data;
        }

        // If keys are stale (mismatch), pair is broken (hash error), or the signed
        // check fails at transport level (for example transient DNS failure with old
        // key state), refresh from access token and retry once.
        if (
            ($data['status'] ?? '') !== 'success'
            && (
                $this->isMerchantMismatchResponse($data)
                || $this->isHashErrorResponse($data)
                || $this->isTransportErrorResponse($data)
            )
        ) {
            $this->logger->info('SanalPosPro: checkApiKeys re-sync — re-fetching keys', [
                'message' => (string) ($data['message'] ?? ''),
            ]);

            $fetchResult = $this->fetchAndSaveApiKeys($token);
            if ($fetchResult['status'] !== 'success') {
                return $fetchResult;
            }

            $pub  = trim((string) ($this->systemConfigService->get(self::CONFIG_PUBLIC_KEY) ?? ''));
            $sec  = trim((string) ($this->systemConfigService->get(self::CONFIG_SECRET_KEY) ?? ''));
            $data = $this->callCheckAccessToken($token, $pub, $sec);
        }

        $this->logger->info('SanalPosPro: checkApiKeys', ['status' => $data['status'] ?? 'unknown']);

        if (($data['status'] ?? '') === 'success') {
            $this->discoverAndSaveShopwareAppId($token, $pub, $sec);
        }

        return $data;
    }

    private function callCheckAccessToken(string $token, string $pub, string $sec): array
    {
        return $this->callSignedEndpoint('POST', '/check/accesstoken', $pub, $sec, ['accesstoken' => $token], 10, 'checkApiKeys');
    }

    private function actionFetchApiKeys(array $params): array
    {
        $token = $this->readAccessTokenFromParams($params);
        if ($token === '') {
            return array_merge($this->error('No access token provided.'), ['data' => []]);
        }

        $this->systemConfigService->set(self::CONFIG_ACCESS_TOKEN, $token);

        return $this->fetchAndSaveApiKeys($token);
    }

    // Woo-compatible aliases used by CDN dashboard flow after checkApiKeys mismatch.
    private function actionAll(array $params): array
    {
        $token = $this->readAccessTokenFromParams($params);
        if ($token === '') {
            $token = $this->getSavedAccessToken();
        }

        $appIdHint = $this->readAppIdFromParams($params);

        if ($token !== '') {
            $this->systemConfigService->set(self::CONFIG_ACCESS_TOKEN, $token);
            $headers = $this->buildBearerHeaders($token, $appIdHint > 0 ? $appIdHint : null);

            try {
                $response = $this->httpClient->request('GET', self::PAYTHOR_API_BASE . '/app/list/all', [
                    'headers' => $headers,
                    'timeout' => 10,
                ]);

                $rawBody  = $response->getContent(false);
                $httpCode = $response->getStatusCode();
                $this->logger->info('SanalPosPro: listAllApps direct', [
                    'http' => $httpCode,
                    'body' => substr($rawBody, 0, 500),
                ]);

                $data = json_decode($rawBody, true);
                if (is_array($data)) {
                    if (!is_array($data['data'] ?? null)) {
                        $data['data'] = [];
                    }

                    $data['data'] = $this->normalizeCatalogApps($data['data']);

                    if (($data['status'] ?? '') === 'success') {
                        return $data;
                    }

                    $message = strtolower((string) ($data['message'] ?? ''));
                    if ($message !== '' && !$this->contains($message, 'not authorized')) {
                        return $data;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('SanalPosPro: listAllApps direct failed', ['error' => $e->getMessage()]);
            }
        }

        $pub = trim((string) ($this->systemConfigService->get(self::CONFIG_PUBLIC_KEY) ?? ''));
        $sec = trim((string) ($this->systemConfigService->get(self::CONFIG_SECRET_KEY) ?? ''));

        if ($pub === '' || $sec === '') {
            if (!$this->refreshApiKeysFromSavedAccessToken()) {
                return array_merge($this->error('API keys not configured.'), ['data' => []]);
            }

            $pub = trim((string) ($this->systemConfigService->get(self::CONFIG_PUBLIC_KEY) ?? ''));
            $sec = trim((string) ($this->systemConfigService->get(self::CONFIG_SECRET_KEY) ?? ''));
            if ($pub === '' || $sec === '') {
                return array_merge($this->error('API keys not configured.'), ['data' => []]);
            }
        }

        $data = $this->callSignedEndpoint('GET', '/app/list/all', $pub, $sec, [], 10, 'listAllApps');

        if (($data['status'] ?? '') === 'success') {
            return $data;
        }

        if ($this->isHashErrorResponse($data) && $this->refreshApiKeysFromSavedAccessToken()) {
            $pub = trim((string) ($this->systemConfigService->get(self::CONFIG_PUBLIC_KEY) ?? ''));
            $sec = trim((string) ($this->systemConfigService->get(self::CONFIG_SECRET_KEY) ?? ''));
            if ($pub !== '' && $sec !== '') {
                $data = $this->callSignedEndpoint('GET', '/app/list/all', $pub, $sec, [], 10, 'listAllAppsRetry');
            }
        }

        if (!is_array($data['data'] ?? null)) {
            $data['data'] = [];
        }

        $data['data'] = $this->normalizeCatalogApps($data['data']);

        return $data;
    }

    private function actionMy(array $params): array
    {
        $token = $this->readAccessTokenFromParams($params);
        if ($token === '') {
            $token = $this->getSavedAccessToken();
        }

        if ($token === '') {
            return $this->error('No access token provided.');
        }

        $this->systemConfigService->set(self::CONFIG_ACCESS_TOKEN, $token);

        $appIdHint = $this->readAppIdFromParams($params);
        $headers   = $this->buildBearerHeaders($token, $appIdHint > 0 ? $appIdHint : null);

        try {
            $response = $this->httpClient->request('GET', self::PAYTHOR_API_BASE . '/app/list/my', [
                'headers' => $headers,
                'timeout' => 10,
            ]);

            $rawBody  = $response->getContent(false);
            $httpCode = $response->getStatusCode();
            $this->logger->info('SanalPosPro: listMyApps direct', [
                'http' => $httpCode,
                'body' => substr($rawBody, 0, 500),
            ]);

            $data = json_decode($rawBody, true);
            if (!is_array($data)) {
                return array_merge(
                    $this->error('listMyApps returned non-JSON (HTTP ' . $httpCode . '): ' . substr($rawBody, 0, 300)),
                    ['data' => []]
                );
            }

            $rows = $data['data'] ?? null;
            if (($data['status'] ?? '') === 'success' && is_array($rows) && count($rows) === 0) {
                $this->logger->info('SanalPosPro: listMyApps returned empty list, forcing install retry');

                $this->installShopwareApp($headers);

                $retryResponse = $this->httpClient->request('GET', self::PAYTHOR_API_BASE . '/app/list/my', [
                    'headers' => $headers,
                    'timeout' => 10,
                ]);

                $retryBody = $retryResponse->getContent(false);
                $retryData = json_decode($retryBody, true);
                if (is_array($retryData)) {
                    if (!is_array($retryData['data'] ?? null)) {
                        $retryData['data'] = [];
                    }

                    return $retryData;
                }
            }

            if (!is_array($data['data'] ?? null)) {
                $data['data'] = [];
            }

            return $data;
        } catch (\Throwable $e) {
            $this->logger->warning('SanalPosPro: listMyApps direct failed', ['error' => $e->getMessage()]);
            return array_merge($this->error('listMyApps request failed: ' . $e->getMessage()), ['data' => []]);
        }
    }

    private function actionGetApiKeys(array $params): array
    {
        $token = $this->readAccessTokenFromParams($params);
        if ($token === '') {
            $token = $this->getSavedAccessToken();
        }

        if ($token === '') {
            return $this->error('No access token provided.');
        }

        $this->systemConfigService->set(self::CONFIG_ACCESS_TOKEN, $token);

        $appIdHint = $this->readAppIdFromParams($params);
        $headers   = $this->buildBearerHeaders($token, $appIdHint > 0 ? $appIdHint : null);

        $installedId = (int) (
            $params['iapi_installedId']
            ?? $params['installed_id']
            ?? $params['iapi_id']
            ?? $params['id']
            ?? 0
        );

        if ($installedId <= 0 && $appIdHint > 0) {
            [$myApp, $allApps] = $this->findMyApp($headers);

            if ($myApp !== null && (int) ($myApp['app_id'] ?? 0) === $appIdHint) {
                $installedId = (int) ($myApp['id'] ?? 0);
            }

            if ($installedId <= 0) {
                foreach ($allApps as $app) {
                    if (!is_array($app)) {
                        continue;
                    }

                    if ((int) ($app['app_id'] ?? 0) === $appIdHint) {
                        $installedId = (int) ($app['id'] ?? 0);
                        break;
                    }
                }
            }
        }

        if ($installedId <= 0) {
            [$myApp] = $this->findMyApp($headers);
            $installedId = (int) ($myApp['id'] ?? 0);
        }

        if ($installedId <= 0) {
            try {
                $this->logger->info('SanalPosPro: getApiKeys could not resolve installed app id, forcing install retry');
                $this->installShopwareApp($headers);

                [$myApp] = $this->findMyApp($headers);
                $installedId = (int) ($myApp['id'] ?? 0);
            } catch (\Throwable $e) {
                $this->logger->warning('SanalPosPro: forced install before getApiKeys failed', ['error' => $e->getMessage()]);
            }
        }

        if ($installedId <= 0) {
            return $this->error('No installed app id resolved for getApiKeys.');
        }

        $keysResult = $this->fetchApiKeysByInstalledId($installedId, $headers);
        if (($keysResult['status'] ?? '') !== 'success') {
            return $keysResult;
        }

        return $this->success('API keys fetched.', [
            'installed_id' => $installedId,
            'public_key'   => (string) ($keysResult['public_key'] ?? ''),
            'secret_key'   => (string) ($keysResult['secret_key'] ?? ''),
        ]);
    }

    private function actionSaveApiKeys(array $params): array
    {
        $pub = trim((string) ($params['iapi_publicKey'] ?? ''));
        $sec = trim((string) ($params['iapi_secretKey'] ?? ''));

        if ($pub === '' || $sec === '') {
            return $this->error('Missing API keys.');
        }

        $this->systemConfigService->set(self::CONFIG_PUBLIC_KEY, $pub);
        $this->systemConfigService->set(self::CONFIG_SECRET_KEY, $sec);

        return $this->success('API keys saved.');
    }

    private function actionSetInstallmentOptions(array $params): array
    {
        $options = $params['iapi_installmentOptions'] ?? null;
        if (empty($options)) {
            return $this->error('Invalid installment options.');
        }

        $this->systemConfigService->set('SanalPosPro.config.installments', json_encode($options));

        return $this->success('Installment options updated.');
    }

    private function actionSetModuleSettings(array $params): array
    {
        $settings = $params['iapi_moduleSettings'] ?? [];
        if (empty($settings) || !is_array($settings)) {
            return $this->error('No settings provided.');
        }

        $allowedMap = [
            'order_status'        => 'orderStatus',
            'currency_convert'    => 'currencyConvert',
            'showinstallmentstabs' => 'showInstallmentsTabs',
            'paymentpagetheme'    => 'paymentPageTheme',
        ];

        $updated = [];
        foreach ($settings as $key => $value) {
            $normalized = strtolower((string) $key);
            if (isset($allowedMap[$normalized])) {
                $this->systemConfigService->set('SanalPosPro.config.' . $allowedMap[$normalized], (string) $value);
                $updated[$key] = $value;
            }
        }

        return $this->success('Module settings updated.', ['updated_settings' => $updated]);
    }

    private function actionGetMerchantInfo(array $params): array
    {
        $pub = trim((string) ($this->systemConfigService->get(self::CONFIG_PUBLIC_KEY) ?? ''));
        $sec = trim((string) ($this->systemConfigService->get(self::CONFIG_SECRET_KEY) ?? ''));

        if ($pub === '' || $sec === '') {
            if (!$this->refreshApiKeysFromSavedAccessToken()) {
                return $this->error('API keys not configured.');
            }

            $pub = trim((string) ($this->systemConfigService->get(self::CONFIG_PUBLIC_KEY) ?? ''));
            $sec = trim((string) ($this->systemConfigService->get(self::CONFIG_SECRET_KEY) ?? ''));
            if ($pub === '' || $sec === '') {
                return $this->error('API keys not configured.');
            }
        }

        $data = $this->callMerchantInfo($pub, $sec);
        if (($data['status'] ?? '') === 'success') {
            return $data;
        }

        // Self-heal on hash error by refreshing keys from the last access token.
        if ($this->isHashErrorResponse($data) && $this->refreshApiKeysFromSavedAccessToken()) {
            $pub = trim((string) ($this->systemConfigService->get(self::CONFIG_PUBLIC_KEY) ?? ''));
            $sec = trim((string) ($this->systemConfigService->get(self::CONFIG_SECRET_KEY) ?? ''));
            if ($pub !== '' && $sec !== '') {
                return $this->callMerchantInfo($pub, $sec);
            }
        }

        return $data;
    }

    // ── Storefront payment actions ────────────────────────────────────────────

    /**
     * PayThor handles gateway selection on its own payment page.
     * Return empty list → JS in iframe.html.twig skips gateway radio-button UI
     * and calls createPayment directly.
     */
    private function actionGetPaymentGateways(array $params): array
    {
        return $this->success('Gateway selection handled by PayThor payment page.', []);
    }

    /**
     * Creates a PayThor payment session and returns the iframe/payment-page URL.
     *
     * Params from JS:
     *   iapi_transactionId – Shopware order_transaction UUID
     *   iapi_storeUrl      – window.location.origin
     *   iapi_returnUrl     – Shopware finalize URL
     */
    private function actionCreatePayment(array $params): array
    {
        $transactionId = (string) ($params['iapi_transactionId'] ?? '');
        $storeUrl      = rtrim((string) ($params['iapi_storeUrl'] ?? ''), '/');
        $ctx           = $this->requestContext;

        if ($transactionId === '' || $storeUrl === '') {
            return $this->error('transactionId and storeUrl are required.');
        }

        if ($ctx === null) {
            return $this->error('Request context is unavailable.');
        }

        $pub = (string) ($this->systemConfigService->get(self::CONFIG_PUBLIC_KEY) ?? '');
        $sec = (string) ($this->systemConfigService->get(self::CONFIG_SECRET_KEY) ?? '');

        if ($pub === '' || $sec === '') {
            return $this->error('API keys not configured.');
        }

        // ── Step 1: load Shopware order ──────────────────────────────────────
        $amount      = 1.0;
        $currency    = 'TRY';
        $firstName   = 'Customer';
        $lastName    = 'Customer';
        $email       = 'customer@example.com';
        $phone       = '0';
        $cartItems   = [];
        $addrData    = ['line_1' => '-', 'city' => 'Istanbul', 'state' => 'Istanbul', 'postal_code' => '34000', 'country' => 'TR'];
        $orderNumber = substr($transactionId, 0, 20);

        try {
            $criteria = new Criteria([$transactionId]);
            $criteria->addAssociation('order.currency');
            $criteria->addAssociation('order.orderCustomer');
            $criteria->addAssociation('order.lineItems');
            $criteria->addAssociation('order.addresses.country');
            $criteria->addAssociation('order.addresses.countryState');

            $txEntity = $this->orderTransactionRepository->search($criteria, $ctx)->first();

            if ($txEntity !== null) {
                $order    = $txEntity->getOrder();
                $amount   = (float) $order->getPrice()->getTotalPrice();
                $currency = $order->getCurrency() ? $order->getCurrency()->getIsoCode() : 'TRY';

                $customer    = $order->getOrderCustomer();
                $firstName   = $customer ? ($customer->getFirstName() ?: 'Customer') : 'Customer';
                $lastName    = $customer ? ($customer->getLastName()  ?: 'Customer') : 'Customer';
                $email       = $customer ? ($customer->getEmail()     ?: 'customer@example.com') : 'customer@example.com';
                $orderNumber = $order->getOrderNumber() ?: substr($transactionId, 0, 20);

                foreach ($order->getLineItems() ?? [] as $item) {
                    $cartItems[] = [
                        'id'       => $item->getId(),
                        'name'     => $item->getLabel(),
                        'type'     => 'product',
                        'price'    => (string) round((float) $item->getUnitPrice(), 2),
                        'quantity' => (int) $item->getQuantity(),
                    ];
                }

                $billingId = $order->getBillingAddressId();
                foreach ($order->getAddresses() ?? [] as $addr) {
                    if ($addr->getId() !== $billingId) continue;

                    $stateVal = '';
                    if ($addr->getCountryState()) {
                        $stateVal = $addr->getCountryState()->getName() ?? '';
                    }
                    if ($stateVal === '') {
                        $stateVal = $addr->getCity() ?? 'N/A';
                    }

                    $addrData = [
                        'line_1'      => ($addr->getStreet() ?: '-'),
                        'city'        => ($addr->getCity() ?: 'Istanbul'),
                        'state'       => $stateVal,
                        'postal_code' => ($addr->getZipcode() ?: '00000'),
                        'country'     => $addr->getCountry() ? ($addr->getCountry()->getIso() ?? 'TR') : 'TR',
                    ];
                    $phone = $addr->getPhoneNumber() ?: '0';
                    break;
                }
            } else {
                $this->logger->warning('SanalPosPro: createPayment — transaction not found', ['id' => $transactionId]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('SanalPosPro: createPayment — order load failed', ['error' => $e->getMessage()]);
        }

        if (empty($cartItems)) {
            $cartItems = [['id' => '1', 'name' => 'Order', 'type' => 'product', 'price' => (string) round($amount, 2), 'quantity' => 1]];
        }

        // ── Step 2: POST /payment/create ─────────────────────────────────────
        $shopwareReturnUrl = (string) ($params['iapi_returnUrl'] ?? '');
        $callbackUrl = $storeUrl . '/sanalpospro/callback'
            . '?txn=' . rawurlencode($transactionId)
            . '&ret=' . rawurlencode($shopwareReturnUrl);

        $personData = ['firstName' => $firstName, 'lastName' => $lastName, 'phone' => $phone, 'email' => $email, 'address' => $addrData];

        $payload = [
            'payment' => [
                'amount'             => round($amount, 2) ?: 1.0,
                'currency'           => $currency,
                'buyerFee'           => 0,
                'method'             => 'creditcard',
                'merchant_reference' => $orderNumber,
                'return_url'         => $callbackUrl,
            ],
            'payer' => [
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'email'      => $email,
                'phone'      => $phone,
                'ip'         => $this->requestIp,
                'address'    => $addrData,
            ],
            'order' => [
                'cart'     => $cartItems,
                'shipping' => $personData,
                'invoice'  => [
                    'id'        => $orderNumber,
                    'firstName' => $firstName,
                    'lastName'  => $lastName,
                    'price'     => number_format(round($amount, 2), 2, '.', ''),
                    'quantity'  => 1,
                ],
            ],
        ];

        try {
            [$hashTime, $hashRand, $hash] = $this->generateHash($pub, $sec);

            $createResp = $this->httpClient->request('POST', self::PAYTHOR_API_BASE . '/payment/create', [
                'headers' => [
                    'Authorization'  => 'ApiKeys ' . $pub . ':' . $hash,
                    'X-Timestamp'    => $hashTime,
                    'X-Nonce'        => $hashRand,
                    'ETC-PROGRAM-ID' => (string) self::PROGRAM_ID,
                    'ETC-APP-ID'     => (string) $this->savedAppId(),
                    'Content-Type'   => 'application/json',
                ],
                'json'    => $payload,
                'timeout' => 15,
            ]);

            $rawBody  = $createResp->getContent(false);
            $httpCode = $createResp->getStatusCode();
            $this->logger->info('SanalPosPro: createPayment', ['http' => $httpCode, 'body' => substr($rawBody, 0, 600)]);

            $createData = json_decode($rawBody, true);
            if (!is_array($createData)) {
                return $this->error('payment/create returned non-JSON (HTTP ' . $httpCode . '): ' . substr($rawBody, 0, 200));
            }
            if (($createData['status'] ?? '') !== 'success') {
                $details = implode('; ', $createData['details'] ?? []);
                return $this->error('payment/create failed: ' . ($createData['message'] ?? 'unknown') . ($details ? ' — ' . $details : ''));
            }

            // ── Step 3: GET /payment/getbytoken/{payment_token} ───────────────
            $paymentToken = (string) ($createData['data']['payment_token'] ?? '');
            if ($paymentToken === '') {
                return $this->error('payment/create response missing payment_token.');
            }

            $iframeUrl = '';
            try {
                [$hashTime2, $hashRand2, $hash2] = $this->generateHash($pub, $sec);

                $getByTokenResp = $this->httpClient->request(
                    'GET',
                    self::PAYTHOR_API_BASE . '/payment/getbytoken/' . rawurlencode($paymentToken),
                    [
                        'headers' => [
                            'Authorization'  => 'ApiKeys ' . $pub . ':' . $hash2,
                            'X-Timestamp'    => $hashTime2,
                            'X-Nonce'        => $hashRand2,
                            'ETC-PROGRAM-ID' => (string) self::PROGRAM_ID,
                            'ETC-APP-ID'     => (string) $this->savedAppId(),
                        ],
                        'timeout' => 10,
                    ]
                );

                $rawBody2  = $getByTokenResp->getContent(false);
                $httpCode2 = $getByTokenResp->getStatusCode();
                $tokenData = json_decode($rawBody2, true);
                $this->logger->info('SanalPosPro: getByToken', ['http' => $httpCode2, 'body' => substr($rawBody2, 0, 500)]);

                if (is_array($tokenData) && ($tokenData['status'] ?? '') === 'success') {
                    $d = $tokenData['data'] ?? $tokenData;
                    $iframeUrl = (string) ($d['payment_link'] ?? $d['iframe_url'] ?? $d['url'] ?? $d['embed_url'] ?? $d['iframe'] ?? '');
                }
            } catch (\Throwable $e) {
                $this->logger->warning('SanalPosPro: getByToken failed, falling back to payment_link', ['error' => $e->getMessage()]);
            }

            if ($iframeUrl === '') {
                $iframeUrl = (string) ($createData['data']['payment_link'] ?? '');
            }
            if ($iframeUrl === '') {
                $iframeUrl = 'https://pay.paythor.com/payment/' . $paymentToken;
            }

            return $this->success('Payment session created.', [
                'iframe_url'    => $iframeUrl,
                'payment_token' => $paymentToken,
            ]);

        } catch (\Throwable $e) {
            $this->logger->warning('SanalPosPro: createPayment failed', ['error' => $e->getMessage()]);
            return $this->error('Payment creation failed: ' . $e->getMessage());
        }
    }

    private function actionGetByToken(array $params): array
    {
        $token = (string) ($params['iapi_token'] ?? '');
        if ($token === '') {
            return $this->error('token is required.');
        }

        $pub = (string) ($this->systemConfigService->get(self::CONFIG_PUBLIC_KEY) ?? '');
        $sec = (string) ($this->systemConfigService->get(self::CONFIG_SECRET_KEY) ?? '');

        if ($pub === '' || $sec === '') {
            return $this->error('API keys not configured.');
        }

        return $this->callGetByToken($token, $pub, $sec);
    }

    private function callGetByToken(string $token, string $pub, string $sec): array
    {
        try {
            [$hashTime, $hashRand, $hash] = $this->generateHash($pub, $sec);

            $response = $this->httpClient->request('GET', self::PAYTHOR_API_BASE . '/payment/getbytoken/' . $token, [
                'headers' => [
                    'Authorization'  => 'ApiKeys ' . $pub . ':' . $hash,
                    'X-Timestamp'    => $hashTime,
                    'X-Nonce'        => $hashRand,
                    'ETC-PROGRAM-ID' => (string) self::PROGRAM_ID,
                    'ETC-APP-ID'     => (string) $this->savedAppId(),
                ],
                'timeout' => 10,
            ]);

            $rawBody  = $response->getContent(false);
            $httpCode = $response->getStatusCode();
            $this->logger->info('SanalPosPro: getByToken', ['http' => $httpCode, 'body' => substr($rawBody, 0, 500)]);

            $data = json_decode($rawBody, true);
            if (!is_array($data)) {
                return $this->error('getbytoken returned non-JSON (HTTP ' . $httpCode . ').');
            }

            return $data;
        } catch (\Throwable $e) {
            $this->logger->warning('SanalPosPro: getByToken failed', ['error' => $e->getMessage()]);
            return $this->error('getByToken failed: ' . $e->getMessage());
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function fetchAndSaveApiKeys(string $bearerToken): array
    {
        $bearerHeaders = [
            'Authorization'  => 'Bearer ' . $bearerToken,
            'ETC-PROGRAM-ID' => (string) self::PROGRAM_ID,
            'ETC-APP-ID'     => (string) self::SHOPWARE_APP_ID_DEFAULT,
            'Content-Type'   => 'application/json',
        ];

        try {
            [$myApp, $allApps] = $this->findMyApp($bearerHeaders);
            $this->logger->info('SanalPosPro: listMyApps full', ['apps' => $allApps]);

            if ($myApp === null) {
                $this->logger->info('SanalPosPro: app not installed, installing now');
                $this->installShopwareApp($bearerHeaders);

                [$myApp, $allApps] = $this->findMyApp($bearerHeaders);
                $this->logger->info('SanalPosPro: listMyApps after install', ['apps' => $allApps]);
            }

            $attemptedIds = [];
            $attemptErrors = [];

            $success = $this->trySaveApiKeysFromAppCandidates(
                $myApp,
                $allApps,
                $bearerHeaders,
                $bearerToken,
                $attemptedIds,
                $attemptErrors
            );

            if ($success !== null) {
                return $success;
            }

            // Some accounts have stale app rows with empty keys; force install and retry once.
            $this->logger->info('SanalPosPro: no usable keys from current app list, forcing install retry');
            $this->installShopwareApp($bearerHeaders);

            [$myApp, $allApps] = $this->findMyApp($bearerHeaders);
            $this->logger->info('SanalPosPro: listMyApps after forced install', ['apps' => $allApps]);

            $success = $this->trySaveApiKeysFromAppCandidates(
                $myApp,
                $allApps,
                $bearerHeaders,
                $bearerToken,
                $attemptedIds,
                $attemptErrors
            );

            if ($success !== null) {
                return $success;
            }

            if ($myApp === null) {
                $appSummary = array_map(
                    function ($app) {
                        return [
                            'id' => $app['id'] ?? '?',
                            'app_id' => $app['app_id'] ?? '?',
                            'name' => $app['name'] ?? '?',
                        ];
                    },
                    $allApps
                );
                return $this->error('App not found after install attempt. Available apps: ' . json_encode($appSummary));
            }

            return $this->error('No usable API keys found for installed apps. Attempts: ' . json_encode($attemptErrors));

        } catch (\Throwable $e) {
            $this->logger->warning('SanalPosPro: fetchAndSaveApiKeys failed', ['error' => $e->getMessage()]);
            return $this->error('Failed to fetch API keys: ' . $e->getMessage());
        }
    }

    /** @return array{0: array|null, 1: array} */
    private function findMyApp(array $bearerHeaders): array
    {
        $response = $this->httpClient->request('GET', self::PAYTHOR_API_BASE . '/app/list/my', [
            'headers' => $bearerHeaders,
            'timeout' => 10,
        ]);

        $data = json_decode($response->getContent(false), true);
        $this->logger->info('SanalPosPro: listMyApps', ['status' => $data['status'] ?? 'unknown']);

        if (($data['status'] ?? '') !== 'success' || !is_array($data['data'] ?? null)) {
            return [null, []];
        }

        $all = $data['data'];
        $candidates = $this->buildApiKeyCandidates(null, $all);
        return [$candidates[0] ?? null, $all];
    }

    private function installShopwareApp(array $bearerHeaders): void
    {
        $installRaw = $this->httpClient->request(
            'POST',
            self::PAYTHOR_API_BASE . '/app/install/' . self::SHOPWARE_APP_ID_DEFAULT,
            [
                'headers' => $bearerHeaders,
                'json'    => [
                    'install' => [
                        'app_stage'  => 'production',
                        'app_id'     => self::SHOPWARE_APP_ID_DEFAULT,
                        'program_id' => self::PROGRAM_ID,
                        'store_url'  => 'http://localhost',
                    ],
                ],
                'timeout' => 10,
            ]
        );

        $installBody = $installRaw->getContent(false);
        $installData = json_decode($installBody, true) ?? [];
        $this->logger->info('SanalPosPro: install response', [
            'http'    => $installRaw->getStatusCode(),
            'body'    => substr($installBody, 0, 500),
            'status'  => $installData['status'] ?? 'unknown',
            'message' => $installData['message'] ?? '',
        ]);
    }

    private function trySaveApiKeysFromAppCandidates(
        ?array $preferredApp,
        array $allApps,
        array $bearerHeaders,
        string $bearerToken,
        array &$attemptedIds,
        array &$attemptErrors
    ): ?array {
        $candidates = $this->buildApiKeyCandidates($preferredApp, $allApps);

        foreach ($candidates as $app) {
            $installedId = (int) ($app['id'] ?? 0);
            if ($installedId === 0 || in_array($installedId, $attemptedIds, true)) {
                continue;
            }

            $attemptedIds[] = $installedId;
            $keysResult     = $this->fetchApiKeysByInstalledId($installedId, $bearerHeaders);

            if (($keysResult['status'] ?? '') !== 'success') {
                $attemptErrors[] = [
                    'installed_id' => $installedId,
                    'message'      => (string) ($keysResult['message'] ?? 'unknown error'),
                ];
                continue;
            }

            $publicKey = (string) ($keysResult['public_key'] ?? '');
            $secretKey = (string) ($keysResult['secret_key'] ?? '');

            $this->systemConfigService->set(self::CONFIG_PUBLIC_KEY, trim($publicKey));
            $this->systemConfigService->set(self::CONFIG_SECRET_KEY, trim($secretKey));
            $this->systemConfigService->set(self::CONFIG_ACCESS_TOKEN, $bearerToken);

            $this->logger->info('SanalPosPro: API keys auto-fetched and saved', ['installed_id' => $installedId]);

            return $this->success('API keys retrieved and saved automatically.', ['public_key' => $publicKey]);
        }

        return null;
    }

    private function fetchApiKeysByInstalledId(int $installedId, array $bearerHeaders): array
    {
        try {
            $keysResp = $this->httpClient->request(
                'GET',
                self::PAYTHOR_API_BASE . '/app/getapikeys/' . $installedId,
                ['headers' => $bearerHeaders, 'timeout' => 10]
            );

            $rawBody  = $keysResp->getContent(false);
            $httpCode = $keysResp->getStatusCode();
            $this->logger->info('SanalPosPro: getApiKeys raw', [
                'installed_id' => $installedId,
                'http'         => $httpCode,
                'body'         => substr($rawBody, 0, 500),
            ]);

            $data = json_decode($rawBody, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                return $this->error('getApiKeys returned non-JSON (HTTP ' . $httpCode . '): ' . substr($rawBody, 0, 300));
            }

            if (($data['status'] ?? '') !== 'success') {
                return $this->error('getApiKeys failed: ' . ($data['message'] ?? 'unknown error'));
            }

            $publicRaw = $data['data']['public_key'] ?? $data['data']['publicKey'] ?? $data['data']['publicApiKey'] ?? '';
            $secretRaw = $data['data']['secret_key'] ?? $data['data']['secretKey'] ?? $data['data']['secretApiKey'] ?? '';

            $publicKey = is_string($publicRaw) ? trim($publicRaw) : '';
            $secretKey = is_string($secretRaw) ? trim($secretRaw) : '';

            if ($publicKey === '' || $secretKey === '') {
                return $this->error('getApiKeys response missing public_key or secret_key for installed app id ' . $installedId . '.');
            }

            return [
                'status'     => 'success',
                'public_key' => $publicKey,
                'secret_key' => $secretKey,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('SanalPosPro: getApiKeys failed', [
                'installed_id' => $installedId,
                'error'        => $e->getMessage(),
            ]);
            return $this->error('getApiKeys request failed: ' . $e->getMessage());
        }
    }

    private function buildApiKeyCandidates(?array $preferredApp, array $allApps): array
    {
        $candidates = [];

        if (is_array($preferredApp) && $preferredApp !== []) {
            $candidates[] = $preferredApp;
        }

        foreach ($allApps as $app) {
            if (!is_array($app)) {
                continue;
            }

            $appId = (int) ($app['app_id'] ?? 0);
            $name  = strtolower((string) ($app['name'] ?? ''));

            if ($appId === self::SHOPWARE_APP_ID_DEFAULT || $this->contains($name, 'shopware') || $this->contains($name, 'swr')) {
                $candidates[] = $app;
            }
        }

        $deduped = [];
        $seen    = [];
        foreach ($candidates as $app) {
            $id  = (int) ($app['id'] ?? 0);
            $key = $id > 0 ? 'id:' . $id : md5(json_encode($app) ?: '');
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduped[]  = $app;
        }

        usort($deduped, function (array $a, array $b): int {
            $scoreA = $this->appCandidateScore($a);
            $scoreB = $this->appCandidateScore($b);

            if ($scoreA === $scoreB) {
                return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0);
            }

            return $scoreB <=> $scoreA;
        });

        return $deduped;
    }

    private function appCandidateScore(array $app): int
    {
        $score = 0;

        if ((int) ($app['app_id'] ?? 0) === self::SHOPWARE_APP_ID_DEFAULT) {
            $score += 100;
        }

        $name = strtolower((string) ($app['name'] ?? ''));
        if ($this->contains($name, 'shopware') || $this->contains($name, 'swr')) {
            $score += 40;
        }

        $storeUrl = strtolower((string) ($app['store_url'] ?? ''));
        if ($storeUrl !== '') {
            $score += 30;
        }

        if ($this->requestHost !== '' && $storeUrl !== '' && $this->contains($storeUrl, $this->requestHost)) {
            $score += 80;
        }

        if ($this->contains($storeUrl, 'localhost') || $this->contains($storeUrl, '127.0.0.1')) {
            $score += 20;
        }

        return $score;
    }

    private function savedAppId(): int
    {
        $saved = (int) ($this->systemConfigService->get(self::CONFIG_APP_ID) ?? 0);

        // /app/list/all app ids are catalog-level small ids. Large ids are
        // typically installed-app record ids from /app/list/my and break UI
        // matching in account-switch flows.
        if ($saved <= 0 || $saved > self::MAX_SANE_APP_ID) {
            return self::SHOPWARE_APP_ID_DEFAULT;
        }

        return $saved;
    }

    private function generateHash(string $publicKey, string $secretKey): array
    {
        // Keep hash generation byte-for-byte aligned with legacy WooCommerce module.
        $hashTime = (string) microtime(true);
        $hashRand = (string) rand(1000000, 9999999);
        $hash     = hash('sha256', $publicKey . $secretKey . $hashTime . $hashRand);

        return [$hashTime, $hashRand, $hash];
    }

    private function discoverAndSaveShopwareAppId(string $token, string $pub, string $sec): void
    {
        try {
            $data = $this->callSignedEndpoint('GET', '/app/list/all', $pub, $sec, [], 10, 'discoverAppId');

            if (($data['status'] ?? '') !== 'success') {
                return;
            }

            foreach ($data['data'] ?? [] as $app) {
                $appId = (int) ($app['id'] ?? 0);
                $name  = strtolower((string) ($app['name'] ?? ''));
                if ($appId === self::SHOPWARE_APP_ID_DEFAULT || $this->contains($name, 'swr') || $this->contains($name, 'shopware')) {
                    $this->systemConfigService->set(self::CONFIG_APP_ID, $appId);
                    $this->logger->info('SanalPosPro: app ID saved', ['app_id' => $appId]);
                    return;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('SanalPosPro: discoverAppId failed', ['error' => $e->getMessage()]);
        }
    }

    private function callMerchantInfo(string $pub, string $sec): array
    {
        return $this->callSignedEndpoint('POST', '/merchant/info', $pub, $sec, [], 10, 'merchantInfo');
    }

    private function callSignedEndpoint(
        string $method,
        string $endpoint,
        string $pub,
        string $sec,
        array $payload,
        int $timeout,
        string $logContext
    ): array {
        $attempts = [
            ['name' => 'withEtcHeaders', 'includeEtcHeaders' => true],
            ['name' => 'withoutEtcHeaders', 'includeEtcHeaders' => false],
        ];

        $lastError = null;

        foreach ($attempts as $attempt) {
            try {
                [$hashTime, $hashRand, $hash] = $this->generateHash($pub, $sec);

                $headers = [
                    'Authorization' => 'ApiKeys ' . $pub . ':' . $hash,
                    'X-Timestamp'   => $hashTime,
                    'X-Nonce'       => $hashRand,
                    'Content-Type'  => 'application/json',
                ];

                if ($attempt['includeEtcHeaders']) {
                    $headers['ETC-PROGRAM-ID'] = (string) self::PROGRAM_ID;
                    $headers['ETC-APP-ID']     = (string) $this->savedAppId();
                }

                $options = [
                    'headers' => $headers,
                    'timeout' => $timeout,
                ];

                if (strtoupper($method) === 'GET') {
                    if ($payload !== []) {
                        $options['query'] = $payload;
                    }
                } else {
                    $options['json'] = $payload;
                }

                $response = $this->httpClient->request($method, self::PAYTHOR_API_BASE . $endpoint, $options);

                $rawBody  = $response->getContent(false);
                $httpCode = $response->getStatusCode();

                $this->logger->info('SanalPosPro: ' . $logContext . ' raw', [
                    'attempt' => $attempt['name'],
                    'http'    => $httpCode,
                    'body'    => substr($rawBody, 0, 500),
                ]);

                $data = json_decode($rawBody, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                    return $this->error('API returned non-JSON (HTTP ' . $httpCode . '): ' . substr($rawBody, 0, 300));
                }

                if ($this->isHashErrorResponse($data) && $attempt['includeEtcHeaders']) {
                    $lastError = $data;
                    continue;
                }

                return $data;
            } catch (\Throwable $e) {
                $this->logger->warning('SanalPosPro: ' . $logContext . ' failed', [
                    'attempt' => $attempt['name'],
                    'error'   => $e->getMessage(),
                ]);
                $lastError = $this->error('Request failed: ' . $e->getMessage());
            }
        }

        return $lastError ?? $this->error('Request failed.');
    }

    private function decodeIapiParams(string $rawParams): array
    {
        $decoded = json_decode($rawParams, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // WooCommerce flow occasionally posts escaped JSON strings.
        $decoded = json_decode(stripslashes($rawParams), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function readAccessTokenFromParams(array $params): string
    {
        return trim((string) (
            $params['iapi_accessToken']
            ?? $params['iapi_access_token']
            ?? $params['accessToken']
            ?? $params['access_token']
            ?? $params['accesstoken']
            ?? $params['etc_token']
            ?? $params['etc-token']
            ?? $params['token']
            ?? ''
        ));
    }

    private function readAppIdFromParams(array $params): int
    {
        $raw = $params['iapi_appId']
            ?? $params['iapi_app_id']
            ?? $params['appId']
            ?? $params['app_id']
            ?? null;

        $parsed = (int) $raw;
        if ($parsed <= 0 || $parsed > self::MAX_SANE_APP_ID) {
            return 0;
        }

        return $parsed;
    }

    private function buildBearerHeaders(string $token, ?int $appId = null): array
    {
        $resolvedAppId = $appId ?? $this->savedAppId();
        if ($resolvedAppId <= 0 || $resolvedAppId > self::MAX_SANE_APP_ID) {
            $resolvedAppId = self::SHOPWARE_APP_ID_DEFAULT;
        }

        return [
            'Authorization'  => 'Bearer ' . $token,
            'ETC-PROGRAM-ID' => (string) self::PROGRAM_ID,
            'ETC-APP-ID'     => (string) $resolvedAppId,
            'Content-Type'   => 'application/json',
        ];
    }

    private function normalizeCatalogApps(array $apps): array
    {
        $normalized = [];

        foreach ($apps as $app) {
            if (!is_array($app)) {
                continue;
            }

            $row  = $app;
            $id   = (int) ($row['id'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));
            $slug = strtolower($name);

            if (!isset($row['app_id']) && $id > 0) {
                $row['app_id'] = $id;
            }

            if (!isset($row['appId']) && $id > 0) {
                $row['appId'] = $id;
            }

            if (
                $id === self::SHOPWARE_APP_ID_DEFAULT
                || $this->contains($slug, 'swr')
                || $this->contains($slug, 'shopware')
            ) {
                $row['name'] = 'Shopware SanalPOS PRO!';
                $row['platform'] = 'shopware';
            }

            $normalized[] = $row;
        }

        return $normalized;
    }

    private function getSavedAccessToken(): string
    {
        return trim((string) ($this->systemConfigService->get(self::CONFIG_ACCESS_TOKEN) ?? ''));
    }

    private function refreshApiKeysFromSavedAccessToken(): bool
    {
        $token = $this->getSavedAccessToken();
        if ($token === '') {
            return false;
        }

        $result = $this->fetchAndSaveApiKeys($token);
        return ($result['status'] ?? '') === 'success';
    }

    private function clearStoredApiKeys(): void
    {
        $this->systemConfigService->delete(self::CONFIG_PUBLIC_KEY);
        $this->systemConfigService->delete(self::CONFIG_SECRET_KEY);
        $this->systemConfigService->delete(self::CONFIG_APP_ID);
    }

    private function contains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return strpos($haystack, $needle) !== false;
    }

    private function isHashErrorResponse(array $data): bool
    {
        if (($data['status'] ?? '') === 'success') {
            return false;
        }

        $message = strtolower((string) ($data['message'] ?? ''));
        return $this->contains($message, 'hash') || $this->contains($message, 'nonce') || $this->contains($message, 'rand');
    }

    private function isMerchantMismatchResponse(array $data): bool
    {
        if (($data['status'] ?? '') === 'success') {
            return false;
        }

        $message = strtolower((string) ($data['message'] ?? ''));
        return $this->contains($message, 'mismatch') || $this->contains($message, 'merchant');
    }

    private function isTransportErrorResponse(array $data): bool
    {
        if (($data['status'] ?? '') === 'success') {
            return false;
        }

        $message = strtolower((string) ($data['message'] ?? ''));

        return $this->contains($message, 'request failed:')
            || $this->contains($message, 'could not resolve host')
            || $this->contains($message, 'timed out')
            || $this->contains($message, 'connection refused')
            || $this->contains($message, 'network is unreachable');
    }

    private function success(string $message, array $data = []): array
    {
        return [
            'status'  => 'success',
            'message' => $message,
            'data'    => $data,
            'details' => [],
            'meta'    => ['xfvv' => null, 'nonce' => null],
        ];
    }

    private function error(string $message): array
    {
        return [
            'status'  => 'error',
            'message' => $message,
            'details' => [],
            'meta'    => ['xfvv' => null, 'nonce' => null],
        ];
    }
}
