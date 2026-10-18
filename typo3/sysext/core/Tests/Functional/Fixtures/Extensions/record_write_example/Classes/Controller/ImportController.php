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

namespace TYPO3Tests\RecordWriteExample\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Domain\Persistence\Exception\InvalidRecordValueException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\RecordPersistenceException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\RecordWriteException;
use TYPO3\CMS\Core\Domain\Persistence\RecordReference;
use TYPO3\CMS\Core\Domain\Persistence\RecordWriterInterface;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Mvc\View\JsonView;

final class ImportController extends ActionController
{
    protected ?string $defaultViewObjectName = JsonView::class;

    public function __construct(private readonly RecordWriterInterface $writer) {}

    public function importAction(array $rows): ResponseInterface
    {
        // This import endpoint is an administrator tool, not a public system-write endpoint.
        if (!ApplicationType::fromRequest($this->request)->isBackend() || !$GLOBALS['BE_USER']->isAdmin()) {
            return $this->jsonResponse('{"error":"Administrator required"}')->withStatus(403);
        }
        $batch = $this->writer->batch();
        $drafts = [];
        $results = [];
        foreach ($rows as $rowKey => $row) {
            try {
                if (!is_array($row) || !is_string($row['header'] ?? '') || !is_string($row['bodytext'] ?? '')) {
                    throw new InvalidRecordValueException('An import row requires text fields.', 1792310031);
                }
                foreach (['uid', 'pid', 'colPos'] as $field) {
                    $value = $row[$field] ?? 0;
                    if ((!is_int($value) && (!is_string($value) || !preg_match('/^-?[0-9]+$/D', $value))) || ($field !== 'colPos' && (int)$value < 0)) {
                        throw new InvalidRecordValueException('Invalid integer in import field ' . $field, 1792310032);
                    }
                }
                if (isset($row['categories']) && (!is_array($row['categories']) || !array_is_list($row['categories']))) {
                    throw new InvalidRecordValueException('Category IDs must be a list.', 1792310033);
                }
                foreach ($row['categories'] ?? [] as $category) {
                    if (filter_var($category, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                        throw new InvalidRecordValueException('Invalid category ID.', 1792310034);
                    }
                }
                $uid = (int)($row['uid'] ?? 0);
                $draft = $uid > 0 ? $batch->editByUid('tt_content', $uid) : $batch->create('tt_content', (int)($row['pid'] ?? 0));
                $drafts[$rowKey] = $draft;
                // Explicit input mapping prevents writes to UID/PID/system fields from imported data.
                $draft->setValues(['CType' => 'text', 'header' => (string)($row['header'] ?? ''), 'bodytext' => (string)($row['bodytext'] ?? ''), 'colPos' => (int)($row['colPos'] ?? 0)]);
                if (isset($row['categories'])) {
                    $draft->relations('categories')->setMembers(array_map(static fn($id): RecordReference => new RecordReference('sys_category', (int)$id), $row['categories']));
                }
            } catch (RecordWriteException $exception) {
                if (isset($drafts[$rowKey])) {
                    $batch->remove($drafts[$rowKey]);
                    unset($drafts[$rowKey]);
                }
                $results[$rowKey] = ['outcome' => 'notAttempted', 'error' => $exception->getMessage()];
            }
        }
        $validation = $batch->validate();
        foreach ($drafts as $rowKey => $draft) {
            $errors = $validation->forDraft($draft)->errors;
            if ($errors !== []) {
                $results[$rowKey] = ['outcome' => 'notAttempted', 'errors' => $errors];
                $batch->remove($draft);
                unset($drafts[$rowKey]);
            }
        }
        try {
            $diagnostics = $batch->execute()->diagnostics;
        } catch (RecordPersistenceException $exception) {
            $diagnostics = $exception->diagnostics;
        }
        foreach ($drafts as $rowKey => $draft) {
            $entry = $diagnostics?->forDraft($draft);
            $results[$rowKey] = ['outcome' => $entry?->outcome->value ?? 'unknown', 'uid' => $entry?->identity?->logicalUid, 'physicalUid' => $entry?->identity?->physicalUid, 'errors' => $entry->errors ?? []];
        }
        ksort($results);
        return $this->jsonResponse(json_encode(['rows' => $results], JSON_THROW_ON_ERROR));
    }
}
