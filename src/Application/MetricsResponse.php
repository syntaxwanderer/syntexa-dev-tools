<?php

declare(strict_types=1);

namespace Syntexa\DevTools\Application;

use Syntexa\Core\Attributes\AsResponse;

#[AsResponse]
class MetricsResponse
{
    public function __construct(
        public array $swoole,
        public array $application,
        public array $memory,
        public float $timestamp
    ) {
    }
}
