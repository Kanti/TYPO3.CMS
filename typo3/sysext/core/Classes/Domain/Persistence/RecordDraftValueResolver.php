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

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\DataHandling\RecordFieldTransformer;
use TYPO3\CMS\Core\Domain\DateTimeFactory;
use TYPO3\CMS\Core\Domain\Persistence\Exception\InvalidRecordValueException;
use TYPO3\CMS\Core\Domain\RawRecord;
use TYPO3\CMS\Core\Domain\Record\ComputedProperties;
use TYPO3\CMS\Core\Domain\RecordPropertyClosure;
use TYPO3\CMS\Core\LinkHandling\TypoLinkCodecService;
use TYPO3\CMS\Core\LinkHandling\TypolinkParameter;
use TYPO3\CMS\Core\Schema\Field\DateTimeFieldType;
use TYPO3\CMS\Core\Schema\Field\RelationalFieldTypeInterface;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/** @internal Consistent write-side PHP values, with raw storage retained only in the target. */
final readonly class RecordDraftValueResolver
{
    public function __construct(
        private TcaSchemaFactory $schemas,
        private RecordFieldTransformer $transformer,
        private RecordRelationResolver $relations,
        private TypoLinkCodecService $links,
    ) {}

    public function get(RecordDraft $draft, string $name, mixed $value, bool $pending): mixed
    {
        $draft->context->assertCurrent(GeneralUtility::makeInstance(Context::class));
        return $draft->context->run(function () use ($draft, $name, $value, $pending) {
            $schema = $this->schemas->get($draft->table);
            if (!$schema->hasField($name)) {
                if (in_array($name, ['uid', 'pid'], true) && !$pending) {
                    return (int)$value;
                }
                throw new InvalidRecordValueException('Unknown draft field "' . $name . '".', 1792310011);
            }
            if ($schema->supportsSubSchema() && !$schema->getSubSchemaTypeInformation()->isPointerToForeignFieldInForeignSchema()) {
                $typeField = $schema->getSubSchemaTypeInformation()->getFieldName();
                $type = $draft->getChanges()[$typeField] ?? $draft->target?->row[$typeField] ?? '';
                $type = is_bool($type) ? (string)(int)$type : (is_scalar($type) ? (string)$type : '');
                if ($schema->hasSubSchema($type) && $schema->getSubSchema($type)->hasField($name)) {
                    $schema = $schema->getSubSchema($type);
                }
            }
            $field = $schema->getField($name);
            if ($field instanceof RelationalFieldTypeInterface) {
                $references = $pending ? ($value instanceof RecordRelationSet ? $value->toArray() : $value) : $this->relations->load($draft, $name);
                if (!is_array($references)) {
                    throw new InvalidRecordValueException('A relation value must be a list.', 1792310013);
                }
                return array_map(fn($reference) => $reference instanceof RecordDraft && $reference->target === null ? $reference : $this->relations->reference($reference, $draft->context), $references);
            }
            if ($value === null) {
                return null;
            }
            if ($field->getType() === 'check') {
                return count($field->getConfiguration()['items'] ?? []) <= 1 ? (bool)$value : (int)$value;
            }
            if ($pending) {
                if ($field instanceof DateTimeFieldType) {
                    return $value instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($value) : DateTimeFactory::createFromDatabaseValue($value, $field);
                }
                if ($field->getType() === 'link') {
                    return $value instanceof TypolinkParameter ? $value : TypolinkParameter::createFromTypolinkParts($this->links->decode($value));
                }
                return $value;
            }
            $row = $draft->target->row;
            $raw = new RawRecord((int)$row['uid'], (int)$row['pid'], $row, new ComputedProperties(), $draft->table);
            $resolved = $this->transformer->transformField($field, $raw, $draft->context->readingContext(), new RecordIdentityMap());
            return $resolved instanceof RecordPropertyClosure ? $resolved->instantiate() : $resolved;
        });
    }
}
