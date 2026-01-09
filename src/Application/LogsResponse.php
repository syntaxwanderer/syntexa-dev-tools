<?php

declare(strict_types=1);

namespace Syntexa\DevTools\Application;

use Syntexa\Core\Attributes\AsResponse;

#[AsResponse]
class LogsResponse
{
    public function __construct(
        public array $logs,
        public array $files,
        public string $currentFile,
        public int $totalLines
    ) {
    }
}
