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

namespace TYPO3\CMS\Backend\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Authentication\SessionSynchronization\Coordinator;

/**
 * @internal
 */
#[AsController]
final readonly class SessionSynchronizationController
{
    public function __construct(private Coordinator $coordinator) {}

    public function controlAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->coordinator->control($request, $GLOBALS['BE_USER']);
    }

    public function confirmAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->coordinator->confirm($request, $GLOBALS['BE_USER']);
    }

    public function receiptAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->coordinator->receipt($request, $GLOBALS['BE_USER']);
    }
}
