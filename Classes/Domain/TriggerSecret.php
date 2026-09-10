<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

use TYPO3\CMS\Core\Crypto\Cipher\CipherException;
use TYPO3\CMS\Core\Crypto\Cipher\CipherService;
use TYPO3\CMS\Core\Crypto\Cipher\CipherValue;
use TYPO3\CMS\Core\Crypto\Cipher\KeyFactory;

/**
 * The Basic Auth password for an instance's trigger, at rest.
 *
 * The hub has to send it in clear, so it cannot be hashed. It is sealed with
 * a key derived from the encryption key instead: a database dump alone does
 * not give it away.
 */
final class TriggerSecret
{
    private const PREFIX = 'enc:';

    private const KEY_SEED = 'caretaker2/trigger-password';

    public function __construct(
        private readonly CipherService $cipher,
        private readonly KeyFactory $keys,
    ) {}

    public function seal(string $password): string
    {
        if ($password === '' || $this->isSealed($password)) {
            return $password;
        }

        return self::PREFIX . $this->cipher->encrypt($password, $this->key())->encode();
    }

    /**
     * A value without the prefix never went through seal(); it is used as
     * it is rather than thrown away.
     */
    public function open(string $stored): string
    {
        if (!$this->isSealed($stored)) {
            return $stored;
        }

        try {
            return $this->cipher->decrypt(
                CipherValue::fromSerialized(substr($stored, strlen(self::PREFIX))),
                $this->key()
            );
        } catch (CipherException $e) {
            return '';
        }
    }

    public function isSealed(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    private function key(): \TYPO3\CMS\Core\Crypto\Cipher\SharedKey
    {
        return $this->keys->deriveSharedKeyFromEncryptionKey(self::KEY_SEED);
    }
}
