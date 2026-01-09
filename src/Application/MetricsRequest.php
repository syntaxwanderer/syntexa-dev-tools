<?php

declare(strict_types=1);

namespace Syntexa\DevTools\Application;

use Syntexa\Core\Attributes\AsRequest;
use Syntexa\Core\Contract\RequestInterface;

#[AsRequest(path: '/dev-tools/api/metrics')]
class MetricsRequest implements RequestInterface
{
}
