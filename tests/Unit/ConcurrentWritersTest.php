<?php

declare(strict_types=1);

use Prism\HumanPlus\Contracts\RelayTransport;
use Prism\HumanPlus\Data\Participant;
use Prism\HumanPlus\Data\SurfaceAttachment;
use Prism\HumanPlus\Data\SurfaceInvitation;
use Prism\HumanPlus\Data\SurfaceRevision;
use Prism\HumanPlus\Exceptions\ConflictDetectionUnavailable;
use Prism\HumanPlus\Exceptions\SurfaceChangedUnderYou;
use Prism\HumanPlus\HumanPlusManager;
use Prism\HumanPlus\Security\ResultGuard;
use Prism\HumanPlus\Security\TrustPolicy;
use Prism\HumanPlus\Stores\InMemoryAttachmentStore;

/*
|--------------------------------------------------------------------------
| Two writers
|--------------------------------------------------------------------------
|
| The case the first outside consumer said they WILL need at their Phase 5: a
| human dragging nodes on a graph the agent is also editing. Before this, a
| human's edit committed mid-turn was overwritten and NOBODY WAS TOLD — both
| writes succeeded, which is what a lost update looks like from the inside.
|
| These pin the property, not the plumbing. The one that matters most is the
| last: a surface that mints no revision cannot be protected, and the package
| has to SAY so rather than look configured.
|
*/

/**
 * A surface that answers a scripted sequence and records what it was sent.
 *
 * Hand-written rather than mocked because the assertions here are about the
 * frames — whether a revision was pinned to a call at all — and a mock that
 * returns the right thing while dropping `_meta` would pass every test in this
 * file while the feature did nothing.
 */
final class ScriptedSurface implements RelayTransport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    /** @param list<array<string, mixed>> $results */
    public function __construct(private array $results, private ?array $toolList = null) {}

    public function exchange(SurfaceAttachment $attachment, array $frame): array
    {
        $this->sent[] = $frame;
        $id = $frame['id'] ?? null;

        return match ($frame['method'] ?? null) {
            'initialize' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['protocolVersion' => '2025-06-18']],
            'tools/list' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => $this->toolList ?? [
                ['name' => 'move_node', 'description' => 'Move a node', 'inputSchema' => ['type' => 'object']],
                ['name' => 'read_graph', 'description' => 'Read the graph', 'inputSchema' => ['type' => 'object']],
            ]]],
            default => $this->nextResult($id),
        };
    }

    public function notify(SurfaceAttachment $attachment, array $frame): void {}

    public function detach(SurfaceAttachment $attachment): void {}

    /** The revision `_meta` carried on each tools/call, in order. */
    public function pinnedRevisions(): array
    {
        return array_values(array_map(
            fn (array $frame): ?string => $frame['params']['_meta']['revision'] ?? null,
            array_filter($this->sent, fn (array $frame): bool => ($frame['method'] ?? null) === 'tools/call'),
        ));
    }

    private function nextResult(mixed $id): array
    {
        $next = array_shift($this->results) ?? ['result' => ['content' => [['type' => 'text', 'text' => 'ok']]]];

        return ['jsonrpc' => '2.0', 'id' => $id, ...$next];
    }
}

function surfaceManager(ScriptedSurface $surface, bool $requireRevision = false): array
{
    $store = new InMemoryAttachmentStore;
    $manager = new HumanPlusManager(
        $surface,
        $store,
        TrustPolicy::everyTool(),
        new ResultGuard,
        requireRevision: $requireRevision,
    );

    $attachment = $manager->attach(
        'owner:1',
        new SurfaceInvitation('https://relay.example.com', 'session_one', str_repeat('a', 32), 'graph:one', 'Canvas'),
        new Participant('agent:one', 'One', '#000000'),
    );

    return [$manager, $attachment->id, $store];
}

function textResult(string $text, ?string $revision = null): array
{
    $result = ['content' => [['type' => 'text', 'text' => $text]]];

    if ($revision !== null) {
        $result['_meta'] = ['revision' => $revision];
    }

    return ['result' => $result];
}

it('pins a write to the revision the previous read observed', function (): void {
    $surface = new ScriptedSurface([
        textResult('graph as at r1', 'r1'),
        textResult('moved'),
    ]);
    [$manager, $id] = surfaceManager($surface);

    $manager->call('owner:1', $id, 'read_graph');
    $manager->call('owner:1', $id, 'move_node', ['node' => 'a']);

    // The first call had nothing to pin to; the second carries what the first
    // was told. That ordering IS the feature.
    expect($surface->pinnedRevisions())->toBe([null, 'r1']);
});

