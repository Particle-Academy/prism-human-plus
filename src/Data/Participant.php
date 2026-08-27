<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Data;

final readonly class Participant
{
    public function __construct(public string $id, public string $name, public string $color) {}
}
