<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Scheme, host and port of the address a request came in on. The port is
 * only spelled out when it is not the default of its scheme.
 */
final class Origin
{
    public static function fromRequest(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $origin = $uri->getScheme() . '://' . $uri->getHost();

        if ($uri->getPort() !== null && !in_array($uri->getPort(), [80, 443], true)) {
            $origin .= ':' . $uri->getPort();
        }

        return $origin;
    }
}
