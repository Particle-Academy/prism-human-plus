<?php

declare(strict_types=1);

use Prism\HumanPlus\Data\SurfaceChanges;
use Prism\HumanPlus\Enums\ChangeFeed;

/**
 * The cross-language change-feed corpus from `prism-parity`.
 *
 * This package is the REFERENCE, so this file proves the corpus has not drifted
 * from the code it was generated against — which is what makes the ports'
 * assertions mean anything.
 *
 * A Human+ surface is SHARED. A person edits the same canvas a PHP application
 * and a TypeScript or Python agent are editing, and each agent decides from
 * this answer whether to re-read before writing. If one language reports an
 * unanswerable feed as "nothing changed", the agent in that language reverts
 * the person's edit believing it is fixing drift — and nothing errors, in any
 * layer, ever.
 */

/** @return array<int, array<string, mixed>> */
function changeFeedCorpus(): array
{
    /** @var array{cases: array<int, array<string, mixed>>} $document */
    $document = json_decode(
        (string) file_get_contents(__DIR__.'/../fixtures/human-plus-change-feed.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    return $document['cases'];
}

/**
 * @param  array<string, mixed>  $case
 * @return array<string, mixed>
 */
function answerFromCorpus(array $case): array
{
    // Parsed HERE, from the corpus's raw JSON text. Carrying the result decoded
    // in the case file is the defect `human-plus-tool-admission` documents:
    // writing it back through this decoder would turn `1.0` into `1` and `true`
    // into something a number check accepts, and half these rows exist because
    // a value's TYPE is what is under test.
    /** @var array<string, mixed> $result */
    $result = json_decode((string) $case['input']['result'], true, 512, JSON_THROW_ON_ERROR);

    return SurfaceChanges::readFrom($result, ChangeFeed::from((string) $case['input']['feed']))->toArray();
}

it('is the whole suite, not a subset someone trimmed to green', function (): void {
    expect(changeFeedCorpus())->toHaveCount(23);
});

it('answers every case exactly as the corpus records for the reference', function (array $case): void {
    $produced = json_encode(answerFromCorpus($case), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    expect($produced)->toBe($case['rows']['php']);
})->with(fn (): array => collect(changeFeedCorpus())
    ->mapWithKeys(fn (array $case): array => [$case['id'].' — '.$case['title'] => [$case]])
    ->all());

it('agrees with both ports on every case', function (): void {
    foreach (changeFeedCorpus() as $case) {
        expect([$case['rows']['ts'], $case['rows']['py']])
            ->toBe([$case['rows']['php'], $case['rows']['php']], $case['id']);
        expect($case['agrees'])->toBeTrue($case['id']);
    }
});

it('still cannot tell an unanswerable feed from a quiet one BY THE LIST ALONE', function (): void {
    // The property the suite exists for, asserted here rather than inferred
    // from agreement: hpc-0009 and hpc-0010 differ only in the feed state, and
    // both carry an empty `changes`. A reader that looked at the list would
    // call them the same answer.
    $cases = [];

    foreach (changeFeedCorpus() as $case) {
        $cases[$case['id']] = json_decode($case['rows']['php'], true, 512, JSON_THROW_ON_ERROR);
    }

    expect($cases['hpc-0009']['changes'])->toBe($cases['hpc-0010']['changes'])
        ->and($cases['hpc-0009']['nothing_changed'])->toBeTrue()
        ->and($cases['hpc-0010']['nothing_changed'])->toBeFalse();
});
