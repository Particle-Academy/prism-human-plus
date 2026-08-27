<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Enums;

enum Priority: string
{
    case Background = 'background';
    case Normal = 'normal';
    case Attention = 'attention';
    case Blocking = 'blocking';
}
