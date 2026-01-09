<?php

declare(strict_types=1);

namespace Syntexa\DevTools\Application;

use Syntexa\Core\Attributes\AsResponse;

#[AsResponse]
class ProfilerResponse
{
    public function __construct(
        public array $events,
        public int $total
    ) {
    }
}
