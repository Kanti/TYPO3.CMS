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

namespace TYPO3Tests\TestPreviewEditMode\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Preview\PreviewMode;

/**
 * Turns on the edit mode where the preview token asks for the mode edit. The backend
 * signs the mode into the token, so neither the address nor a visitor can ask for it.
 * Only public API is used: the property "mode" of the aspect "frontend.preview", not
 * the preview token itself.
 */
final readonly class EditMode implements MiddlewareInterface
{
    public function __construct(private Context $context) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $editMode = $this->context->getPropertyFromAspect('frontend.preview', 'mode', PreviewMode::View) === PreviewMode::Edit;
        $response = $handler->handle($request);
        return $editMode ? $response->withHeader('X-Preview-Edit-Mode', 'on') : $response;
    }
}
