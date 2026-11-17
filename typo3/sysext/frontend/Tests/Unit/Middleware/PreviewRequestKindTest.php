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

namespace TYPO3\CMS\Frontend\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Frontend\Middleware\PreviewAccess;
use TYPO3\CMS\Frontend\Middleware\PreviewRequestKind;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PreviewRequestKindTest extends UnitTestCase
{
    public static function requestsDataProvider(): \Generator
    {
        yield 'request without Fetch Metadata, as on plain HTTP' => [[], PreviewRequestKind::Unknown, true];
        yield 'document' => [['Sec-Fetch-Dest' => 'document', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Site' => 'none'], PreviewRequestKind::Window, false];
        yield 'document without mode' => [['Sec-Fetch-Dest' => 'document'], PreviewRequestKind::Window, false];
        yield 'navigation of another destination' => [['Sec-Fetch-Dest' => 'embed', 'Sec-Fetch-Mode' => 'navigate'], PreviewRequestKind::Window, false];
        yield 'page loaded into a frame' => [['Sec-Fetch-Dest' => 'iframe', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Site' => 'cross-site'], PreviewRequestKind::Frame, true];
        yield 'fetch of the page' => [['Sec-Fetch-Dest' => 'empty', 'Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Site' => 'same-origin'], PreviewRequestKind::OfThePage, false];
        yield 'image of the page' => [['Sec-Fetch-Dest' => 'image', 'Sec-Fetch-Mode' => 'no-cors', 'Sec-Fetch-Site' => 'same-origin'], PreviewRequestKind::OfThePage, false];
        yield 'fetch of another site' => [['Sec-Fetch-Dest' => 'empty', 'Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Site' => 'cross-site'], PreviewRequestKind::Elsewhere, false];
        yield 'fetch of another origin of the same site' => [['Sec-Fetch-Dest' => 'empty', 'Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Site' => 'same-site'], PreviewRequestKind::Elsewhere, false];
        yield 'request typed by the user' => [['Sec-Fetch-Dest' => 'empty', 'Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Site' => 'none'], PreviewRequestKind::Elsewhere, false];
        yield 'page loaded into a frame element of a frameset' => [['Sec-Fetch-Dest' => 'frame', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Site' => 'same-origin'], PreviewRequestKind::Elsewhere, false];
    }

    /**
     * Browsers describe each request in the Fetch Metadata headers. A preview names the
     * kind once and asks it everywhere: a window loses the token, the frame and the
     * requests of its page keep it, anything else is refused. Without these headers, as
     * on plain HTTP, the request counts as a frame where a frame is asked for.
     */
    #[Test]
    #[DataProvider('requestsDataProvider')]
    public function kindOfARequestFollowsItsFetchMetadata(array $headers, PreviewRequestKind $kind, bool $frameRequest): void
    {
        $request = new ServerRequest('https://site.example/', 'GET', 'php://input', $headers);

        self::assertSame($kind, PreviewRequestKind::fromRequest($request));
        self::assertSame($frameRequest, PreviewAccess::isFrameRequest($request));
    }
}
