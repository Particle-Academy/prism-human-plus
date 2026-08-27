<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Data;

use Prism\HumanPlus\Enums\Priority;

final readonly class Activity
{
    public function __construct(
        public string $action,
        public ?string $target,
        public Priority $priority = Priority::Normal,
        public ?string $correlationId = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(Participant $participant, SurfaceAttachment $attachment): array
    {
        return [
            'actor' => ['id' => $participant->id, 'name' => $participant->name, 'color' => $participant->color, 'type' => 'agent'],
            'surfaceId' => $attachment->invitation->surfaceId,
            'sessionId' => $attachment->invitation->sessionId,
            'action' => $this->action,
            'target' => $this->target,
            'priority' => $this->priority->value,
            'correlationId' => $this->correlationId,
        ];
    }
}
