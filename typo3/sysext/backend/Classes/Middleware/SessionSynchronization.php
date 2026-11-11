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

namespace TYPO3\CMS\Backend\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Backend\Authentication\SessionSynchronization\TargetHandler;
use TYPO3\CMS\Core\Routing\BackendEntryPointResolver;

/**
 * @internal
 */
final readonly class SessionSynchronization implements MiddlewareInterface
{
    public function __construct(
        private BackendEntryPointResolver $backendEntryPointResolver,
        private TargetHandler $targetHandler,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->backendEntryPointResolver->getBackendRoutePath($request) === '/' . TargetHandler::BRIDGE_PATH) {
            return $this->targetHandler->handle($request);
        }
        return $handler->handle($request);
    }
}
