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

namespace TYPO3\CMS\Frontend\Middleware;

use Psr\Http\Message\ServerRequestInterface;

/**
 * What a request with a preview token is, as browsers describe it in the Fetch Metadata
 * headers Sec-Fetch-Dest, Sec-Fetch-Mode and Sec-Fetch-Site. Browsers send these headers
 * only to HTTPS sites and localhost.
 *
 * @internal
 */
enum PreviewRequestKind
{
    /**
     * A navigation of a whole window or tab, such as a link opened in a new tab or a
     * target _blank or _top
     */
    case Window;

    /**
     * The page loaded into a frame
     */
    case Frame;

    /**
     * A request the page sends itself: fetch, XHR or an element of its own origin. Only a
     * document of the site sends one, and in a preview that is the page in the frame.
     */
    case OfThePage;

    /**
     * A request from anywhere else, such as another site or an address the user typed
     */
    case Elsewhere;

    /**
     * A request without Fetch Metadata, as on a plain HTTP site
     */
    case Unknown;

    public static function fromRequest(ServerRequestInterface $request): self
    {
        if (!$request->hasHeader('Sec-Fetch-Dest')) {
            return self::Unknown;
        }
        $destination = strtolower($request->getHeaderLine('Sec-Fetch-Dest'));
        $mode = strtolower($request->getHeaderLine('Sec-Fetch-Mode'));
        if ($destination === 'document') {
            return self::Window;
        }
        if ($mode === 'navigate' && !in_array($destination, ['', 'iframe', 'frame'], true)) {
            return self::Window;
        }
        if ($destination === 'iframe') {
            return self::Frame;
        }
        if (strtolower($request->getHeaderLine('Sec-Fetch-Site')) === 'same-origin' && $mode !== 'navigate') {
            return self::OfThePage;
        }
        return self::Elsewhere;
    }
}
