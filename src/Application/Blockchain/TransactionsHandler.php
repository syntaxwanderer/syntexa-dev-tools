<?php

declare(strict_types=1);

namespace Syntexa\DevTools\Application\Blockchain;

use Syntexa\Core\Attributes\AsRequestHandler;
use Syntexa\Core\Contract\RequestInterface;
use Syntexa\Core\Contract\ResponseInterface;
use Syntexa\Core\Response;
use Syntexa\Orm\Blockchain\BlockchainConfig;

#[AsRequestHandler(for: TransactionsRequest::class)]
class TransactionsHandler
{
    public function handle(RequestInterface $request, ResponseInterface $response = null): ResponseInterface
    {
        /** @var TransactionsRequest $request */
        $limit = $request->limit ?? 50;
        $offset = $request->offset ?? 0;
        $nodeId = $request->node_id;

        $config = BlockchainConfig::fromEnv();
        $dataCollector = new BlockchainDataCollector($config);
        $data = $dataCollector->getTransactions($limit, $offset, $nodeId);
        return Response::json($data);
    }
}
