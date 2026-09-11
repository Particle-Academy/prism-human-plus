<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Data;

use Prism\HumanPlus\Enums\AttachmentState;
use Prism\HumanPlus\Exceptions\ConflictDetectionUnavailable;

final readonly class SurfaceAttachment
{
    public function __construct(
        public string $id,
        public string $owner,
        public SurfaceInvitation $invitation,
        public Participant $participant,
        public string $clientId,
        public int $generation = 0,
        public AttachmentState $state = AttachmentState::Attached,
        /**
         * The surface's own marker for the state this attachment last saw.
         *
         * Carried across turns because a queue worker is a fresh process: the
         * agent that reads and the agent that writes are frequently not the
         * same PHP process, so holding this in memory would mean a write is
         * never pinned to anything in exactly the deployment shape the first
         * consumer has.
         */
        public ?SurfaceRevision $revision = null,
        /**
         * Has this surface EVER minted a revision? Null until it has answered.
         *
         * Separate from `$revision` because they answer different questions, and
         * the difference is the whole of {@see ConflictDetectionUnavailable}:
         * `$revision === null` can mean "not yet read" OR "this surface does not
         * do revisions", and only the second is a reason to refuse a write.
         */
        public ?bool $revisionsSupported = null,
    ) {}

    public function transition(AttachmentState $state): self
    {
        return new self(
            $this->id, $this->owner, $this->invitation, $this->participant, $this->clientId,
            $this->generation + 1, $state, $this->revision, $this->revisionsSupported,
        );
    }

    /**
     * Record what the surface said its state is now.
     *
     * **The generation deliberately does NOT advance.** It is the optimistic
     * token for the ATTACHMENT — its lifecycle state, its identity — and
     * `LegacyMcpClient` keys its initialise cache on `id:generation`. Bumping it
     * for a revision observed on an ordinary tool call would re-run the MCP
     * handshake on every single call, which is a cost nobody asked for to record
     * something that is not a lifecycle change.
     */
    public function withRevision(?SurfaceRevision $revision): self
    {
        return new self(
            $this->id, $this->owner, $this->invitation, $this->participant, $this->clientId,
            $this->generation, $this->state, $revision, $revision instanceof SurfaceRevision ? true : $this->revisionsSupported,
        );
    }

    /**
     * Record that the surface answered and minted nothing.
     *
     * Only ever moves null → false. A surface that supplied a revision once and
     * then stopped is a surface that supports them and had nothing new to say,
     * which is not the same as one that has never had the capability — and
     * treating it as such would refuse writes on a surface that is protecting
     * them perfectly well.
     */
    public function observingNoRevision(): self
    {
        if ($this->revisionsSupported !== null) {
            return $this;
        }

        return new self(
            $this->id, $this->owner, $this->invitation, $this->participant, $this->clientId,
            $this->generation, $this->state, $this->revision, false,
        );
    }

    /**
     * Forget the revision, so the next call goes out unpinned.
     *
     * Called after a rejection. Without it an agent is stuck: every call carries
     * the stale marker, a surface that gates reads on the marker refuses the
     * very read that would refresh it, and the turn cannot recover. The package
     * does not know which of the surface's tools is a read, so it cannot refresh
     * on the agent's behalf — dropping the marker is how it gets out of the way
     * instead.
     */
    public function withoutRevision(): self
    {
        return new self(
            $this->id, $this->owner, $this->invitation, $this->participant, $this->clientId,
            $this->generation, $this->state, null, $this->revisionsSupported,
        );
    }
}
