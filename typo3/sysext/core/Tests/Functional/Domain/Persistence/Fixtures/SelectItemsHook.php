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

namespace TYPO3\CMS\Core\Tests\Functional\Domain\Persistence\Fixtures;

final class SelectItemsHook
{
    public static array $contexts = [];

    public function position(array &$parameters): void
    {
        self::$contexts[] = ['pid' => $parameters['row']['pid'], 'effectivePid' => $parameters['effectivePid'], 'language' => $parameters['row']['sys_language_uid'] ?? 0, 'site' => $parameters['site']->getIdentifier()];
        $value = (int)$parameters['row']['pid'] * 10 + (int)($parameters['row']['sys_language_uid'] ?? 0);
        $parameters['items'] = [['label' => 'Default', 'value' => 0], ['label' => 'Position', 'value' => $value]];
    }

    public function populate(array &$parameters): void
    {
        $parameters['items'] = [['label' => 'Only other record', 'value' => 2]];
    }
}
