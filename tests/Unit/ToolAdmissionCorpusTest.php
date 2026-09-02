<?php

declare(strict_types=1);

use Prism\HumanPlus\Data\ToolDefinition;
use Prism\HumanPlus\Exceptions\ToolRefused;
use Prism\HumanPlus\Security\TrustPolicy;

/**
 * The cross-language tool-admission corpus from `prism-parity`.
 *
 * This package is the REFERENCE, so this file proves the corpus has not
 * drifted from the code it was generated against — which is what makes the
 * ports' "I differ from the reference HERE and nowhere else" assertions mean
 * anything.
 *
 * A Human+ surface is SHARED. The same surface is driven by a PHP application
 * and by a TypeScript or Python agent, so a tool this package reserves for the
 * human has to be reserved in all three — a name refused here and callable
 * there is an agent approving its own proposals, and nothing errors to say so.
 */

/** @return array<int, array<string, mixed>> */
function toolAdmissionCorpus(): array
{
    /** @var array{cases: array<int, array<string, mixed>>} $document */
    $document = json_decode(
        (string) file_get_contents(__DIR__.'/../fixtures/human-plus-tool-admission.json'),
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
function admitFromCorpus(array $case): array
{
    // Parsed HERE, from the corpus's raw JSON text. Carrying it decoded is the
    // defect the corpus documents: this decoder maps `{}` and `[]` to the same
    // value, so a decoded corpus would have replaced adm-0016's empty MAP with
    // an empty LIST and the row would have stopped asking its question.
    $schema = json_decode($case['tool']['input_schema_json'], true, 512, JSON_THROW_ON_ERROR);

    $tool = new ToolDefinition($case['tool']['name'], $case['tool']['description'], $schema);
    $digest = $tool->digest();

    $pins = [];
    foreach ($case['policy']['pins'] ?? [] as $name => $pin) {
        $pins[$name] = $pin === '@digest' ? $digest : $pin;
    }

    $policy = match ($case['policy']['mode']) {
        'undeclared' => TrustPolicy::undeclared(),
        'everyTool' => TrustPolicy::everyTool($pins),
        default => TrustPolicy::allowing($case['policy']['tools'], $pins),
    };

    $declared = true;
    $message = null;

    try {
        $policy->assertDeclared();
    } catch (ToolRefused $e) {
        $declared = false;
        $message = $e->getMessage();
    }

    $admitted = true;

    try {
        $policy->assertAllows($tool);
    } catch (ToolRefused $e) {
        $admitted = false;
        $message ??= $e->getMessage();
    }

    return [
        'digest' => $digest,
        'declared' => $declared,
        'allows' => $policy->allows($tool->name),
        'admitted' => $admitted,
        'message' => $message,
    ];
}

it('is the whole suite, not a subset someone trimmed to green', function (): void {
    expect(toolAdmissionCorpus())->toHaveCount(32);
});

it('still decides each case the way the corpus recorded', function (array $case): void {
    expect(admitFromCorpus($case))->toBe($case['admission']['php']);
})->with(fn (): array => collect(toolAdmissionCorpus())
    ->mapWithKeys(fn (array $case): array => [$case['id'].' — '.$case['title'] => [$case]])
    ->all());

it('reserves confirmation for the human even under WILDCARD trust', function (): void {
    // The property the Lab probes live at /lab/team, asserted here in the
    // reference too. `everyTool` is the widest trust a caller can express, and
    // it must still not include the one tool an agent must never call — an
    // agent that can confirm approves its own proposals.
    $case = collect(toolAdmissionCorpus())->firstWhere('id', 'adm-0005');
    $decision = admitFromCorpus($case);

    expect($case['policy']['mode'])->toBe('everyTool')
        ->and($decision['allows'])->toBeFalse()
        ->and($decision['admitted'])->toBeFalse();
});

it('reserves a confirm name whatever INVISIBLE character trails it', function (): void {
    // G-33 and G-36, both CLOSED — and this is the test that keeps them closed.
    //
    // A tool name is chosen by the SURFACE, and `$` anchors at the end. Before
    // the name was normalised, `terminal_confirm ` — one trailing space — was
    // admitted to the agent HERE and in both ports, under every trust level
    // including the wildcard, with nothing raised anywhere. The same name with
    // a trailing NEWLINE was admitted in the TypeScript port only, because `$`
    // matches before a final newline in PCRE and Python and not in JavaScript.
    //
    // The normalisation strips an EXPLICIT codepoint set, spelled identically
    // in all three languages. That detail is the fix: the built-ins disagree
    // three ways — trim() here strips no Unicode whitespace at all, JavaScript's
    // strips every one of these including U+FEFF, and Python's strips them
    // except U+FEFF — so reaching for a built-in would have closed one hole and
    // opened three new divergences.
    $reserved = ['adm-0005', 'adm-0011', 'adm-0019', 'adm-0020', 'adm-0021', 'adm-0022', 'adm-0023', 'adm-0024', 'adm-0025'];

    foreach ($reserved as $id) {
        $case = collect(toolAdmissionCorpus())->firstWhere('id', $id);
        $decision = admitFromCorpus($case);

        expect($decision['allows'])->toBeFalse($id)
            ->and($decision['admitted'])->toBeFalse($id)
            // And every port agrees, which is the half a single-language suite
            // cannot check and the half that was actually broken.
            ->and($case['admission']['ts']['allows'])->toBeFalse($id)
            ->and($case['admission']['py']['allows'])->toBeFalse($id);
    }
});

it('still admits the names that merely LOOK like a reserved verb', function (): void {
    // The other half of a reservation, and the half a fix like this can break.
    // Normalising only ever reserves MORE names, so these are what proves it did
    // not over-reach: `confirmation_status` and `preconfirm` stay callable.
    foreach (['adm-0009', 'adm-0010'] as $id) {
        $case = collect(toolAdmissionCorpus())->firstWhere('id', $id);

        expect(admitFromCorpus($case)['admitted'])->toBeTrue($id)
            ->and($case['admission']['ts']['admitted'])->toBeTrue($id)
            ->and($case['admission']['py']['admitted'])->toBeTrue($id);
    }
});

it('REFUSES a name that is not well formed, in all three languages', function (): void {
    // The name rule, and the reason it exists beyond tidiness.
    //
    // adm-0026 is the one worth reading. A Cyrillic `с` in `сonfirm` does NOT
    // bypass the reservation — it genuinely is not `confirm`, so not reserving
    // it is correct — but a human reading an allowlist cannot tell it from the
    // real one. The hole is in the HUMAN's ability to audit the trust config,
    // which is the other half of the same trust model, and no amount of
    // pattern-matching on the reserved word closes it. An ASCII-only name does.
    //
    // adm-0028 carries a BEL byte: invisible in every log and allowlist someone
    // might review it in. The shell tooling used to build this corpus refuses
    // to carry that character at all, which is the argument in miniature.
    foreach (['adm-0026', 'adm-0027', 'adm-0028', 'adm-0029', 'adm-0030'] as $id) {
        $case = collect(toolAdmissionCorpus())->firstWhere('id', $id);

        expect(admitFromCorpus($case)['allows'])->toBeFalse($id)
            ->and(admitFromCorpus($case)['admitted'])->toBeFalse($id)
            ->and($case['admission']['ts']['allows'])->toBeFalse($id)
            ->and($case['admission']['py']['allows'])->toBeFalse($id);
    }
});

it('still ADMITS the namespaced and hyphenated names real surfaces use', function (): void {
    // The direction a name rule breaks things, and the reason this one is not
    // stricter. Dots, colons and hyphens are how surfaces namespace tools; a
    // rule that refused `vendor.tool` or `web-search` would be unusable and
    // would get removed, taking the homoglyph guard with it.
    foreach (['adm-0031', 'adm-0032'] as $id) {
        $case = collect(toolAdmissionCorpus())->firstWhere('id', $id);

        expect(admitFromCorpus($case)['admitted'])->toBeTrue($id)
            ->and($case['admission']['ts']['admitted'])->toBeTrue($id)
            ->and($case['admission']['py']['admitted'])->toBeTrue($id);
    }
});

it('digests a tool with NO schema differently from both ports', function (): void {
    // G-34, and this one the reference is on the wrong side of. An empty PHP
    // array encodes as `[]` and never `{}`, so a tool declared without a schema
    // — the DEFAULT, not an edge case — has a different pin here than in either
    // port. A pin computed against a PHP deployment therefore fails in a
    // TypeScript or Python app, and the failure reads as a tool definition
    // having changed when nothing changed at all.
    //
    // G-20's shape, third family. Pinned in the negative.
    $case = collect(toolAdmissionCorpus())->firstWhere('id', 'adm-0016');

    expect($case['tool']['input_schema_json'])->toBe('{}')
        ->and(admitFromCorpus($case)['digest'])->not->toBe($case['admission']['ts']['digest'])
        ->and($case['admission']['ts']['digest'])->toBe($case['admission']['py']['digest']);
});

it('agrees with BOTH ports on every reserved-name row', function (): void {
    // The reservation itself is sound: start-of-string, underscore boundary,
    // case-insensitivity, the full verb set, and the two names that merely
    // CONTAIN a verb all agree in three languages. Stated as an assertion so
    // that a port narrowing the pattern shows up here rather than in a row
    // that was already red.
    $names = ['adm-0005', 'adm-0006', 'adm-0007', 'adm-0008', 'adm-0009', 'adm-0010', 'adm-0011', 'adm-0019', 'adm-0020', 'adm-0021', 'adm-0022', 'adm-0023', 'adm-0024', 'adm-0025'];

    $disagreements = collect(toolAdmissionCorpus())
        ->filter(fn (array $case): bool => in_array($case['id'], $names, true))
        ->filter(fn (array $case): bool => $case['disagrees_on'] !== [])
        ->pluck('id')
        ->all();

    expect($disagreements)->toBe([]);
});
