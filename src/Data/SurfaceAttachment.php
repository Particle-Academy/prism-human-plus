<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Data;

use Prism\HumanPlus\Enums\AttachmentState;
use Prism\HumanPlus\Enums\ConflictDetection;

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
         * How much protection this surface has been OBSERVED to have.
         *
         * Separate from `$revision` because they answer different questions:
         * `$revision === null` can mean "not yet read" OR "this surface does not
         * do revisions", and only the second is a reason to refuse a write.
         *
         * Was a `?bool` called `$revisionsSupported`, and the boolean was wrong
         * in a way that mattered — it recorded MINTING and was read as
         * PROTECTION. {@see ConflictDetection} has the finding.
         */
        public ConflictDetection $conflictDetection = ConflictDetection::NotObserved,
    ) {}

    public function transition(AttachmentState $state): self
    {
        return new self(
            $this->id, $this->owner, $this->invitation, $this->participant, $this->clientId,
            $this->generation + 1, $state, $this->revision, $this->conflictDetection,
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
        // Seeing a revision proves minting, so it upgrades OUT of Unavailable —
        // a surface that answered once without one and mints later plainly does
        // mint, and the old boolean made that verdict permanent. Enforced is
        // never downgraded: it was proven by a refusal that happened.
        $detection = $revision instanceof SurfaceRevision && $this->conflictDetection !== ConflictDetection::Enforced
            ? ConflictDetection::Minted
            : $this->conflictDetection;

        return new self(
            $this->id, $this->owner, $this->invitation, $this->participant, $this->clientId,
            $this->generation, $this->state, $revision, $detection,
        );
    }

    /**
     * Record that the surface actually REFUSED a stale pin.
     *
     * The only positive proof of enforcement available, and it is permanent:
     * a refusal that happened cannot un-happen. Nothing else can establish this
     * — a surface with one writer never rejects anything and is indistinguishable
     * from a surface that cannot reject.
     */
    public function observingEnforcement(): self
    {
        return new self(
            $this->id, $this->owner, $this->invitation, $this->participant, $this->clientId,
            $this->generation, $this->state, null, ConflictDetection::Enforced,
        );
    }

    /**
     * Record that the surface answered and minted nothing.
     *
     * Only ever moves NotObserved → Unavailable. A surface that supplied a
     * revision once and then had nothing new to say still mints them, and
     * downgrading it would refuse writes on a surface protecting them perfectly
     * well.
     */
    public function observingNoRevision(): self
    {
        if ($this->conflictDetection !== ConflictDetection::NotObserved) {
            return $this;
        }

        return new self(
            $this->id, $this->owner, $this->invitation, $this->participant, $this->clientId,
            $this->generation, $this->state, $this->revision, ConflictDetection::Unavailable,
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
            $this->generation, $this->state, null, $this->conflictDetection,
        );
    }
}