it('refuses the write when the surface says the revision is stale', function (): void {
    // The human committed between the read and the write. Before this, the
    // write landed and their change was gone.
    $surface = new ScriptedSurface([
        textResult('graph as at r1', 'r1'),
        ['error' => ['code' => -32000, 'message' => 'stale', 'data' => ['code' => 'revision_mismatch']]],
    ]);
    [$manager, $id] = surfaceManager($surface);

    $manager->call('owner:1', $id, 'read_graph');

    expect(fn (): string => $manager->call('owner:1', $id, 'move_node', ['node' => 'a']))
        ->toThrow(SurfaceChangedUnderYou::class);
});

it('names the tool and the stale revision, because a model reads this mid-turn', function (): void {
    $surface = new ScriptedSurface([
        textResult('graph as at r1', 'r1'),
        ['error' => ['code' => 409]],
    ]);
    [$manager, $id] = surfaceManager($surface);
    $manager->call('owner:1', $id, 'read_graph');

    try {
        $manager->call('owner:1', $id, 'move_node');
        throw new RuntimeException('expected a refusal');
    } catch (SurfaceChangedUnderYou $conflict) {
        expect($conflict->getMessage())->toContain('move_node')
            ->and($conflict->getMessage())->toContain('r1')
            ->and($conflict->getMessage())->toContain('read_graph')
            // The agent must not retry identically — that is the lost update.
            ->and($conflict->getMessage())->toContain('Do not')
            ->and($conflict->code())->toBe('surface_changed_under_you');
    }
});

it('lets the agent recover, by dropping the marker a rejection invalidated', function (): void {
    // Without the drop the turn is stuck: every later call carries the same
    // stale token, so a surface that gates reads on it refuses the read that
    // would refresh. The package cannot know which tool is a read.
    $surface = new ScriptedSurface([
        textResult('graph as at r1', 'r1'),
        ['error' => ['data' => ['code' => 'conflict']]],
        textResult('graph as at r2', 'r2'),
        textResult('moved'),
    ]);
    [$manager, $id] = surfaceManager($surface);

    $manager->call('owner:1', $id, 'read_graph');
    try {
        $manager->call('owner:1', $id, 'move_node');
    } catch (SurfaceChangedUnderYou) {
        // expected
    }
    $manager->call('owner:1', $id, 'read_graph');
    $manager->call('owner:1', $id, 'move_node');

    expect($surface->pinnedRevisions())->toBe([null, 'r1', null, 'r2']);
});

it('keeps the attachment attached through a conflict', function (): void {
    // A conflict is the NORMAL outcome of two writers, not a lifecycle failure.
    // Marking the surface unavailable would end a session that is healthy.
    $surface = new ScriptedSurface([
        textResult('graph as at r1', 'r1'),
        ['error' => ['code' => 409]],
    ]);
    [$manager, $id] = surfaceManager($surface);
    $manager->call('owner:1', $id, 'read_graph');

    try {
        $manager->call('owner:1', $id, 'move_node');
    } catch (SurfaceChangedUnderYou) {
        // expected
    }

    expect($manager->status('owner:1', $id)->state->value)->toBe('attached');
});

it('does not re-run the MCP handshake for every observed revision', function (): void {
    // Recording a revision must not bump the attachment generation:
    // LegacyMcpClient keys its initialise cache on id:generation, so a bump per
    // call would re-handshake on every call.
    $surface = new ScriptedSurface([
        textResult('r1', 'r1'),
        textResult('r2', 'r2'),
        textResult('r3', 'r3'),
    ]);
    [$manager, $id] = surfaceManager($surface);

    $manager->call('owner:1', $id, 'read_graph');
    $manager->call('owner:1', $id, 'read_graph');
    $manager->call('owner:1', $id, 'read_graph');

    $initialises = count(array_filter($surface->sent, fn (array $f): bool => ($f['method'] ?? null) === 'initialize'));
    expect($initialises)->toBe(1);
});

