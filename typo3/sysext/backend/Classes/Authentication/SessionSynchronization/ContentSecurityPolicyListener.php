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

use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Event\PolicyMutatedEvent;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Mutation;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationMode;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;

/**
 * @internal
 */
final readonly class ContentSecurityPolicyListener
{
    public function __construct(private OriginResolver $originResolver) {}

    #[AsEventListener(identifier: 'typo3/cms-backend/session-synchronization-csp')]
    public function __invoke(PolicyMutatedEvent $event): void
    {
        $request = $event->request;
        $route = $request?->getAttribute('route');
        if (!$event->scope->type->isBackend() || $request === null || !$route instanceof Route || $route->getOption('_identifier') !== 'main') {
            return;
        }
        $sources = [];
        foreach ($this->originResolver->resolve($request) as $backendBase) {
            $sources[] = new UriValue($backendBase . 'session/synchronize/bridge');
        }
        if ($sources === []) {
            return;
        }
        $policy = $event->getCurrentPolicy();
        $mutations = [new Mutation(MutationMode::Extend, Directive::FrameSrc, ...$sources)];
        // form-action has no default-src fallback; adding it when absent would restrict existing forms.
        if ($policy->has(Directive::FormAction)) {
            $mutations[] = new Mutation(MutationMode::Extend, Directive::FormAction, ...$sources);
        }
        $collection = new MutationCollection(...$mutations);
        $event->setCurrentPolicy($policy->mutate($collection));
        $event->setMutationCollections(...[...$event->getMutationCollections(), $collection]);
    }
}
