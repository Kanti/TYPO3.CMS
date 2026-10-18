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
use TYPO3\CMS\Core\Domain\Persistence\RecordWriterInterface;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Mvc\View\JsonView;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;
use TYPO3Tests\RecordWriteExample\Domain\Repository\RegistrationRepository;

final class InteroperabilityController extends ActionController
{
    protected ?string $defaultViewObjectName = JsonView::class;

    public function __construct(
        private readonly RecordWriterInterface $writer,
        private readonly RegistrationRepository $registrations,
        private readonly PersistenceManagerInterface $persistence,
    ) {}

    public function renameAction(int $uid, string $title): ResponseInterface
    {
        if (!ApplicationType::fromRequest($this->request)->isBackend()) {
            return $this->jsonResponse('{"error":"Backend request required"}')->withStatus(403);
        }
        $draft = $this->writer->editByUid('tx_recordwrite_item', $uid);
        $entity = $this->registrations->findByUid($uid);
        if ($entity === null) {
            return $this->jsonResponse('{"error":"Not found"}')->withStatus(404);
        }
        $before = $entity->getTitle();
        $record = $this->writer->save($draft->set('title', $title));
        $stale = $entity->getTitle();
        // This example has no pending Extbase changes. clearState() would discard such changes.
        $this->persistence->clearState();
        $reloaded = $this->registrations->findByUid($uid);
        return $this->jsonResponse(json_encode(['before' => $before, 'stale' => $stale, 'reloaded' => $reloaded?->getTitle(), 'record' => $record->get('title')], JSON_THROW_ON_ERROR));
    }
}
