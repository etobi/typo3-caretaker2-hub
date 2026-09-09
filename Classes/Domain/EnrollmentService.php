<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Der Ersatz für den Schlüsseltausch des Vorgängers.
 *
 * Der Hub gibt einen kurzlebigen Code aus, die Instanz tauscht ihn gegen ein
 * dauerhaftes Token. Kein Redirect, kein Callback, keine sich gegenseitig
 * erreichenden Systeme — die Instanz spricht, der Hub antwortet.
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
     * Löst den Code ein und gibt das Klartext-Token zurück — das einzige Mal,
     * dass es existiert. Gespeichert wird nur sein Hash.
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
            // Absichtlich eine Meldung für alle drei Fälle — unbekannt,
            // abgelaufen, schon benutzt. Wer Codes raten will, soll nicht
            // erfahren, welcher davon zutrifft.
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
     * Ein brauchbarer Anzeigename ohne Nachfrage: der Hostname.
     * Umbenennen kann man später im Backend.
     */
    private function deriveTitle(string $instanceUrl): string
    {
        $host = parse_url($instanceUrl, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'Neue Instanz';
    }
}
