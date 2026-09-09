<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Connecting an instance without a key exchange.
 *
 * The hub hands out a short-lived code, the instance trades it for a lasting
 * token. No redirect, no callback, no two systems that have to reach each
 * other — the instance speaks, the hub answers.
 */
final class EnrollmentService
{
    public const TABLE = 'tx_caretaker2_enrollment';

    private const VALIDITY_SECONDS = 900;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly InstanceRepository $instances,
    ) {}

    public function createCode(int $tenant = 1): string
    {
        $code = TokenGenerator::enrollmentCode();

        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid' => 0,
            'crdate' => time(),
            'code' => $code,
            'tenant' => $tenant,
            'valid_until' => time() + self::VALIDITY_SECONDS,
        ]);

        return $code;
    }

    /**
     * Redeems the code and returns the token in clear text, the only time it
     * exists. Only its hash is stored.
     *
     * @throws EnrollmentException
     */
    public function redeem(string $code, string $instanceUrl, string $agentVersion): string
    {
        $code = strtoupper(trim($code));
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        $row = $qb
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('code', $qb->createNamedParameter($code)),
                $qb->expr()->eq('redeemed_at', $qb->createNamedParameter(0, ParameterType::INTEGER)),
                $qb->expr()->gte('valid_until', $qb->createNamedParameter(time(), ParameterType::INTEGER)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            // Deliberately one message for all three cases — unknown, expired,
            // already used. Whoever guesses codes should not learn which.
            throw new EnrollmentException('Code ist unbekannt, abgelaufen oder bereits eingelöst.');
        }

        $tenant = (int)$row['tenant'];
        $token = TokenGenerator::issue($tenant);

        $instanceId = $this->instances->create(
            $tenant,
            $this->deriveTitle($instanceUrl),
            $instanceUrl,
            TokenGenerator::hash($token),
        );

        $this->instances->update($instanceId, ['agent_version' => $agentVersion]);

        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            ['redeemed_at' => time(), 'instance' => $instanceId],
            ['uid' => (int)$row['uid']],
        );

        return $token;
    }

    /**
     * A usable display name without asking: the host name. Renaming it later
     * is a backend edit away.
     */
    private function deriveTitle(string $instanceUrl): string
    {
        $host = parse_url($instanceUrl, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'Neue Instanz';
    }
}
