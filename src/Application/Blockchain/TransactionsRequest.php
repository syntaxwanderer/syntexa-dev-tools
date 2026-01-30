<?php

declare(strict_types=1);

namespace Syntexa\DevTools\Application\Blockchain;

use Syntexa\Core\Attributes\AsRequest;
use Syntexa\Core\Contract\RequestInterface;

#[AsRequest(path: '/dev-tools/api/blockchain/transactions')]
class TransactionsRequest implements RequestInterface
{
    public function __construct(
        public readonly ?int $limit = null,
        public readonly ?int $offset = null,
        public readonly ?string $node_id = null,
    ) {
    }
}
