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

namespace TYPO3\CMS\Core\Tests\Unit\Domain\Persistence;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Domain\Persistence\DataHandlerRecordWriter;
use TYPO3\CMS\Core\Domain\Persistence\Exception\InvalidRecordValueException;
use TYPO3\CMS\Core\Domain\Persistence\Exception\UnsupportedRecordWriteException;
use TYPO3\CMS\Core\Domain\Persistence\RecordFieldNormalizer;
use TYPO3\CMS\Core\Domain\Persistence\RecordRelationResolver;
use TYPO3\CMS\Core\Domain\Persistence\RecordWriteTargetResolver;
use TYPO3\CMS\Core\LinkHandling\TypoLinkCodecService;
use TYPO3\CMS\Core\Schema\Field\CheckboxFieldType;
use TYPO3\CMS\Core\Schema\Field\FieldTypeInterface;
use TYPO3\CMS\Core\Schema\Field\InputFieldType;
use TYPO3\CMS\Core\Schema\Field\JsonFieldType;
use TYPO3\CMS\Core\Schema\Field\NumberFieldType;
use TYPO3\CMS\Core\Schema\Field\RadioFieldType;
use TYPO3\CMS\Core\Schema\Field\StaticSelectFieldType;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class RecordFieldNormalizerTest extends UnitTestCase
{
    private function normalizer(): RecordFieldNormalizer
    {
        return new RecordFieldNormalizer(
            new TypoLinkCodecService($this->createMock(EventDispatcherInterface::class)),
            new RecordRelationResolver(new RecordWriteTargetResolver(new ConnectionPool(), $this->createMock(TcaSchemaFactory::class)), new DataHandlerRecordWriter()),
            new DataHandlerRecordWriter()
        );
    }

    public static function scalarValues(): iterable
    {
        yield [new InputFieldType('title', []), 'Text', 'Text'];
        yield [new InputFieldType('title', ['nullable' => true]), null, null];
        yield [new CheckboxFieldType('hidden', []), true, 1];
        yield [new NumberFieldType('amount', []), 42, 42];
        yield [new JsonFieldType('payload', []), ['label' => 'value'], '{"label":"value"}'];
        yield [new JsonFieldType('payload', []), 'scalar string', '"scalar string"'];
        yield [new CheckboxFieldType('flags', ['items' => [['label' => 'A'], ['label' => 'B']]]), 3, 3];
        yield [new StaticSelectFieldType('choices', ['maxitems' => 0, 'items' => [['value' => 'a'], ['value' => 'b']]]), ['a', 'b'], 'a,b'];
        yield [new RadioFieldType('choice', ['items' => [['value' => 0], ['value' => 1]]]), 0, '0'];
    }

    #[Test]
    #[DataProvider('scalarValues')]
    public function scalarValuesAreNormalized(FieldTypeInterface $field, mixed $input, mixed $expected): void
    {
        self::assertSame($expected, $this->normalizer()->normalize($field, $input));
    }

    public static function invalidValues(): iterable
    {
        yield 'required nullable' => [new InputFieldType('title', ['nullable' => true, 'required' => true]), null];
        yield 'bitmask overflow' => [new CheckboxFieldType('flags', ['items' => [['label' => 'A'], ['label' => 'B']]]), 4];
        yield 'range violation' => [new NumberFieldType('amount', ['range' => ['lower' => 1, 'upper' => 10]]), 11];
        yield 'infinite decimal' => [new NumberFieldType('amount', ['format' => 'decimal']), INF];
        yield 'invalid static item' => [new StaticSelectFieldType('choices', ['items' => [['value' => 'a']]]), 'b'];
        yield 'radio multiple' => [new RadioFieldType('choice', []), [0, 1]];
        yield 'too many choices' => [new StaticSelectFieldType('choices', ['maxitems' => 1]), ['a', 'b']];
        yield 'too few choices' => [new StaticSelectFieldType('choices', ['minitems' => 1]), []];
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function invalidValuesAreRejected(FieldTypeInterface $field, mixed $input): void
    {
        $this->expectException(InvalidRecordValueException::class);
        $this->normalizer()->normalize($field, $input);
    }

    #[Test]
    public function dynamicItemsRequireTheCompleteDraftContext(): void
    {
        $this->expectException(UnsupportedRecordWriteException::class);
        $this->normalizer()->normalize(new StaticSelectFieldType('choices', ['itemsProcFunc' => 'Vendor\\Items->populate']), 'value');
    }

    #[Test]
    public function nullCannotBeUsedToEraseANonNullableField(): void
    {
        $this->expectException(InvalidRecordValueException::class);
        $this->normalizer()->normalize(new InputFieldType('title', []), null);
    }

    #[Test]
    public function booleanDoesNotRepresentAMultiCheckboxBitmask(): void
    {
        $this->expectException(InvalidRecordValueException::class);
        $this->normalizer()->normalize(new CheckboxFieldType('flags', ['items' => [['label' => 'A'], ['label' => 'B']]]), true);
    }
}
