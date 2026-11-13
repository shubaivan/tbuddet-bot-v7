<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Service\Search\ProductSearchQuery;
use PHPUnit\Framework\TestCase;

class ProductSearchQueryTest extends TestCase
{
    /** @dataProvider wordForms */
    public function testUkrainianWordFormsShareAStem(string $word, string $expectedStem): void
    {
        self::assertSame($expectedStem, ProductSearchQuery::stem($word));
    }

    public static function wordForms(): iterable
    {
        yield 'base form' => ['вазон', 'вазон'];
        yield 'plural' => ['вазони', 'вазон'];
        yield 'genitive plural' => ['вазонів', 'вазон'];
        yield 'dative' => ['вазону', 'вазон'];
        yield 'instrumental plural' => ['вазонами', 'вазон'];
        yield 'feminine' => ['півсфера', 'півсфер'];
        yield 'feminine plural' => ['півсфери', 'півсфер'];
        yield 'lavka' => ['лавки', 'лавк'];
        yield 'short word kept' => ['куб', 'куб'];
        yield 'stem never shorter than 3' => ['урни', 'урн'];
        yield 'english plural' => ['planters', 'planter'];
        yield 'english -ches' => ['benches', 'bench'];
        yield 'english -ss kept' => ['glass', 'glass'];
        yield 'number kept' => ['500', '500'];
    }

    public function testBuildsPrefixQueries(): void
    {
        $q = new ProductSearchQuery('  Вазони   КУБ ');

        self::assertFalse($q->isEmpty());
        self::assertSame('вазон:* & куб:*', $q->toTsQueryAll());
        self::assertSame('вазон:* | куб:*', $q->toTsQueryAny());
        self::assertSame('вазони куб', $q->plain());
    }

    public function testPartialWordIsAPrefix(): void
    {
        self::assertSame('півсф:*', (new ProductSearchQuery('півсф'))->toTsQueryAll());
    }

    /** @dataProvider hostileInput */
    public function testPunctuationNeverReachesTsQuery(string $raw): void
    {
        $q = new ProductSearchQuery($raw);

        foreach ([$q->toTsQueryAll(), $q->toTsQueryAny()] as $tsQuery) {
            // Only letters, digits, the prefix marker and our own operators.
            self::assertMatchesRegularExpression('/^[\p{L}\p{N}:* &|]*$/u', $tsQuery);
        }
    }

    public static function hostileInput(): iterable
    {
        yield 'exclamation (was HTTP 500 on prod)' => ['вазон!'];
        yield 'tsquery operators' => ['вазон & | ! ( ) :* <->'];
        yield 'quotes and apostrophe' => ["п'єдестал \"куб\""];
        yield 'sql-ish' => ["'; DROP TABLE product; --"];
    }

    public function testEmptyAndSymbolOnlyInputIsEmpty(): void
    {
        self::assertTrue((new ProductSearchQuery(null))->isEmpty());
        self::assertTrue((new ProductSearchQuery('   '))->isEmpty());
        self::assertTrue((new ProductSearchQuery('!!! ? ,'))->isEmpty());
        self::assertSame('', (new ProductSearchQuery('!!!'))->toTsQueryAny());
    }

    public function testDuplicateFormsCollapseAndTermsAreCapped(): void
    {
        self::assertSame(['вазон'], (new ProductSearchQuery('вазон вазони вазонів'))->terms());
        self::assertCount(8, (new ProductSearchQuery('аа бб вв гг дд ее жж зз ии кк лл'))->terms());
    }
}
