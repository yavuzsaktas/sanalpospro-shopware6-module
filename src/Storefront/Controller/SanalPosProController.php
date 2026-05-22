<?php declare(strict_types=1);

namespace SanalposproPayment\Storefront\Controller;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class SanalPosProController extends StorefrontController
{
    private const PAYTHOR_API_BASE        = 'https://live-api.sanalpospro.com';
    private const SHOPWARE_APP_ID_DEFAULT = 106;
    private const PROGRAM_ID             = 1;
    private const CONFIG_APP_ID          = 'SanalPosPro.config.appId';
    private const CONFIG_PUBLIC_KEY      = 'SanalPosPro.config.publicApiKey';
    private const CONFIG_SECRET_KEY      = 'SanalPosPro.config.secretApiKey';

    /** @var SystemConfigService */
    private $systemConfigService;

    /** @var HttpClientInterface */
    private $httpClient;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        SystemConfigService $systemConfigService,
        HttpClientInterface $httpClient,
        LoggerInterface $logger
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    #[Route(path: '/sanalpospro/iframe/{transactionId}', name: 'frontend.sanalpospro.iframe', methods: ['GET'])]
    public function iframe(string $transactionId, Request $request): Response
    {
        if ($transactionId === '') {
            return $this->redirectToRoute('frontend.checkout.cart.page');
        }

        $returnUrl   = (string) $request->query->get('returnUrl', '');
        $publicApiKey = (string) ($this->systemConfigService->get('SanalPosPro.config.publicApiKey') ?? '');

        return $this->renderStorefront('@EticsoftSanalPosPro/storefront/page/checkout/iframe.html.twig', [
            'transactionId' => $transactionId,
            'returnUrl'     => $returnUrl,
            'publicApiKey'  => $publicApiKey,
            'iframeUrl'     => '',
        ]);
    }

    #[Route(path: '/sanalpospro/callback', name: 'frontend.sanalpospro.callback', methods: ['GET'])]
    public function callback(Request $request): Response
    {
        // PayThor appends &p_id=<process_token> on full-page (window.top) redirects.
        $pId = (string) $request->query->get('p_id', '');

        // Fallback: PayThor sometimes appends &p_id=... even when return_url has no
        // query string, resulting in a malformed URL that PHP's query parser misses.
        if ($pId === '') {
            $requestUri = (string) $request->server->get('REQUEST_URI', '');
            if (preg_match('/[?&]p_id=([^&]+)/', $requestUri, $m) === 1) {
                $pId = rawurldecode($m[1]);
            }
        }

        if ($pId !== '') {
            return $this->handleFullPageRedirect($pId, $request);
        }

        // Case B: postMessage bridge — callback was loaded inside the iframe.
        return $this->handlePostMessageBridge($request);
    }

