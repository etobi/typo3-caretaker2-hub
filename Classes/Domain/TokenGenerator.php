<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

final class TokenGenerator
{
    /**
     * Der Mandant steckt im Token selbst. Das kostet jetzt nichts und
     * erspart später, alle ausgerollten Tokens neu verteilen zu müssen.
     */
    public static function issue(int $tenant): string
    {
        return sprintf('ct2_%d_%s', $tenant, bin2hex(random_bytes(24)));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Enrollment-Code: kurz genug zum Abtippen, ohne die Zeichen, die man
     * beim Vorlesen verwechselt (0/O, 1/I).
     */
    public static function enrollmentCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            if ($i === 4) {
                $code .= '-';
            }
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }
}
