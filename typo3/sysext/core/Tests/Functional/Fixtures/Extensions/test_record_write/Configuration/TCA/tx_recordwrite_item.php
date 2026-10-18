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

defined('TYPO3') or die();

return [
    'ctrl' => [
        'title' => 'Write API fixture', 'label' => 'title', 'tstamp' => 'tstamp',
        'crdate' => 'crdate', 'delete' => 'deleted', 'sortby' => 'sorting',
        'security' => ['ignorePageTypeRestriction' => true],
        'versioningWS' => true, 'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent', 'translationSource' => 'l10n_source',
        'enablecolumns' => ['disabled' => 'hidden', 'starttime' => 'starttime'],
    ],
    'columns' => [
        'placement' => ['config' => ['type' => 'select', 'renderType' => 'selectSingle', 'itemsProcFunc' => \TYPO3\CMS\Core\Tests\Functional\Domain\Persistence\Fixtures\SelectItemsHook::class . '->position']],
        'title' => ['config' => ['type' => 'input']],
        'body' => ['config' => ['type' => 'text', 'nullable' => true]],
        'amount' => ['config' => ['type' => 'number']],
        'email' => ['config' => ['type' => 'email']],
        'link' => ['config' => ['type' => 'link']],
        'flags' => ['config' => ['type' => 'check', 'items' => [['label' => 'A'], ['label' => 'B']]]],
        'syncable' => ['config' => ['type' => 'input', 'behaviour' => ['allowLanguageSynchronization' => true]]],
        'synchronized' => ['l10n_mode' => 'exclude', 'config' => ['type' => 'input']],
        'payload' => ['config' => ['type' => 'json', 'nullable' => true]],
        'hidden' => ['config' => ['type' => 'check']],
        'starttime' => ['config' => ['type' => 'datetime']],
        'readonly_value' => ['config' => ['type' => 'input', 'readOnly' => true]],
        'restricted' => ['exclude' => true, 'config' => ['type' => 'input']],
        'parent' => ['config' => ['type' => 'passthrough']],
        'children' => ['config' => ['type' => 'inline', 'foreign_table' => 'tx_recordwrite_item', 'foreign_field' => 'parent', 'foreign_sortby' => 'sorting']],
        'matched_children' => ['config' => ['type' => 'inline', 'foreign_table' => 'tx_recordwrite_item', 'foreign_field' => 'parent', 'foreign_match_fields' => ['amount' => 1]]],
        'related' => ['config' => ['type' => 'select', 'renderType' => 'selectMultipleSideBySide', 'foreign_table' => 'tx_recordwrite_item', 'MM' => 'tx_recordwrite_item_mm']],
        'dynamic_reference' => ['config' => ['type' => 'select', 'renderType' => 'selectSingle', 'foreign_table' => 'tx_recordwrite_item', 'itemsProcFunc' => \TYPO3\CMS\Core\Tests\Functional\Domain\Persistence\Fixtures\SelectItemsHook::class . '->populate']],
        'references' => ['config' => ['type' => 'group', 'allowed' => 'tx_recordwrite_item', 'maxitems' => 100]],
        'selected' => ['config' => ['type' => 'select', 'renderType' => 'selectMultipleSideBySide', 'foreign_table' => 'tx_recordwrite_item', 'maxitems' => 100]],
        'reference' => ['config' => ['type' => 'group', 'allowed' => 'tx_recordwrite_item', 'maxitems' => 1]],
    ],
    'types' => ['0' => ['showitem' => 'placement,title,body,amount,email,link,flags,syncable,synchronized,payload,hidden,starttime,readonly_value,restricted,parent,children,matched_children,related,dynamic_reference,references,selected,reference']],
];
