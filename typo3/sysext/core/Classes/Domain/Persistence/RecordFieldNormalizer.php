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

use TYPO3\CMS\Core\DataHandling\Localization\State;
use TYPO3\CMS\Core\Domain\Persistence\Exception\InvalidRecordValueException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\InvalidWriteContextException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\RecordAccessDeniedException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\UnsupportedRecordWriteException;
use TYPO3\CMS\Core\LinkHandling\TypoLinkCodecService;
use TYPO3\CMS\Core\LinkHandling\TypolinkParameter;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\Field\FieldTranslationBehaviour;
use TYPO3\CMS\Core\Schema\Field\FieldTypeInterface;
use TYPO3\CMS\Core\Schema\Field\RelationalFieldTypeInterface;
use TYPO3\CMS\Core\Schema\TcaSchema;

/** @internal Converts write-side values; does not reverse the read transformer. */
final readonly class RecordFieldNormalizer
{
    public function __construct(private TypoLinkCodecService $linkCodec, private RecordRelationResolver $relations, private DataHandlerRecordWriter $adapter, private ?RecordSelectItemResolver $selectItems = null) {}

    public function changes(RecordDraft $draft, TcaSchema $schema, array $identifiers = [], ?RecordWriteTarget $currentTarget = null, ?array $changes = null): array
    {
        if ($schema->hasCapability(TcaSchemaCapability::AccessReadOnly)) {
            throw new UnsupportedRecordWriteException('The table is read-only.', 1792305009);
        }
        if ($draft->context->workspaceId !== 0 && (!$schema->isWorkspaceAware() || !empty($schema->getRawConfiguration()['versioningWS_alwaysAllowLiveEdit']))) {
            throw new InvalidWriteContextException('Writing a non-versioned table or an always-Live table in a workspace is not supported.', 1792330004);
        }
        $changes ??= $draft->getChanges();
        $currentTarget ??= $draft->target;
        $effectiveSchema = $schema;
        $typeField = null;
        if ($schema->supportsSubSchema()) {
            $type = $schema->getSubSchemaTypeInformation();
            if ($type->isPointerToForeignFieldInForeignSchema()) {
                throw new UnsupportedRecordWriteException('Foreign record type discriminators are not supported for writing.', 1792305019);
            }
            $typeField = $type->getFieldName();
            $typeValue = $changes[$typeField] ?? $currentTarget?->row[$typeField] ?? $this->adapter->defaults($draft)[$typeField] ?? $schema->getField($typeField)->getDefaultValue() ?? '0';
            if (is_bool($typeValue) && $schema->getField($typeField)->getType() === 'check') {
                $typeValue = (int)$typeValue;
            }
            if (!is_int($typeValue) && !is_string($typeValue)) {
                throw new InvalidRecordValueException('A record type must be a valid discriminator value.', 1792310035);
            }
            $typeValue = (string)$typeValue;
            if (!$schema->hasSubSchema($typeValue)) {
                throw new InvalidRecordValueException('Unknown record type "' . $typeValue . '".', 1792305020);
            }
            $effectiveSchema = $schema->getSubSchema($typeValue);
        }
        $protected = ['uid', 'pid', 'l10n_state'];
        $ctrl = $schema->getRawConfiguration();
        foreach (['tstamp', 'crdate', 'delete', 'sortby', 'origUid', 'languageField', 'transOrigPointerField', 'translationSource', 'transOrigDiffSourceField'] as $key) {
            if (!empty($ctrl[$key])) {
                $protected[] = $ctrl[$key];
            }
        }
        $result = [];
        foreach ($changes as $name => $value) {
            if (in_array($name, $protected, true) || str_starts_with($name, 't3ver_')) {
                throw new InvalidRecordValueException('Protected system field "' . $name . '" requires a semantic operation.', 1792305021);
            }
            $enableField = in_array($name, $ctrl['enablecolumns'] ?? [], true);
            if (!$schema->hasField($name) || (!$effectiveSchema->hasField($name) && $name !== $typeField && !$enableField)) {
                throw new InvalidRecordValueException('Unknown field "' . $name . '" for the effective record type.', 1792305022);
            }
            $field = $effectiveSchema->hasField($name) ? $effectiveSchema->getField($name) : $schema->getField($name);
            $conf = $field->getConfiguration();
            if (($conf['readOnly'] ?? false) || in_array($field->getType(), ['none', 'passthrough'], true)) {
                throw new InvalidRecordValueException('Field "' . $name . '" is not editable.', 1792305023);
            }
            if ($field->supportsAccessControl() && !$draft->context->user->isAdmin() && !$draft->context->user->check('non_exclude_fields', $draft->table . ':' . $name)) {
                throw new RecordAccessDeniedException('The backend user cannot write excluded field "' . $name . '".', 1792305024);
            }
            $target = $currentTarget;
            if ($target !== null && $target->languageId > 0) {
                $state = State::fromJSON($draft->table, $target->row['l10n_state'] ?? null);
                if ($field->getTranslationBehaviour() === FieldTranslationBehaviour::Excluded || in_array($state?->getState($name), ['parent', 'source'], true)) {
                    throw new InvalidRecordValueException('Field "' . $name . '" is synchronized with another language.', 1792305025);
                }
            }
            $items = null;
            if (in_array($field->getType(), ['select', 'radio'], true) && (!empty($conf['itemsProcFunc']) || !empty($conf['itemsProcessors']))) {
                if ($field instanceof RelationalFieldTypeInterface) {
                    throw new UnsupportedRecordWriteException('Dynamic foreign-record item filters are not supported for relation writes.', 1792310036);
                }
                $items = $this->selectItems?->items($field, $draft, $currentTarget);
            }
            $result[$name] = $field instanceof RelationalFieldTypeInterface ? $this->relations->encode($field, $value, $draft, $identifiers, $currentTarget) : $this->normalize($field, $value, $items);
        }
        return $result;
    }

    public function normalize(FieldTypeInterface $field, mixed $value, ?array $items = null): mixed
    {
        if ($value === null) {
            if (!$field->isNullable() || $field->isRequired()) {
                throw new InvalidRecordValueException('Field "' . $field->getName() . '" does not accept null.', 1792305026);
            }
            return null;
        }
        if ($field instanceof RelationalFieldTypeInterface) {
            throw new UnsupportedRecordWriteException('Use the explicit relation API for relational fields.', 1792305027);
        }
        $conf = $field->getConfiguration();
        $valid = match ($field->getType()) {
            'input', 'text', 'color', 'slug', 'password', 'email' => is_string($value),
            'number' => ($conf['format'] ?? 'integer') === 'decimal' ? is_int($value) || (is_float($value) && is_finite($value)) : is_int($value),
            'check' => is_int($value) && $value >= 0 || is_bool($value) && count($conf['items'] ?? []) <= 1,
            'datetime' => is_int($value) || $value instanceof \DateTimeInterface,
            'link' => is_string($value) || $value instanceof TypolinkParameter,
            'json' => is_array($value) || is_string($value) || is_int($value) || is_float($value) || is_bool($value),
            'select', 'radio' => is_int($value) || is_string($value) || is_array($value),
            default => false,
        };
        if (!$valid) {
            throw new InvalidRecordValueException('Unsupported value for "' . $field->getName() . '" (' . $field->getType() . ').', 1792305028);
        }
        if ($field->isRequired() && ($value === '' || $value === [])) {
            throw new InvalidRecordValueException('Required field "' . $field->getName() . '" must not be empty.', 1792305029);
        }
        if ($field->getType() === 'email' && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidRecordValueException('Invalid email address.', 1792305030);
        }
        if ($field->getType() === 'json') {
            try {
                return json_encode($value, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new InvalidRecordValueException('Invalid JSON value.', 1792305031, $exception);
            }
        }
        if ($value instanceof TypolinkParameter) {
            return $this->linkCodec->encode($value->jsonSerialize());
        }
        if ($field->getType() === 'check') {
            $bits = max(1, count($conf['items'] ?? []));
            if ($bits < PHP_INT_SIZE * 8 - 1 && (int)$value > (1 << $bits) - 1) {
                throw new InvalidRecordValueException('Checkbox value exceeds the configured bitmask.', 1792305062);
            }
            return (int)$value;
        }
        if ($field->getType() === 'number') {
            if ((isset($conf['range']['lower']) && $value < $conf['range']['lower']) || (isset($conf['range']['upper']) && $value > $conf['range']['upper'])) {
                throw new InvalidRecordValueException('Number is outside the configured range.', 1792305063);
            }
        }
        if (in_array($field->getType(), ['select', 'radio'], true)) {
            if ($items === null && (!empty($conf['itemsProcFunc']) || !empty($conf['itemsProcessors']))) {
                throw new UnsupportedRecordWriteException('Dynamic select/radio items require the complete draft context.', 1792305073);
            }
            $items ??= $conf['items'] ?? null;
            $values = is_array($value) ? $value : [$value];
            foreach ($values as $item) {
                if (!is_string($item) && !is_int($item)) {
                    throw new InvalidRecordValueException('Select values must be strings or integers.', 1792305032);
                }
                if ($items !== null && !in_array((string)$item, array_map(static fn(array $entry): string => (string)($entry['value'] ?? ''), array_filter($items, static fn(array $entry): bool => ($entry['value'] ?? '') !== '--div--')), true)) {
                    throw new InvalidRecordValueException('Select value is not among the configured items.', 1792305064);
                }
                if (str_contains((string)$item, ',')) {
                    throw new InvalidRecordValueException('Provide select values as a list, not a comma-separated string.', 1792305033);
                }
            }
            if ((($conf['renderType'] ?? '') === 'selectSingle' || $field->getType() === 'radio') && count($values) !== 1) {
                throw new InvalidRecordValueException('A single select requires exactly one value.', 1792305034);
            }
            $maximum = (int)($conf['maxitems'] ?? 0);
            if (count($values) < (int)($conf['minitems'] ?? 0) || ($maximum > 0 && count($values) > $maximum)) {
                throw new InvalidRecordValueException('Selection cardinality is outside the configured limits.', 1792305074);
            }
            return implode(',', $values);
        }
        return $value;
    }
}
