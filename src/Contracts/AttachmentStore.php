<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Contracts;

use Closure;
use Prism\HumanPlus\Data\SurfaceAttachment;

interface AttachmentStore
{
    public function get(string $id): ?SurfaceAttachment;

    public function put(SurfaceAttachment $attachment, ?int $expectedGeneration = null): void;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function lock(string $id, Closure $callback): mixed;
}
