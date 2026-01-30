<?php

declare(strict_types=1);

namespace Syntexa\DevTools\Application\Blockchain;

use Syntexa\Core\Attributes\AsRequest;
use Syntexa\Core\Contract\RequestInterface;

#[AsRequest(path: '/dev-tools/api/blockchain/overview')]
class OverviewRequest implements RequestInterface
{
}
