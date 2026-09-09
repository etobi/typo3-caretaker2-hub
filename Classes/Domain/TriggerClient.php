<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Asks an instance to report right away.
 *
 * The hub never receives data on this path — it only knocks, and the agent
 * pushes through the same route it always uses. Nothing about the instance
 * travels back in the response.
 */
final class TriggerClient
{
    private const PATH = '/caretaker2/trigger';
    private const TIMEOUT_SECONDS = 25;

    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {}

    /**
     * @return array{0: bool, 1: string} success and a message for the user
     */
    public function trigger(Instance $instance): array
    {
        if ($instance->instanceUrl === '') {
            return [false, 'Für diese Instanz ist keine Adresse hinterlegt.'];
        }

        $url = rtrim($instance->instanceUrl, '/') . self::PATH;

        try {
            $response = $this->requestFactory->request($url, 'POST', [
                'timeout' => self::TIMEOUT_SECONDS,
                'http_errors' => false,
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (\Throwable $e) {
            return [false, sprintf('Instanz nicht erreichbar: %s', $e->getMessage())];
        }

        $status = $response->getStatusCode();
        $body = json_decode((string)$response->getBody(), true);

        if ($status === 429) {
            $wait = is_array($body) ? (int)($body['retryAfter'] ?? 0) : 0;

            return [false, sprintf('Die Instanz hat gerade erst gemeldet. In %d Sekunden erneut versuchen.', $wait)];
        }

        if ($status >= 400) {
            $detail = is_array($body) && isset($body['error']) ? (string)$body['error'] : 'HTTP ' . $status;

            return [false, 'Die Instanz hat abgelehnt: ' . $detail];
        }

        return [
            true,
            (is_array($body) && ($body['stored'] ?? false))
                ? 'Die Instanz hat gemeldet, es gab eine Veränderung.'
                : 'Die Instanz hat gemeldet, unverändert.',
        ];
    }
}
