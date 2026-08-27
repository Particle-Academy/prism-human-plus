<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Enums;

enum AttachmentState: string
{
    case Attached = 'attached';
    case SurfaceUnavailable = 'surface_unavailable';
    case Unauthorized = 'attachment_unauthorized';
    case Detached = 'detached';
}
