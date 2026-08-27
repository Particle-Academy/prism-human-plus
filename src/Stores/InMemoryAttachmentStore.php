<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Stores;

use Closure;
use Prism\HumanPlus\Contracts\AttachmentStore;
use Prism\HumanPlus\Data\SurfaceAttachment;
use Prism\HumanPlus\Exceptions\HumanPlusException;

final class InMemoryAttachmentStore implements AttachmentStore
{
    /** @var array<string, SurfaceAttachment> */
    private array $attachments = [];

    public function get(string $id): ?SurfaceAttachment
    {
        return $this->attachments[$id] ?? null;
    }

    public function put(SurfaceAttachment $attachment, ?int $expectedGeneration = null): void
    {
        if ($expectedGeneration !== null && $this->get($attachment->id)?->generation !== $expectedGeneration) {
            throw new HumanPlusException('Human+ attachment changed while this worker was acting.');
        }
        $this->attachments[$attachment->id] = $attachment;
    }

    public function lock(string $id, Closure $callback): mixed
    {
        return $callback();
    }
}
