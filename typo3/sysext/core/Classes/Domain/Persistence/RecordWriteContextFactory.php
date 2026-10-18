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
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Authentication\SystemUserAuthentication;
use TYPO3\CMS\Core\Context\Context;

/**
 * @internal Request-keyed authentication snapshots, cloned for independent drafts/batches.
 * Weak keys prevent authentication state from surviving the lifetime of a request.
 */
#[Autoconfigure(public: true, shared: true)]
final class RecordWriteContextFactory
{
    /** @var \WeakMap<ServerRequestInterface, SystemUserAuthentication> */
    private \WeakMap $systemUsers;

    public function __construct()
    {
        $this->systemUsers = new \WeakMap();
    }

    public function capture(Context $context, bool $refresh = false): RecordWriteContext
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $prototype = $request instanceof ServerRequestInterface && !$refresh ? ($this->systemUsers[$request] ?? null) : null;
        $captured = RecordWriteContext::capture($context, $prototype !== null ? clone $prototype : null);
        $captured->assertCurrent($context);
        if ($request instanceof ServerRequestInterface && $captured->user instanceof SystemUserAuthentication) {
            $this->systemUsers[$request] = clone $captured->user;
        }
        return $captured;
    }

    /** @param list<RecordDraft> $drafts */
    public function validate(array $drafts, Context $context): void
    {
        if ($drafts === []) {
            return;
        }
        $fresh = $this->capture($context, true);
        foreach ($drafts as $draft) {
            $draft->assertEditable();
            $draft->context->assertCompatible($fresh);
        }
    }
}
