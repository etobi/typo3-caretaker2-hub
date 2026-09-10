<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Controller;

use Caretaker2\Hub\Domain\EnrollmentService;
use Caretaker2\Hub\Http\Origin;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Hands out an enrollment code without reloading the list.
 */
#[AsController]
final class EnrollmentAjaxController
{
    public function __construct(
        private readonly EnrollmentService $enrollment,
    ) {}

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse([
            'code' => $this->enrollment->createCode(),
            'hubUrl' => Origin::fromRequest($request),
            'validMinutes' => 15,
        ]);
    }
}
