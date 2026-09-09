<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Http;

use Caretaker2\Hub\Domain\EnrollmentException;
use Caretaker2\Hub\Domain\EnrollmentService;
use Caretaker2\Hub\Domain\IngestException;
use Caretaker2\Hub\Domain\IngestService;
use Caretaker2\Hub\Domain\InstanceRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * The API the agents speak to.
 *
 * Runs ahead of site resolution so that it works even when the hub has no site
 * configured at all, or the frontend is busy with something else.
 */
final class ApiMiddleware implements MiddlewareInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const PATH_ENROLL = '/caretaker2/api/enroll';
    private const PATH_INVENTORY = '/caretaker2/api/inventory';

    public function __construct(
        private readonly EnrollmentService $enrollment,
        private readonly IngestService $ingest,
        private readonly InstanceRepository $instances,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if ($path !== self::PATH_ENROLL && $path !== self::PATH_INVENTORY) {
            return $handler->handle($request);
        }

        if ($request->getMethod() !== 'POST') {
            return $this->error('POST only.', 405);
        }

        $payload = json_decode((string)$request->getBody(), true);
        if (!is_array($payload)) {
            return $this->error('The body is not valid JSON.', 400);
        }

        // From here on the hub always answers JSON. An exception passed through
        // would reach the agent as an HTML error page, out of which it can make
        // no usable message for the console.
        try {
            return $path === self::PATH_ENROLL
                ? $this->handleEnroll($payload)
                : $this->handleInventory($request, $payload);
        } catch (\Throwable $e) {
            $this->logger?->error('The Caretaker2 API failed', ['exception' => $e]);

            return $this->error('Internal error in the hub: ' . $e->getMessage(), 500);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handleEnroll(array $payload): ResponseInterface
    {
        $code = (string)($payload['code'] ?? '');
        $instanceUrl = (string)($payload['instanceUrl'] ?? '');

        if ($code === '' || $instanceUrl === '') {
            return $this->error('code and instanceUrl are required.', 400);
        }

        try {
            $token = $this->enrollment->redeem(
                $code,
                $instanceUrl,
                (string)($payload['agentVersion'] ?? '')
            );
        } catch (EnrollmentException $e) {
            return $this->error($e->getMessage(), 403);
        }

        return new JsonResponse(['token' => $token], 201);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handleInventory(ServerRequestInterface $request, array $payload): ResponseInterface
    {
        $token = $this->bearerToken($request);
        if ($token === null) {
            return $this->error('Authorization: Bearer <token> is missing.', 401);
        }

        $instance = $this->instances->findByToken($token);
        if ($instance === null) {
            return $this->error('The token is unknown or has been revoked.', 403);
        }

        try {
            $result = $this->ingest->accept($instance, $payload);
        } catch (IngestException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return new JsonResponse([
            'accepted' => true,
            'stored' => $result['stored'],
            'instance' => $instance->uid,
        ]);
    }

    private function bearerToken(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function error(string $message, int $status): ResponseInterface
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
