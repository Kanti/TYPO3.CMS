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
use TYPO3\CMS\Core\Domain\Persistence\Exception\RecordAccessDeniedException;
use TYPO3\CMS\Core\Domain\Persistence\RecordWriterInterface;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Mvc\View\JsonView;

final class BackendController extends ActionController
{
    protected ?string $defaultViewObjectName = JsonView::class;

    public function __construct(private readonly RecordWriterInterface $writer) {}

    public function updateAction(int $uid, string $title): ResponseInterface
    {
        if (!ApplicationType::fromRequest($this->request)->isBackend()) {
            return $this->jsonResponse('{"error":"Backend request required"}')->withStatus(403);
        }
        if (trim($title) === '') {
            return $this->jsonResponse('{"error":"Title required"}')->withStatus(400);
        }
        try {
            $draft = $this->writer->editByUid('tx_recordwrite_item', $uid)->set('title', $title);
            $record = $this->writer->save($draft);
            $identity = $this->writer->identity($record);
        } catch (RecordAccessDeniedException) {
            return $this->jsonResponse('{"error":"Access denied"}')->withStatus(403);
        }
        return $this->jsonResponse(json_encode(['uid' => $identity->logicalUid, 'physicalUid' => $identity->physicalUid, 'workspace' => $identity->workspaceId], JSON_THROW_ON_ERROR));
    }
}
