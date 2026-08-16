<?php declare(strict_types=1);

namespace PhpFuzzer;

use PHPUnit\Framework\TestCase;

final class CorpusTest extends TestCase {
    public function testDuplicateInputMergesCoverageWithoutGrowingCorpus(): void {
        $corpus = new Corpus();

        $first = new CorpusEntry('same-seed', [10 => true, 20 => true], null);
        $corpus->computeUniqueFeatures($first);
        self::assertTrue($corpus->addEntry($first));

        $duplicate = new CorpusEntry('same-seed', [10 => true, 20 => true, 30 => true], null);
        $corpus->computeUniqueFeatures($duplicate);
        self::assertSame([30 => true], $duplicate->uniqueFeatures);
        self::assertFalse($corpus->addEntry($duplicate));

        self::assertSame(1, $corpus->getNumCorpusEntries());
        self::assertSame(3, $corpus->getNumFeatures());
        self::assertSame(\strlen('same-seed'), $corpus->getTotalLen());
        self::assertSame([], $first->features);
        self::assertSame(
            [10 => true, 20 => true, 30 => true],
            $corpus->getEntryByHash($first->hash)?->uniqueFeatures
        );

        $index = new \ReflectionProperty($corpus, 'entriesByIndex');
        self::assertCount(1, $index->getValue($corpus));
    }

    public function testReplacementStoresOnlyUniqueFeatureOwnership(): void {
        $corpus = new Corpus();
        $original = new CorpusEntry('long-seed', [1 => true, 2 => true], null);
        $corpus->computeUniqueFeatures($original);
        $corpus->addEntry($original);

        $replacement = new CorpusEntry('short', [1 => true, 2 => true], null);
        self::assertTrue($replacement->hasAllUniqueFeaturesOf($original));
        $replacement->uniqueFeatures = $original->uniqueFeatures;

        self::assertTrue($corpus->replaceEntry($original, $replacement));
        self::assertSame([], $replacement->features);
        self::assertSame(1, $corpus->getNumCorpusEntries());
        self::assertSame(\strlen('short'), $corpus->getTotalLen());
    }
}
