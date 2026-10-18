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

namespace TYPO3\CMS\Core\Domain\Persistence;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Domain\Persistence\Exception\InvalidWriteContextException;

/** @internal Captures authentication and ambient context, never credentials. */
final readonly class RecordWriteContext
{
    private function __construct(
        public BackendUserAuthentication $user,
        public int $userId,
        public int $workspaceId,
        private LanguageAspect $languageAspect,
    ) {}

    public static function capture(Context $context): self
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication || (int)($user->user['uid'] ?? 0) <= 0) {
            throw new InvalidWriteContextException('Record writes require an authenticated backend user.', 1792305002);
        }
        if ($user->workspace !== (int)$context->getPropertyFromAspect('workspace', 'id', 0)) {
            throw new InvalidWriteContextException('Backend user and Context must select the same workspace.', 1792305003);
        }
        return new self(
            $user,
            (int)$user->user['uid'],
            $user->workspace,
            $context->getAspect('language')
        );
    }

    public function assertCurrent(Context $context): void
    {
        $current = self::capture($context);
        if ($current->user !== $this->user || $current->userId !== $this->userId
            || $current->workspaceId !== $this->workspaceId || $current->languageAspect != $this->languageAspect) {
            throw new InvalidWriteContextException('The draft authentication, language or workspace context has changed.', 1792305004);
        }
    }
}
