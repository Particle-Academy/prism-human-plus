<?php

declare(strict_types=1);

use Prism\HumanPlus\Contracts\RelayTransport;
use Prism\HumanPlus\Data\Activity;
use Prism\HumanPlus\Data\Participant;
use Prism\HumanPlus\Data\SurfaceAttachment;
use Prism\HumanPlus\Data\SurfaceInvitation;
use Prism\HumanPlus\Enums\AttachmentState;
use Prism\HumanPlus\Enums\Priority;
use Prism\HumanPlus\Exceptions\AttachmentUnauthorized;
use Prism\HumanPlus\Exceptions\HumanPlusException;
use Prism\HumanPlus\Exceptions\SurfaceUnavailable;
use Prism\HumanPlus\Exceptions\ToolRefused;
use Prism\HumanPlus\HumanPlusManager;
use Prism\HumanPlus\Security\ResultGuard;
use Prism\HumanPlus\Security\TrustPolicy;
use Prism\HumanPlus\Stores\InMemoryAttachmentStore;
use Prism\HumanPlus\Tools\HumanPlusToolset;

function fakeRelayTransport(): RelayTransport
{
    return new class implements RelayTransport
    {
        /** @var list<array<string, mixed>> */
        public array $notifications = [];

        public bool $gone = false;

        public function exchange(SurfaceAttachment $attachment, array $frame): array
        {
            if ($this->gone) {
                throw new SurfaceUnavailable('surface_unavailable');
            }
            $method = $frame['method'];
            $result = match ($method) {
                'initialize' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'serverInfo' => ['name' => 'surface', 'version' => '1']],
                'tools/list' => ['tools' => [['name' => 'sheet_read', 'description' => 'Read the shared sheet', 'inputSchema' => ['type' => 'object']]]],
                'tools/call' => ['content' => [['type' => 'text', 'text' => 'shared state']], 'isError' => false],
                default => [],
            };

            return ['jsonrpc' => '2.0', 'id' => $frame['id'], 'result' => $result];
        }

        public function notify(SurfaceAttachment $attachment, array $frame): void
        {
            $this->notifications[] = $frame;
        }

        public function detach(SurfaceAttachment $attachment): void {}
    };
}

function attachFixture(HumanPlusManager $manager): SurfaceAttachment
{
    return $manager->attach(
        'session:one',
        new SurfaceInvitation('https://relay.example.com', 'demo_001', str_repeat('a', 32), 'sheet:budget', 'Budget'),
        new Participant('agent:prism', 'Prism', '#7c3aed'),
    );
}

it('refuses before discovery when trust is undeclared', function (): void {
    $transport = fakeRelayTransport();
    $manager = new HumanPlusManager($transport, new InMemoryAttachmentStore, TrustPolicy::undeclared(), new ResultGuard);
    $attachment = attachFixture($manager);

    expect(fn () => $manager->tools('session:one', $attachment->id))->toThrow(ToolRefused::class, 'undeclared')
        ->and($transport->notifications)->toBe([]);
});

it('discovers allowed tools and guards their result', function (): void {
    $manager = new HumanPlusManager(fakeRelayTransport(), new InMemoryAttachmentStore, TrustPolicy::allowing(['sheet_read']), new ResultGuard);
    $attachment = attachFixture($manager);

    expect($manager->tools('session:one', $attachment->id))->toHaveCount(1)
        ->and($manager->call('session:one', $attachment->id, 'sheet_read'))->toContain('<untrusted-tool-output')
        ->toContain('shared state');
});

it('announces participant activity with priority and target', function (): void {
    $transport = fakeRelayTransport();
    $manager = new HumanPlusManager($transport, new InMemoryAttachmentStore, TrustPolicy::allowing(['sheet_read']), new ResultGuard);
    $attachment = attachFixture($manager);
    $manager->announce('session:one', $attachment->id, new Activity('editing', 'cell:A1', Priority::Attention, 'run-7'));

    expect($transport->notifications)->toHaveCount(1)
        ->and($transport->notifications[0]['params']['actor']['id'])->toBe('agent:prism')
        ->and($transport->notifications[0]['params']['priority'])->toBe('attention');
});

it('makes session gone terminal for the attachment', function (): void {
    $transport = fakeRelayTransport();
    $store = new InMemoryAttachmentStore;
    $manager = new HumanPlusManager($transport, $store, TrustPolicy::allowing(['sheet_read']), new ResultGuard);
    $attachment = attachFixture($manager);
    $transport->gone = true;

    expect(fn () => $manager->tools('session:one', $attachment->id))->toThrow(SurfaceUnavailable::class)
        ->and($manager->status('session:one', $attachment->id)->state)->toBe(AttachmentState::SurfaceUnavailable)
        ->and(fn () => $manager->tools('session:one', $attachment->id))->toThrow(HumanPlusException::class, 'create a new attachment');
});

it('turns trusted surface definitions into Prism tools with local approval policy', function (): void {
    $manager = new HumanPlusManager(fakeRelayTransport(), new InMemoryAttachmentStore, TrustPolicy::allowing(['sheet_read']), new ResultGuard);
    $attachment = attachFixture($manager);
    $tools = (new HumanPlusToolset($manager))->forAttachment('session:one', $attachment->id, ['sheet_read']);

    expect($tools)->toHaveCount(1)
        ->and($tools[0]->name())->toBe('sheet_read')
        ->and($tools[0]->needsApproval())->toBeTrue();
});

it('refuses every operation when the attachment owner does not match', function (): void {
    $manager = new HumanPlusManager(fakeRelayTransport(), new InMemoryAttachmentStore, TrustPolicy::allowing(['sheet_read']), new ResultGuard);
    $attachment = attachFixture($manager);

    expect(fn () => $manager->tools('session:two', $attachment->id))->toThrow(AttachmentUnauthorized::class, 'does not belong')
        ->and(fn () => $manager->status('session:two', $attachment->id))->toThrow(AttachmentUnauthorized::class, 'does not belong')
        ->and(fn () => $manager->announce('session:two', $attachment->id, new Activity('editing', 'cell:A1')))->toThrow(AttachmentUnauthorized::class, 'does not belong')
        ->and(fn () => $manager->detach('session:two', $attachment->id))->toThrow(AttachmentUnauthorized::class, 'does not belong');
});
