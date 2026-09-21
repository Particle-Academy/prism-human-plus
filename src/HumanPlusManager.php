<?php

declare(strict_types=1);

namespace Prism\HumanPlus;

use Prism\HumanPlus\Contracts\AttachmentStore;
use Prism\HumanPlus\Contracts\RelayTransport;
use Prism\HumanPlus\Data\Activity;
use Prism\HumanPlus\Data\Participant;
use Prism\HumanPlus\Data\SurfaceAttachment;
use Prism\HumanPlus\Data\SurfaceChanges;
use Prism\HumanPlus\Data\SurfaceInvitation;
use Prism\HumanPlus\Data\SurfaceRevision;
use Prism\HumanPlus\Data\ToolDefinition;
use Prism\HumanPlus\Enums\AttachmentState;
use Prism\HumanPlus\Enums\ChangeFeed;
use Prism\HumanPlus\Enums\ConflictDetection;
use Prism\HumanPlus\Exceptions\AttachmentUnauthorized;
use Prism\HumanPlus\Exceptions\ConflictDetectionUnavailable;
use Prism\HumanPlus\Exceptions\HumanPlusException;
use Prism\HumanPlus\Exceptions\SurfaceChangedUnderYou;
use Prism\HumanPlus\Exceptions\SurfaceRevisionRejected;
use Prism\HumanPlus\Exceptions\SurfaceUnavailable;
use Prism\HumanPlus\Exceptions\ToolRefused;
use Prism\HumanPlus\Security\ResultGuard;
use Prism\HumanPlus\Security\TrustPolicy;
use Prism\HumanPlus\Support\OwnerAddress;
use Prism\HumanPlus\Transport\LegacyMcpClient;

final class HumanPlusManager
{
    /**
     * The tool names a surface may offer a change feed under.
     *
     * Several, because this half of the wire is the surface's — the same reason
     * {@see SurfaceRevision::fromResult()} accepts several key names. Matched
     * case-insensitively and nothing else: a tool that merely looks like a feed
     * is not called speculatively.
     */
    private const CHANGE_FEED_TOOLS = ['changes_since', 'changessince', 'surface_changes', 'surfacechanges', 'what_changed', 'whatchanged', 'changes'];

    private readonly LegacyMcpClient $client;

    public function __construct(
        private readonly RelayTransport $transport,
        private readonly AttachmentStore $store,
        private readonly TrustPolicy $trust,
        private readonly ResultGuard $guard,
        /**
         * Refuse calls to a surface that has proved it mints no revisions.
         *
         * Off by default, because a single-writer surface is a real and common
         * case and refusing it would be this package inventing a requirement.
         * ON is for a shared canvas, where a lost update is silent data loss —
         * see {@see ConflictDetectionUnavailable} for why the absence has to be
         * made loud rather than left to look like protection.
         *
         * **This requires minting, which is not the same as protection.** A
         * surface that mints a revision and ignores the pin satisfies this flag
         * and loses every update; the first integrator is exactly that surface
         * today. There is deliberately no stricter mode — see
         * {@see ConflictDetection} for why demanding proof of enforcement would
         * refuse every write on a healthy single-writer surface.
         */
        private readonly bool $requireRevision = false,
    ) {
        $this->client = new LegacyMcpClient($transport);
    }

    /**
     * How much lost-update protection this surface has been OBSERVED to have.
     *
     * A check rather than a claim, and callable at attach time so a host can
     * assert it once instead of finding out mid-turn. Read
     * {@see ConflictDetection} before acting on it: the state that matters most
     * is {@see ConflictDetection::Minted}, which means this package is pinning
     * every call and **cannot see whether the surface enforces the pin**.
     *
     * That distinction is not hypothetical. The first integrator mints a
     * revision on every write result and reads an incoming pin nowhere — no
     * `_meta`, no rejection path, no 409 — so a pinned call is applied exactly
     * as an unpinned one. An earlier version of this method returned `true` for
     * that surface and its documentation said a lost update would be caught.
     * It would not have been. The states now say only what was seen.
     */
    public function conflictDetection(string|object $owner, string $id): ConflictDetection
    {
        return $this->store->lock($id, fn (): ConflictDetection => $this->required($owner, $id)->conflictDetection);
    }

