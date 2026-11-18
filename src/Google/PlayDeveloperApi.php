<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Google;

use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Exceptions\AuthenticationException;
use BijayX\IapGuard\Exceptions\IapException;
use BijayX\IapGuard\Exceptions\PurchaseNotFoundException;
use BijayX\IapGuard\Exceptions\StoreApiException;
use BijayX\IapGuard\Http\StoreHttpClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Google Play Developer API v3 (SPEC §6.3). Path segments are URL-encoded.
 * acknowledge/consume are idempotent, so they are retried like GETs.
 */
final readonly class PlayDeveloperApi
{
    public function __construct(
        private GoogleConfig $config,
        private ServiceAccountAuth $auth,
        private StoreHttpClient $http,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws IapException
     */
    public function getSubscription(string $token): array
    {
        return $this->request('GET', "purchases/subscriptionsv2/tokens/{$this->segment($token)}");
    }

    /**
     * @return array<string, mixed>
     *
     * @throws IapException
     */
    public function getProduct(string $token): array
    {
        return $this->request('GET', "purchases/productsv2/tokens/{$this->segment($token)}");
    }

    /**
     * @throws IapException
     */
    public function acknowledgeSubscription(string $productId, string $token): void
    {
        $this->request('POST', "purchases/subscriptions/{$this->segment($productId)}/tokens/{$this->segment($token)}:acknowledge");
    }

    /**
     * @throws IapException
     */
    public function acknowledgeProduct(string $productId, string $token): void
    {
        $this->request('POST', "purchases/products/{$this->segment($productId)}/tokens/{$this->segment($token)}:acknowledge");
    }

    /**
     * @throws IapException
     */
    public function consumeProduct(string $productId, string $token): void
    {
        $this->request('POST', "purchases/products/{$this->segment($productId)}/tokens/{$this->segment($token)}:consume");
    }

    /**
     * @return array<string, mixed>
     *
     * @throws IapException
     */
    private function request(string $method, string $path): array
    {
        $url = $this->config->baseUrl.'applications/'.$this->segment($this->config->packageName).'/'.$path;
        $accessToken = $this->auth->accessToken();

        $response = $this->http->send(static function (PendingRequest $request) use ($method, $url, $accessToken): Response {
            $request = $request->withToken($accessToken);

            return $method === 'GET'
                ? $request->get($url)
                : $request->withBody('{}', 'application/json')->post($url);
        });

        if ($response->successful()) {
            $json = $response->json();

            /** @var array<string, mixed> */
            return is_array($json) ? $json : [];
        }

        throw $this->error($response);
    }

    private function error(Response $response): IapException
    {
        $status = $response->status();
        $body = $response->json();
        $body = is_array($body) ? $body : null;

        if ($status === 401 || $status === 403) {
            if ($status === 401) {
                $this->auth->forget();
            }

            return new AuthenticationException("The Play Developer API rejected our credentials ({$status}).", Platform::Google, $status);
        }

        if (in_array($status, [400, 404, 410], true)) {
            return new PurchaseNotFoundException(Platform::Google, $status);
        }

        $message = is_array($body['error'] ?? null) && is_string($body['error']['message'] ?? null) ? $body['error']['message'] : 'Unexpected response.';

        return new StoreApiException(
            "Play Developer API error ({$status}): {$message}",
            Platform::Google,
            $status,
            $body ?? $response->body(),
            StoreHttpClient::isRetryable($response),
        );
    }

    private function segment(string $value): string
    {
        return rawurlencode($value);
    }
}
