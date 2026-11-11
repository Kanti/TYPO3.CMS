<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Backend\Authentication\SessionSynchronization;

use Psr\Clock\ClockInterface;
use TYPO3\CMS\Core\Crypto\Cipher\CipherException;
use TYPO3\CMS\Core\Crypto\Cipher\CipherService;
use TYPO3\CMS\Core\Crypto\Cipher\CipherValue;
use TYPO3\CMS\Core\Crypto\Cipher\KeyFactory;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * @internal
 */
final readonly class TransferStore
{
    private const TABLE = 'be_session_transfer';

    public function __construct(
        private ConnectionPool $connectionPool,
        private ClockInterface $clock,
        private CipherService $cipherService,
        private KeyFactory $keyFactory,
    ) {}

    public function issue(string $purpose, string $audience, array $payload, int $lifetime = 60): string
    {
        if ($lifetime < 1 || $lifetime > 300 || $purpose === '' || strlen($purpose) > 32 || $audience === '' || strlen($audience) > 512) {
            throw new \InvalidArgumentException('Transfer codes require a purpose, an audience and a lifetime between one and 300 seconds.', 1793088043);
        }

        $data = json_encode($payload, JSON_THROW_ON_ERROR);
        $code = bin2hex(random_bytes(32));
        $expires = $this->clock->now()->getTimestamp() + $lifetime;
        $cipherValue = $this->cipherService->encrypt(
            $data,
            $this->keyFactory->deriveSharedKeyFromEncryptionKey(self::class),
            $this->additionalData($code, $purpose, $audience, $expires),
        );
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'identifier' => hash('sha256', $code),
            'expires' => $expires,
            'purpose' => $purpose,
            'audience' => $audience,
            'data' => $cipherValue->encode(),
        ]);

        return $code;
    }

    public function inspect(string $code, string $purpose, string $audience): ?array
    {
        if (!$this->isValidCode($code)) {
            return null;
        }

        $record = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->select(['expires', 'purpose', 'audience', 'data'], self::TABLE, [
                'identifier' => hash('sha256', $code),
                'purpose' => $purpose,
                'audience' => $audience,
            ])
            ->fetchAssociative();
        if ($record === false
            || (int)$record['expires'] <= $this->clock->now()->getTimestamp()
            || !hash_equals($purpose, (string)$record['purpose'])
            || !hash_equals($audience, (string)$record['audience'])
        ) {
            return null;
        }

        try {
            $data = $this->cipherService->decrypt(
                CipherValue::fromSerialized((string)$record['data']),
                $this->keyFactory->deriveSharedKeyFromEncryptionKey(self::class),
                $this->additionalData($code, $purpose, $audience, (int)$record['expires']),
            );
            $payload = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (CipherException|\JsonException|\SodiumException|\TypeError) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    public function consume(string $code, string $purpose, string $audience): ?array
    {
        $payload = $this->inspect($code, $purpose, $audience);
        if ($payload === null) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $affectedRows = $queryBuilder->delete(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('identifier', $queryBuilder->createNamedParameter(hash('sha256', $code))),
                $queryBuilder->expr()->eq('purpose', $queryBuilder->createNamedParameter($purpose)),
                $queryBuilder->expr()->eq('audience', $queryBuilder->createNamedParameter($audience)),
                $queryBuilder->expr()->gt('expires', $queryBuilder->createNamedParameter($this->clock->now()->getTimestamp(), Connection::PARAM_INT)),
            )
            ->executeStatement();

        // Only the request that atomically removed the record may use its payload.
        return $affectedRows === 1 ? $payload : null;
    }

    public function remove(string $code): void
    {
        if ($this->isValidCode($code)) {
            $this->connectionPool->getConnectionForTable(self::TABLE)->delete(self::TABLE, [
                'identifier' => hash('sha256', $code),
            ]);
        }
    }

    public function collectGarbage(): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->delete(self::TABLE)
            ->where($queryBuilder->expr()->lte('expires', $queryBuilder->createNamedParameter($this->clock->now()->getTimestamp(), Connection::PARAM_INT)))
            ->executeStatement();
    }

    private function isValidCode(string $code): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $code) === 1;
    }

    private function additionalData(string $code, string $purpose, string $audience, int $expires): string
    {
        return json_encode([hash('sha256', $code), $purpose, $audience, $expires], JSON_THROW_ON_ERROR);
    }
}
