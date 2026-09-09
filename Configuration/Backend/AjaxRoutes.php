<?php

declare(strict_types=1);

use Caretaker2\Hub\Controller\EnrollmentAjaxController;

return [
    'caretaker2_enrollment_code' => [
        'path' => '/caretaker2/enrollment-code',
        'target' => EnrollmentAjaxController::class . '::create',
        'inheritAccessFromModule' => 'caretaker2_instances',
    ],
];
