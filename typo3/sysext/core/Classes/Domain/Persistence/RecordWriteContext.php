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

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Authentication\SystemUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Domain\Persistence\Exception\InvalidWriteContextException;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/** @internal Captures execution identity separately from the requesting visitor. */
final readonly class RecordWriteContext
{
    private function __construct(
        public BackendUserAuthentication $user,
        public int $userId,
        public int $workspaceId,
        private LanguageAspect $languageAspect,
        private string $mode,
        private array $systemState,
        private ?\WeakReference $request = null,
    ) {}

    public static function capture(Context $context, ?SystemUserAuthentication $systemUser = null): self
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $application = $request instanceof ServerRequestInterface ? ApplicationType::fromRequest($request) : null;
        $requestReference = $request instanceof ServerRequestInterface ? \WeakReference::create($request) : null;
        $frontend = $application === ApplicationType::FRONTEND;
        $current = $GLOBALS['BE_USER'] ?? null;
        $useSystem = $frontend || ($application === null && Environment::isCli() && !$current instanceof CommandLineUserAuthentication);
        if ($useSystem) {
            try {
                $user = $systemUser ?? GeneralUtility::makeInstance(SystemUserAuthentication::class);
                if ($systemUser === null) {
                    $user->initialize();
                    $result = new self($user, (int)$user->getUserId(), 0, $context->getAspect('language'), $frontend ? 'frontend' : 'system', [], $requestReference);
                    $result->run(fn() => $user->fetchGroupData());
                }
                if ($user->workspace !== 0 || !in_array((int)$GLOBALS['TYPO3_CONF_VARS']['BE']['adminOnly'], [0, 2], true)) {
                    throw new InvalidWriteContextException('The system identity is not available in this execution context.', 1792320002);
                }
                if (!$frontend && (int)$context->getPropertyFromAspect('workspace', 'id', 0) !== 0) {
                    throw new InvalidWriteContextException('A system CLI write requires an explicit Live context or an established CLI workspace identity.', 1792310004);
                }
                return new self($user, (int)$user->getUserId(), 0, $context->getAspect('language'), $frontend ? 'frontend' : 'system', [$user->user, $user->groupData], $requestReference);
            } catch (InvalidWriteContextException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                throw new InvalidWriteContextException('The system write identity is unavailable.', 1792310005, $exception);
            }
        }
        if (!$current instanceof BackendUserAuthentication || (int)($current->user['uid'] ?? 0) <= 0 || $application === ApplicationType::INSTALL) {
            throw new InvalidWriteContextException('Backend writes require an authenticated backend user.', 1792305002);
        }
        if ($current->workspace !== (int)$context->getPropertyFromAspect('workspace', 'id', 0)) {
            throw new InvalidWriteContextException('Backend user and Context must select the same workspace.', 1792305003);
        }
        return new self($current, (int)$current->user['uid'], $current->workspace, $context->getAspect('language'), $application->value ?? 'cli', [], $requestReference);
    }

    public function assertCurrent(Context $context): void
    {
        $this->assertCompatible(self::capture($context, $this->user instanceof SystemUserAuthentication ? $this->user : null));
    }

    public function assertCompatible(self $current): void
    {
        if ($this->request?->get() !== $current->request?->get() || $current->mode !== $this->mode || $current->userId !== $this->userId
            || ($this->systemState === [] && $current->user !== $this->user)
            || $current->systemState !== $this->systemState
            || $current->workspaceId !== $this->workspaceId || $current->languageAspect != $this->languageAspect) {
            throw new InvalidWriteContextException('The draft request, execution identity, language or workspace context has changed.', 1792305004);
        }
    }

    public function readingContext(): Context
    {
        $context = clone GeneralUtility::makeInstance(Context::class);
        $context->setAspect('workspace', new WorkspaceAspect($this->workspaceId));
        $context->setAspect('backend.user', new UserAspect($this->user));
        $context->setAspect('language', $this->languageAspect);
        return $context;
    }

    /**
     * Legacy DataHandler dependencies and hooks still consult globals. Scope those dependencies
     * to the explicit identity and restore them even after exceptions or nested executions.
     */
    public function run(\Closure $operation): mixed
    {
        $context = GeneralUtility::makeInstance(Context::class);
        $alreadyBound = ($GLOBALS['BE_USER'] ?? null) === $this->user
            && (int)$context->getPropertyFromAspect('workspace', 'id', 0) === $this->workspaceId
            && $context->getAspect('language') == $this->languageAspect && isset($GLOBALS['LANG']);
        $aspects = ['workspace' => $context->getAspect('workspace'), 'backend.user' => $context->getAspect('backend.user'), 'language' => $context->getAspect('language')];
        $globals = [];
        foreach (['BE_USER', 'LANG'] as $key) {
            $globals[$key] = [array_key_exists($key, $GLOBALS), $GLOBALS[$key] ?? null];
        }
        $switchingIdentity = ($GLOBALS['BE_USER'] ?? null) !== $this->user
            || (int)$context->getPropertyFromAspect('workspace', 'id', 0) !== $this->workspaceId;
        $runtimeCache = GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime');
        if ($switchingIdentity) {
            $runtimeCache->flush();
        }
        try {
            // Synchronize legacy readers; this aspect never selects or authorizes the actor.
            $context->setAspect('backend.user', new UserAspect($this->user));
            if (!$alreadyBound) {
                $GLOBALS['BE_USER'] = $this->user;
                $context->setAspect('workspace', new WorkspaceAspect($this->workspaceId));
                $context->setAspect('language', $this->languageAspect);
                if ($this->user instanceof SystemUserAuthentication || !isset($GLOBALS['LANG'])) {
                    $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->createFromUserPreferences($this->user);
                }
            }
            return $operation();
        } finally {
            if ($switchingIdentity) {
                $runtimeCache->flush();
            }
            foreach ($aspects as $key => $aspect) {
                $context->setAspect($key, $aspect);
            }
            foreach ($globals as $key => [$existed, $value]) {
                if ($existed) {
                    $GLOBALS[$key] = $value;
                } else {
                    unset($GLOBALS[$key]);
                }
            }
        }
    }
}