it('reports that conflicts CANNOT be detected on a surface that mints nothing', function (): void {
    // The property the whole feature rests on, and the one that would otherwise
    // be invisible: everything looks configured while nothing is protected.
    $surface = new ScriptedSurface([textResult('graph')]);
    [$manager, $id] = surfaceManager($surface);

    // Nothing known before the surface has answered.
    expect($manager->conflictDetection('owner:1', $id))->toBeNull();

    $manager->call('owner:1', $id, 'read_graph');

    expect($manager->conflictDetection('owner:1', $id))->toBeFalse();
});

it('reports that they CAN be, once the surface has minted one', function (): void {
    // The positive control for the test above. Without it, a broken detector
    // that always answered false would pass.
    $surface = new ScriptedSurface([textResult('graph', 'r1')]);
    [$manager, $id] = surfaceManager($surface);

    $manager->call('owner:1', $id, 'read_graph');

    expect($manager->conflictDetection('owner:1', $id))->toBeTrue();
});

it('refuses to keep writing to an unprotectable surface when asked to', function (): void {
    $surface = new ScriptedSurface([textResult('graph'), textResult('moved')]);
    [$manager, $id] = surfaceManager($surface, requireRevision: true);

    // The FIRST call is allowed: there is no way to know what a surface supplies
    // before it has answered, and refusing it would refuse the read that finds
    // out.
    $manager->call('owner:1', $id, 'read_graph');

    expect(fn (): string => $manager->call('owner:1', $id, 'move_node'))
        ->toThrow(ConflictDetectionUnavailable::class);
});

it('does not refuse a surface that IS protecting itself', function (): void {
    $surface = new ScriptedSurface([textResult('graph', 'r1'), textResult('moved', 'r2')]);
    [$manager, $id] = surfaceManager($surface, requireRevision: true);

    $manager->call('owner:1', $id, 'read_graph');
    $manager->call('owner:1', $id, 'move_node');

    expect($surface->pinnedRevisions())->toBe([null, 'r1']);
});

it('does not decide a surface is unprotectable because one result omitted a revision', function (): void {
    // A surface that minted once and then had nothing new to say still supports
    // revisions. Downgrading it to false would refuse writes on a surface
    // protecting them perfectly well.
    $surface = new ScriptedSurface([textResult('graph', 'r1'), textResult('moved'), textResult('moved again')]);
    [$manager, $id] = surfaceManager($surface, requireRevision: true);

    $manager->call('owner:1', $id, 'read_graph');
    $manager->call('owner:1', $id, 'move_node');
    $manager->call('owner:1', $id, 'move_node');

    expect($manager->conflictDetection('owner:1', $id))->toBeTrue();
});

it('carries a revision across processes, because a queue worker is a fresh one', function (): void {
    // The agent that reads and the agent that writes are frequently different
    // workers. An in-memory marker would leave every write unpinned in exactly
    // the deployment shape this protects.
    $first = new ScriptedSurface([textResult('graph', 'r1')]);
    [$readManager, $id, $store] = surfaceManager($first);
    $readManager->call('owner:1', $id, 'read_graph');

    // A second manager over the SAME store is a second worker.
    $second = new ScriptedSurface([textResult('moved')]);
    $writeManager = new HumanPlusManager($second, $store, TrustPolicy::everyTool(), new ResultGuard);
    $writeManager->call('owner:1', $id, 'move_node');

    expect($second->pinnedRevisions())->toBe(['r1']);
});

it('refuses a revision marker big enough to be a payload', function (): void {
    expect(fn (): SurfaceRevision => SurfaceRevision::observed(str_repeat('x', 513), 'read_graph'))
        ->toThrow(UnexpectedValueException::class, 'identifier, not a payload')
        ->and(fn (): SurfaceRevision => SurfaceRevision::observed('   ', 'read_graph'))
        ->toThrow(UnexpectedValueException::class);
});

it('reads a revision from several places, because that half of the wire is the surface\'s', function (string $body, string $expected): void {
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

    expect(SurfaceRevision::fromResult($decoded, 'read_graph')?->token)->toBe($expected);
})->with([
    '_meta.revision' => ['{"_meta":{"revision":"r9"}}', 'r9'],
    'top-level revision' => ['{"revision":"r9"}', 'r9'],
    'surfaceRevision' => ['{"surfaceRevision":"r9"}', 'r9'],
    'etag' => ['{"etag":"r9"}', 'r9'],
    // An integer version is a real shape and becomes a string marker; the
    // package never orders or compares it, so the type is irrelevant past here.
    'an integer version' => ['{"version":9}', '9'],
]);