    /**
     * Case A: PayThor did window.top.location = return_url&p_id=<token>.
     * Verify payment status with PayThor, then redirect to Shopware finalization
     * URL on success, or to cart on failure.
     */
    private function handleFullPageRedirect(string $pId, Request $request): Response
    {
        $ret = (string) $request->query->get('ret', '');

        $pub = (string) ($this->systemConfigService->get(self::CONFIG_PUBLIC_KEY) ?? '');
        $sec = (string) ($this->systemConfigService->get(self::CONFIG_SECRET_KEY) ?? '');

        $isApproved = false;
        $isFailed   = false;

        if ($pub !== '' && $sec !== '') {
            try {
                [$hashTime, $hashRand, $hash] = $this->generateHash($pub, $sec);

                $response = $this->httpClient->request(
                    'GET',
                    self::PAYTHOR_API_BASE . '/process/getbytoken/' . rawurlencode($pId),
                    [
                        'headers' => [
                            'Authorization'  => 'ApiKeys ' . $pub . ':' . $hash,
                            'X-Timestamp'    => $hashTime,
                            'X-Nonce'        => $hashRand,
                            'ETC-PROGRAM-ID' => (string) self::PROGRAM_ID,
                            'ETC-APP-ID'     => (string) $this->savedAppId(),
                        ],
                        'timeout' => 10,
                    ]
                );

                $rawBody = $response->getContent(false);
                $data    = json_decode($rawBody, true);
                $this->logger->info('SanalPosPro: callback getbytoken', [
                    'http'   => $response->getStatusCode(),
                    'p_id'   => substr($pId, 0, 20) . '...',
                    'status' => $data['data']['status'] ?? $data['status'] ?? 'unknown',
                ]);

                $statusRaw  = mb_strtolower(trim((string) ($data['data']['status'] ?? $data['status'] ?? '')), 'UTF-8');
                $isApproved = in_array($statusRaw, [
                    'success', 'paid', 'authorized', 'captured', 'approved', 'completed',
                    'tamamlandı', 'tamamlandi',
                ], true);
                $isFailed   = in_array($statusRaw, [
                    'failed', 'failure', 'declined', 'cancelled', 'canceled', 'rejected',
                    'başarısız', 'basarisiz',
                ], true);

            } catch (\Throwable $e) {
                $this->logger->warning('SanalPosPro: callback getbytoken failed', [
                    'p_id'  => substr($pId, 0, 20) . '...',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($isFailed) {
            // Payment definitively declined → back to cart.
            return new RedirectResponse('/checkout/cart');
        }

        // Approved or unknown (pending / API error) → let Shopware finalize.
        // The _sw_payment_token in $ret authenticates the finalization; the
        // p_id is passed along so finalize() can do its own verification if needed.
        if ($ret !== '') {
            $separator = strpos($ret, '?') !== false ? '&' : '?';
            return new RedirectResponse($ret . $separator . 'p_id=' . rawurlencode($pId));
        }

        // Last-resort fallback: no ret URL stored.
        return new RedirectResponse('/checkout/confirm');
    }

    /**
     * Case B: PayThor loaded the callback page inside our iframe.
     * Post the result to the parent window so the JS listener can redirect.
     */
    private function handlePostMessageBridge(Request $request): Response
    {
        $status    = (string) $request->query->get('status', 'failure');
        $reference = (string) $request->query->get('reference', '');
        $message   = (string) $request->query->get('message', '');

        if (!in_array($status, ['success', 'failure', 'cancel'], true)) {
            $status = 'failure';
        }

        $payload = json_encode(
            [
                'source'    => 'paythor_sanalpospro',
                'status'    => $status,
                'reference' => $reference,
                'message'   => $message,
            ],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        $html = '<!DOCTYPE html>'
            . '<html lang="en"><head><meta charset="UTF-8"></head><body>'
            . '<script>(function(){'
            . 'var p=' . $payload . ';'
            . 'if(window.parent&&window.parent!==window){window.parent.postMessage(p,window.location.origin);}'
            . 'else if(window.opener&&!window.opener.closed){window.opener.postMessage(p,window.location.origin);}'
            . '})()</script>'
            . '</body></html>';

        return new Response(
            $html,
            Response::HTTP_OK,
            [
                'Content-Type'    => 'text/html; charset=UTF-8',
                'X-Frame-Options' => 'SAMEORIGIN',
                'Cache-Control'   => 'no-store',
            ]
        );
    }

    private function generateHash(string $publicKey, string $secretKey): array
    {
        $hashTime = (string) microtime(true);
        $hashRand = (string) random_int(1000000, 9999999);
        $hash     = hash('sha256', $publicKey . $secretKey . $hashTime . $hashRand);

        return [$hashTime, $hashRand, $hash];
    }

    private function savedAppId(): int
    {
        $saved = $this->systemConfigService->get(self::CONFIG_APP_ID);
        return ($saved !== null && (int) $saved > 0) ? (int) $saved : self::SHOPWARE_APP_ID_DEFAULT;
    }
}