    /**
     * What changed on this surface since the marker the agent last saw.
     *
     * ## Why this exists, in one sentence
     *
     * {@see SurfaceRevision} stops an agent overwriting a change it did not
     * know about. It does NOTHING about an agent that re-reads, sees current
     * state, decides the surface has drifted from what it intended, and puts it
     * back — over a person's edit, with nothing stale anywhere and no error at
     * any layer. Optimistic concurrency answers "did the world move under me";
     * this answers "what did somebody else do", which is the question that
     * stops the revert.
     *
     * ## What this package does and does not do here
     *
     * It carries. The marker goes out, the surface decides what changed since
     * it, and the answer comes back as handles, kinds and actors. No diffing,
     * no merge, no model of what a screen is — the same contract
     * {@see SurfaceRevision} keeps, for the same reason: the comparison belongs
     * where the knowledge is.
     *
     * ## The empty answer
     *
     * **Read {@see SurfaceChanges::answered()} before reading the list.** A
     * surface with no feed and a surface with nothing to report produce the
     * same empty array, and this package has already shipped one check that
     * could not tell "no" from "cannot say" — see {@see ConflictDetection}.
     * {@see SurfaceChanges::nothingChanged()} is the only method that means
     * what an empty list looks like it means.
     */
    public function changesSince(string|object $owner, string $id): SurfaceChanges
    {
        return $this->store->lock($id, function () use ($owner, $id): SurfaceChanges {
            $this->trust->assertDeclared();
            $attachment = $this->required($owner, $id);

            $feedTool = null;
            foreach ($this->discover($attachment) as $candidate) {
                if (in_array(strtolower($candidate->name), self::CHANGE_FEED_TOOLS, true)) {
                    $feedTool = $candidate;
                    break;
                }
            }

            if (! $feedTool instanceof ToolDefinition) {
                // Recorded, not just returned. A later turn asking again should
                // not have to re-derive that this surface cannot answer, and an
                // operator should be able to see it on the attachment.
                $next = $attachment->observingChangeFeed(false);
                if ($next !== $attachment) {
                    $this->store->put($next, $attachment->generation);
                }

                return SurfaceChanges::unavailable();
            }

            $attachment = $attachment->observingChangeFeed(true);
            $pinned = $attachment->revision;

            try {
                $result = $this->client->call(
                    $attachment,
                    $feedTool->name,
                    $pinned instanceof SurfaceRevision ? ['since' => $pinned->token] : [],
                    $pinned,
                );
            } catch (SurfaceUnavailable $failure) {
                $this->store->put($attachment->transition(AttachmentState::SurfaceUnavailable), $attachment->generation);
                throw $failure;
            } catch (AttachmentUnauthorized $failure) {
                $this->store->put($attachment->transition(AttachmentState::Unauthorized), $attachment->generation);
                throw $failure;
            } catch (SurfaceRevisionRejected) {
                // The READ was refused for carrying a stale marker. Drop it and
                // say the question went unanswered, exactly as `call()` does:
                // an agent left holding a stale token cannot refresh it, because
                // the refresh is the call being refused.
                $this->store->put($attachment->observingEnforcement(), $attachment->generation);

                throw SurfaceChangedUnderYou::while($feedTool->name, $pinned);
            }

            // Parsed where a conformance runner can reach it. The manager's
            // job here is the guard and the attachment, not the shape.
            $answer = SurfaceChanges::readFrom($result, $attachment->changeFeed);

            if ($answer->revision instanceof SurfaceRevision) {
                $attachment = $attachment->withRevision($answer->revision);
            }

            if ($answer->feed === ChangeFeed::Attributed) {
                $attachment = $attachment->observingAttribution();
            }

            $this->store->put($attachment, $attachment->generation);

            return $answer->withFramedLabels(
                fn (string $label): string => $this->guard->guard($attachment->invitation->surfaceId, $feedTool->name, $label),
            );
        });
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
    public function tools(string|object $owner, string $id): array
    {
        $this->trust->assertDeclared();

        return $this->store->lock($id, fn (): array => $this->discover($this->required($owner, $id)));
    }

    /** @param array<string, mixed> $arguments */
    public function call(string|object $owner, string $id, string $tool, array $arguments = []): string
    {
        return $this->store->lock($id, function () use ($owner, $id, $tool, $arguments): string {
            $this->trust->assertDeclared();
            $attachment = $this->required($owner, $id);
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

            // The first call is always allowed: there is no way to know what a
            // surface supplies before it has answered once, and refusing it
            // would refuse the very read that finds out.
            if ($this->requireRevision && $attachment->conflictDetection->isUnprotected()) {
                throw ConflictDetectionUnavailable::forSurface($attachment->invitation->surfaceId, $tool);
            }

            $pinned = $attachment->revision;

            try {
                $result = $this->client->call($attachment, $tool, $arguments, $pinned);
            } catch (SurfaceUnavailable $failure) {
                $this->store->put($attachment->transition(AttachmentState::SurfaceUnavailable), $attachment->generation);
                throw $failure;
            } catch (AttachmentUnauthorized $failure) {
                $this->store->put($attachment->transition(AttachmentState::Unauthorized), $attachment->generation);
                throw $failure;
            } catch (SurfaceRevisionRejected) {
                // DROP THE MARKER, then refuse. Without the drop the agent is
                // stuck: every later call carries the same stale token, and a
                // surface that gates reads on it refuses the read that would
                // refresh. The package cannot refresh on the agent's behalf
                // because it does not know which tool is a read — getting out of
                // the way is the recovery path it can offer.
                //
                // The attachment is NOT transitioned: a conflict is a normal
                // outcome of two writers, not a lifecycle failure, and marking
                // the surface unavailable would end a session that is healthy.
                // A refusal is the ONLY positive proof that this surface
                // enforces a pin, so it is recorded permanently. Nothing else
                // can establish it: a surface with one writer never rejects
                // anything and is indistinguishable from one that cannot.
                // `observingEnforcement()` also clears the marker, which is the
                // recovery path described above.
                $this->store->put($attachment->observingEnforcement(), $attachment->generation);

                throw SurfaceChangedUnderYou::while($tool, $pinned);
            }

            $observed = SurfaceRevision::fromResult($result, $tool);
            $next = $observed instanceof SurfaceRevision
                ? $attachment->withRevision($observed)
                : $attachment->observingNoRevision();

            if ($next !== $attachment) {
                $this->store->put($next, $attachment->generation);
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

    public function announce(string|object $owner, string $id, Activity $activity): void
    {
        $this->store->lock($id, function () use ($owner, $id, $activity): void {
            $attachment = $this->required($owner, $id);
            $this->transport->notify($attachment, [
                'jsonrpc' => '2.0', 'method' => 'notifications/human-plus/activity',
                'params' => $activity->toArray($attachment->participant, $attachment),
            ]);
        });
    }

    public function markUnavailable(string|object $owner, string $id): SurfaceAttachment
    {
        return $this->transition($owner, $id, AttachmentState::SurfaceUnavailable);
    }

    public function markUnauthorized(string|object $owner, string $id): SurfaceAttachment
    {
        return $this->transition($owner, $id, AttachmentState::Unauthorized);
    }

    public function detach(string|object $owner, string $id): SurfaceAttachment
    {
        return $this->store->lock($id, function () use ($owner, $id): SurfaceAttachment {
            $attachment = $this->required($owner, $id);
            $this->transport->detach($attachment);
            $next = $attachment->transition(AttachmentState::Detached);
            $this->store->put($next, $attachment->generation);

            return $next;
        });
    }

    public function status(string|object $owner, string $id): SurfaceAttachment
    {
        $attachment = $this->store->get($id);
        if ($attachment === null) {
            throw new HumanPlusException('Human+ attachment does not exist.');
        }
        if (! hash_equals($attachment->owner, OwnerAddress::from($owner))) {
            throw new AttachmentUnauthorized('Human+ attachment does not belong to this owner.');
        }

        return $attachment;
    }

    private function required(string|object $owner, string $id): SurfaceAttachment
    {
        $attachment = $this->status($owner, $id);
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

    private function transition(string|object $owner, string $id, AttachmentState $state): SurfaceAttachment
    {
        return $this->store->lock($id, function () use ($owner, $id, $state): SurfaceAttachment {
            $attachment = $this->required($owner, $id);
            $next = $attachment->transition($state);
            $this->store->put($next, $attachment->generation);

            return $next;
        });
    }
}
