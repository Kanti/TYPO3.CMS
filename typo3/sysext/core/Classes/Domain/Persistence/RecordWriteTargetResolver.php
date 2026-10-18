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

namespace TYPO3\CMS\Core\Domain\Persistence;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Domain\Persistence\Exception\InvalidWriteContextException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\RecordNotFoundException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\UnsupportedRecordWriteException;
use TYPO3\CMS\Core\Domain\RecordInterface;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/** @internal Backend reads without frontend language fallback or enable-column filtering. */
final readonly class RecordWriteTargetResolver
{
    public function __construct(private ConnectionPool $connectionPool, private TcaSchemaFactory $schemaFactory) {}

    public function schema(string $table, RecordWriteContext $context): TcaSchema
    {
        if (!$this->schemaFactory->has($table)) {
            throw new UnsupportedRecordWriteException('Unknown TCA table "' . $table . '".', 1792305007);
        }
        $schema = $this->schemaFactory->get($table);
        if ($context->workspaceId !== 0 && $schema->isWorkspaceAware() && !ExtensionManagementUtility::isLoaded('workspaces')) {
            throw new InvalidWriteContextException('Workspace overlays require the workspace infrastructure.', 1792305008);
        }
        return $schema;
    }

    public function byUid(string $table, int $uid, RecordWriteContext $context, bool $includeDeleted = false): RecordWriteTarget
    {
        $schema = $this->schema($table, $context);
        $row = $this->row($table, $uid);
        if (!$row) {
            throw new RecordNotFoundException('Record not found: ' . $table . ':' . $uid, 1792305010);
        }
        $workspace = (int)($row['t3ver_wsid'] ?? 0);
        if ($workspace !== 0 && $workspace !== $context->workspaceId) {
            throw new InvalidWriteContextException('The physical record belongs to another workspace.', 1792305011);
        }
        $logicalUid = (int)($row['t3ver_oid'] ?? 0) ?: $uid;
        if ($context->workspaceId > 0 && $schema->isWorkspaceAware()) {
            $version = BackendUtility::getWorkspaceVersionOfRecord($context->workspaceId, $table, $logicalUid);
            if ($version) {
                $row = $version;
            }
        }
        $deletedField = $schema->hasCapability(TcaSchemaCapability::SoftDelete)
            ? $schema->getCapability(TcaSchemaCapability::SoftDelete)->getFieldName() : null;
        if (!$includeDeleted && (($deletedField !== null && !empty($row[$deletedField])) || (int)($row['t3ver_state'] ?? 0) === 2)) {
            throw new RecordNotFoundException('The record is deleted in the selected workspace.', 1792305012);
        }
        $languageId = $schema->isLanguageAware()
            ? (int)$row[$schema->getCapability(TcaSchemaCapability::Language)->getLanguageField()->getName()] : 0;
        return new RecordWriteTarget($table, $logicalUid, $row, $languageId);
    }

    public function fromRecord(RecordInterface $record, RecordWriteContext $context): RecordWriteTarget
    {
        $raw = $record->getRawRecord();
        if ($raw === null) {
            throw new InvalidWriteContextException('The record has no raw identity. Use editByUid() with an explicit UID.', 1792305014);
        }
        if ($raw->getMainType() !== $record->getMainType() || $raw->getUid() !== $record->getUid()) {
            throw new InvalidWriteContextException('Record and raw record identities disagree.', 1792305065);
        }
        $computed = $raw->getComputedProperties();
        $schema = $this->schema($record->getMainType(), $context);
        if ($schema->isWorkspaceAware() && (!$raw->has('t3ver_wsid') || !$raw->has('t3ver_oid'))) {
            throw new InvalidWriteContextException('The record lacks workspace identity fields.', 1792305015);
        }
        $languageField = $schema->isLanguageAware() ? $schema->getCapability(TcaSchemaCapability::Language)->getLanguageField()->getName() : null;
        if ($languageField !== null && (!$raw->has($languageField)
            || ($computed->getRequestedOverlayLanguageId() !== null && $computed->getRequestedOverlayLanguageId() !== (int)$raw->get($languageField)))) {
            throw new InvalidWriteContextException('A language fallback or incomplete language identity cannot be written.', 1792305016);
        }
        if ($raw->has('t3ver_wsid') && (int)$raw->get('t3ver_wsid') !== 0 && (int)$raw->get('t3ver_wsid') !== $context->workspaceId) {
            throw new InvalidWriteContextException('The record was read in another workspace.', 1792305017);
        }
        $uid = $computed->getLocalizedUid() ?? $computed->getVersionedUid() ?? $raw->getUid();
        $target = $this->byUid($record->getMainType(), $uid, $context);
        if ($languageField !== null && $target->languageId !== (int)$raw->get($languageField)) {
            throw new InvalidWriteContextException('The overlay UID does not identify the record language.', 1792305018);
        }
        if ($computed->getVersionedUid() !== null && $computed->getLocalizedUid() === null
            && $target->uid !== $raw->getUid() && (int)$target->row['uid'] !== $raw->getUid()) {
            throw new InvalidWriteContextException('The workspace overlay does not identify the logical record.', 1792305066);
        }
        if ($computed->getLocalizedUid() !== null && $schema->isLanguageAware()) {
            $originField = $schema->getCapability(TcaSchemaCapability::Language)->getTranslationOriginPointerField()->getName();
            if (!in_array($raw->getUid(), [$target->uid, (int)$target->row['uid'], (int)($target->row[$originField] ?? 0)], true)) {
                throw new InvalidWriteContextException('The localization overlay does not belong to the logical record.', 1792305067);
            }
        }
        return $target;
    }

    public function row(string $table, int $uid): ?array
    {
        $query = $this->connectionPool->getQueryBuilderForTable($table);
        $query->getRestrictions()->removeAll();
        $row = $query->select('*')->from($table)
            ->where($query->expr()->eq('uid', $query->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()->fetchAssociative();
        return $row ?: null;
    }
}
