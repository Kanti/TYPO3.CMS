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

namespace TYPO3Tests\TestPreviewEditMode\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Preview\PreviewAccessDeniedException;
use TYPO3\CMS\Backend\Preview\PreviewTokenIssuer;
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Preview\PreviewMode;

/**
 * Answers the address of a frame that shows a page in the edit mode: the frontend
 * address of the page with a preview token of the editor in the mode edit.
 */
#[AsController]
final readonly class EditFrameController
{
    /**
     * PreviewTokenIssuer is marked @internal. An extension has no public API to issue
     * a preview token in the mode edit for its own frame. This example uses the issuer
     * anyway, because that missing public API is exactly what it shows.
     */
    public function __construct(private PreviewTokenIssuer $issuer) {}

    public function urlAction(ServerRequestInterface $request): ResponseInterface
    {
        $pageId = (int)($request->getQueryParams()['id'] ?? 0);
        $frontendUri = PreviewUriBuilder::create($pageId)->buildUri();
        if ($frontendUri === null) {
            return new JsonResponse(['error' => 'notPreviewable'], 404);
        }
        try {
            $preview = $this->issuer->issue($request, $this->getBackendUser(), $frontendUri, $pageId, null, PreviewMode::Edit);
        } catch (PreviewAccessDeniedException) {
            return new JsonResponse(['error' => 'denied'], 403);
        }
        return new JsonResponse(['url' => (string)$preview->uri], 200, ['Cache-Control' => 'private, no-store']);
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
