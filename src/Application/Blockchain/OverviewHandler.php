<?php

declare(strict_types=1);

namespace Syntexa\DevTools\Application\Blockchain;

use Syntexa\Core\Attributes\AsRequestHandler;
use Syntexa\Core\Contract\RequestInterface;
use Syntexa\Core\Contract\ResponseInterface;
use Syntexa\Core\Response;
use Syntexa\Orm\Blockchain\BlockchainConfig;

#[AsRequestHandler(for: OverviewRequest::class)]
class OverviewHandler
{
    public function handle(RequestInterface $request, ResponseInterface $response = null): ResponseInterface
    {
        $config = BlockchainConfig::fromEnv();
        $dataCollector = new BlockchainDataCollector($config);
        $data = $dataCollector->getOverview();
        return Response::json($data);
    }
}
