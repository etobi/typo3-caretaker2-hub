<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Asks an instance to report right away.
 */
final class TriggerClient
{
    private const PATH = '/caretaker2/trigger';
    private const TIMEOUT_SECONDS = 25;

    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {}

    /**
     * @return bool whether the hub stored a change
     * @throws TriggerException
     */
    public function trigger(Instance $instance): bool
    {
        if ($instance->instanceUrl === '') {
            throw TriggerException::noUrl();
        }

        $url = rtrim($instance->instanceUrl, '/') . self::PATH;

        try {
            $response = $this->requestFactory->request($url, 'POST', [
                'timeout' => self::TIMEOUT_SECONDS,
                'http_errors' => false,
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (\Throwable $e) {
            throw TriggerException::unreachable($url, $e->getMessage());
        }

        $status = $response->getStatusCode();
        $body = json_decode((string)$response->getBody(), true);
        $body = is_array($body) ? $body : [];

        if ($status === 429) {
            throw TriggerException::cooldown((int)($body['retryAfter'] ?? 0));
        }

        if ($status >= 400) {
            throw TriggerException::refused((string)($body['error'] ?? 'HTTP ' . $status));
        }

        return (bool)($body['stored'] ?? false);
    }
}
