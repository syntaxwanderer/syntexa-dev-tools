<?php

declare(strict_types=1);

namespace Syntexa\DevTools\Application;

use Syntexa\Core\Attributes\AsRequestHandler;
use Syntexa\Core\Contract\RequestInterface;
use Syntexa\Core\Contract\ResponseInterface;
use Syntexa\Core\Response;

#[AsRequestHandler(for: AiRequest::class)]
class AiHandler
{
    public function __construct(
        private AiDataCollector $dataCollector
    ) {
    }

    public function handle(RequestInterface $request, ResponseInterface $response = null): ResponseInterface
    {
        $data = $this->dataCollector->collect();
        return Response::json($data);
    }
}
