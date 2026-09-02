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
    expect(toolAdmissionCorpus())->toHaveCount(20);
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

it('RESERVES a confirm name with a trailing newline, which TypeScript does not', function (): void {
    // G-33, and the reason this suite exists. `$` in PCRE matches before a
    // final newline, so `terminal_confirm\n` is reserved here and in Python —
    // and NOT in the TypeScript port, where `$` without the multiline flag
    // matches only at the very end.
    //
    // A surface chooses its own tool names, so the newline is attacker-
    // controlled: the same surface is safe against a PHP or Python agent and
    // hands the confirmation tool to a TypeScript one.
    //
    // Asserted in the POSITIVE, because this side is the correct one.
    $case = collect(toolAdmissionCorpus())->firstWhere('id', 'adm-0011');

    expect(admitFromCorpus($case)['allows'])->toBeFalse()
        ->and($case['admission']['py']['allows'])->toBeFalse()
        ->and($case['admission']['ts']['allows'])->toBeTrue();
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

it('ADMITS a confirm name with one trailing space, and so does every other language', function (): void {
    // G-36, and the worst finding in this suite precisely BECAUSE all three
    // agree. `$` tolerates at most one trailing newline in PCRE and Python and
    // none in JavaScript, and nothing normalises the name before matching — so
    // a surface that calls its tool `terminal_confirm ` gets the confirmation
    // tool handed to the agent in every language.
    //
    // A cross-language corpus cannot find this by COMPARING languages; there is
    // nothing to compare. It is asserted here in the POSITIVE, describing the
    // hole rather than a guarantee, so that the day someone closes it this row
    // goes red and forces the corpus and the register to move with the fix.
    //
    // adm-0020 is the same hole reached by a second newline, which is why a fix
    // that only special-cases `\n` is visibly not enough.
    foreach (['adm-0019', 'adm-0020'] as $id) {
        $case = collect(toolAdmissionCorpus())->firstWhere('id', $id);

        expect($case['policy']['mode'])->toBe('everyTool')
            ->and(admitFromCorpus($case)['admitted'])->toBeTrue()
            ->and($case['admission']['ts']['admitted'])->toBeTrue()
            ->and($case['admission']['py']['admitted'])->toBeTrue();
    }
});

it('agrees with BOTH ports on every reserved-name row except the newline', function (): void {
    // The reservation itself is sound: start-of-string, underscore boundary,
    // case-insensitivity, the full verb set, and the two names that merely
    // CONTAIN a verb all agree in three languages. Stated as an assertion so
    // that a port narrowing the pattern shows up here rather than in a row
    // that was already red.
    $names = ['adm-0005', 'adm-0006', 'adm-0007', 'adm-0008', 'adm-0009', 'adm-0010'];

    $disagreements = collect(toolAdmissionCorpus())
        ->filter(fn (array $case): bool => in_array($case['id'], $names, true))
        ->filter(fn (array $case): bool => $case['disagrees_on'] !== [])
        ->pluck('id')
        ->all();

    expect($disagreements)->toBe([]);
});
