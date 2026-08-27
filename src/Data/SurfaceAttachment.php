<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Data;

use Prism\HumanPlus\Enums\AttachmentState;

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
    ) {}

    public function transition(AttachmentState $state): self
    {
        return new self($this->id, $this->owner, $this->invitation, $this->participant, $this->clientId, $this->generation + 1, $state);
    }
}
