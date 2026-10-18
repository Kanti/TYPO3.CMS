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
use TYPO3\CMS\Core\Crypto\HashAlgo;
use TYPO3\CMS\Core\Domain\Persistence\RecordWriterInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Mvc\View\JsonView;
use TYPO3Tests\RecordWriteExample\Service\RegistrationSummary;

final class RegistrationController extends ActionController
{
    protected ?string $defaultViewObjectName = JsonView::class;

    public function __construct(private readonly RecordWriterInterface $writer, private readonly RegistrationSummary $summary) {}

    public function registerAction(string $name, string $email, string $invitation): ResponseInterface
    {
        if ($this->request->getMethod() !== 'POST') {
            return $this->jsonResponse('{"error":"POST required"}')->withStatus(405);
        }
        $name = trim($name);
        $email = strtolower(trim($email));
        if ($name === '' || mb_strlen($name) > 80 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->jsonResponse('{"error":"Invalid registration"}')->withStatus(400);
        }
        // The signed invitation authorizes this email; neither a BE nor FE login grants it.
        if (!$this->hashService->validateHmac($email, 'record-write-registration', $invitation, HashAlgo::SHA256)) {
            return $this->jsonResponse('{"error":"Invitation required"}')->withStatus(403);
        }
        // The application chooses the storage page and writable fields, never request data.
        $draft = $this->writer->create('tx_recordwrite_item', 1)->setValues(['title' => $name, 'email' => $email]);
        $record = $this->writer->save($draft);
        if ($record === null) {
            return $this->jsonResponse('{"error":"Registration result unavailable; contact support before retrying"}')->withStatus(409);
        }
        return $this->jsonResponse(json_encode(['uid' => $record->getUid(), 'name' => $this->summary->title($record)], JSON_THROW_ON_ERROR))->withStatus(201);
    }
}
