<?php

declare(strict_types=1);

namespace Prism\HumanPlus;

use Prism\HumanPlus\Contracts\AttachmentStore;
use Prism\HumanPlus\Contracts\RelayTransport;
use Prism\HumanPlus\Data\Activity;
use Prism\HumanPlus\Data\Participant;
use Prism\HumanPlus\Data\SurfaceAttachment;
use Prism\HumanPlus\Data\SurfaceInvitation;
use Prism\HumanPlus\Data\ToolDefinition;
use Prism\HumanPlus\Enums\AttachmentState;
use Prism\HumanPlus\Exceptions\AttachmentUnauthorized;
use Prism\HumanPlus\Exceptions\HumanPlusException;
use Prism\HumanPlus\Exceptions\SurfaceUnavailable;
use Prism\HumanPlus\Exceptions\ToolRefused;
use Prism\HumanPlus\Security\ResultGuard;
use Prism\HumanPlus\Security\TrustPolicy;
use Prism\HumanPlus\Support\OwnerAddress;
use Prism\HumanPlus\Transport\LegacyMcpClient;

final class HumanPlusManager
{
    private readonly LegacyMcpClient $client;

    public function __construct(
        private readonly RelayTransport $transport,
        private readonly AttachmentStore $store,
        private readonly TrustPolicy $trust,
        private readonly ResultGuard $guard,
    ) {
        $this->client = new LegacyMcpClient($transport);
    }

    public function attach(string|object $owner, SurfaceInvitation $invitation, Participant $participant): SurfaceAttachment
    {
        $attachment = new SurfaceAttachment(
            id: 'surface_'.bin2hex(random_bytes(12)), owner: OwnerAddress::from($owner), invitation: $invitation,
            participant: $participant, clientId: 'php_'.bin2hex(random_bytes(8)),
        );
        $this->store->put($attachment);

        return $attachment;
    }

    /** @return list<ToolDefinition> */
    public function tools(string $id): array
    {
        $this->trust->assertDeclared();

        return $this->store->lock($id, fn (): array => $this->discover($this->required($id)));
    }

    /** @param array<string, mixed> $arguments */
    public function call(string $id, string $tool, array $arguments = []): string
    {
        return $this->store->lock($id, function () use ($id, $tool, $arguments): string {
            $this->trust->assertDeclared();
            $attachment = $this->required($id);
            $definition = null;
            foreach ($this->discover($attachment) as $candidate) {
                if ($candidate->name === $tool) {
                    $definition = $candidate;
                    break;
                }
            }
            if (! $definition instanceof ToolDefinition) {
                throw new ToolRefused(sprintf('Human+ tool [%s] is not trusted or was not offered.', $tool));
            }

            try {
                $result = $this->client->call($attachment, $tool, $arguments);
            } catch (SurfaceUnavailable $failure) {
                $this->store->put($attachment->transition(AttachmentState::SurfaceUnavailable), $attachment->generation);
                throw $failure;
            } catch (AttachmentUnauthorized $failure) {
                $this->store->put($attachment->transition(AttachmentState::Unauthorized), $attachment->generation);
                throw $failure;
            }
            $content = $result['content'] ?? [];
            $texts = [];
            if (is_array($content)) {
                foreach ($content as $part) {
                    if (is_array($part) && ($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                        $texts[] = $part['text'];
                    }
                }
            }
            $text = implode("\n", $texts);
            if (($result['isError'] ?? false) === true) {
                throw new HumanPlusException($this->guard->guard($attachment->invitation->surfaceId, $tool, $text));
            }

            return $this->guard->guard($attachment->invitation->surfaceId, $tool, $text);
        });
    }

    public function announce(string $id, Activity $activity): void
    {
        $this->store->lock($id, function () use ($id, $activity): void {
            $attachment = $this->required($id);
            $this->transport->notify($attachment, [
                'jsonrpc' => '2.0', 'method' => 'notifications/human-plus/activity',
                'params' => $activity->toArray($attachment->participant, $attachment),
            ]);
        });
    }

    public function markUnavailable(string $id): SurfaceAttachment
    {
        return $this->transition($id, AttachmentState::SurfaceUnavailable);
    }

    public function markUnauthorized(string $id): SurfaceAttachment
    {
        return $this->transition($id, AttachmentState::Unauthorized);
    }

    public function detach(string $id): SurfaceAttachment
    {
        return $this->store->lock($id, function () use ($id): SurfaceAttachment {
            $attachment = $this->required($id);
            $this->transport->detach($attachment);
            $next = $attachment->transition(AttachmentState::Detached);
            $this->store->put($next, $attachment->generation);

            return $next;
        });
    }

    public function status(string $id): SurfaceAttachment
    {
        $attachment = $this->store->get($id);
        if ($attachment === null) {
            throw new HumanPlusException('Human+ attachment does not exist.');
        }

        return $attachment;
    }

    private function required(string $id): SurfaceAttachment
    {
        $attachment = $this->status($id);
        if ($attachment->state !== AttachmentState::Attached) {
            throw new HumanPlusException(sprintf('Human+ attachment is [%s]; create a new attachment to join another surface lifecycle.', $attachment->state->value));
        }

        return $attachment;
    }

    /** @return list<ToolDefinition> */
    private function discover(SurfaceAttachment $attachment): array
    {
        try {
            $tools = $this->client->tools($attachment);
        } catch (SurfaceUnavailable $failure) {
            $this->store->put($attachment->transition(AttachmentState::SurfaceUnavailable), $attachment->generation);
            throw $failure;
        } catch (AttachmentUnauthorized $failure) {
            $this->store->put($attachment->transition(AttachmentState::Unauthorized), $attachment->generation);
            throw $failure;
        }
        $allowed = [];
        foreach ($tools as $tool) {
            if (! $this->trust->allows($tool->name)) {
                continue;
            }
            $this->trust->assertAllows($tool);
            $allowed[] = $tool;
        }

        return $allowed;
    }

    private function transition(string $id, AttachmentState $state): SurfaceAttachment
    {
        return $this->store->lock($id, function () use ($id, $state): SurfaceAttachment {
            $attachment = $this->required($id);
            $next = $attachment->transition($state);
            $this->store->put($next, $attachment->generation);

            return $next;
        });
    }
}
