<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Contracts;

use Prism\HumanPlus\Data\SurfaceAttachment;

interface RelayTransport
{
    /**
     * @param  array<string, mixed>  $frame
     * @return array<string, mixed>
     */
    public function exchange(SurfaceAttachment $attachment, array $frame): array;

    /** @param array<string, mixed> $frame */
    public function notify(SurfaceAttachment $attachment, array $frame): void;

    public function detach(SurfaceAttachment $attachment): void;
}
