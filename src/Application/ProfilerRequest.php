<?php

declare(strict_types=1);

namespace Syntexa\DevTools\Application;

use Syntexa\Core\Attributes\AsRequest;
use Syntexa\Core\Contract\RequestInterface;

#[AsRequest(path: '/dev-tools/api/profiler')]
class ProfilerRequest implements RequestInterface
{
    public function __construct(
        public ?int $limit = 50
    ) {
    }
}
