<?php

declare(strict_types=1);

namespace Syntexa\DevTools\Application;

use Syntexa\Core\Attributes\AsRequest;
use Syntexa\Core\Contract\RequestInterface;

#[AsRequest(path: '/dev-tools/api/logs')]
class LogsRequest implements RequestInterface
{
    public function __construct(
        public ?string $file = null,
        public int $lines = 100,
        public ?string $filter = null
    ) {
    }
}
