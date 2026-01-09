<?php

declare(strict_types=1);

namespace Syntexa\DevTools\Application;

use Syntexa\Core\Attributes\AsRequestHandler;
use Syntexa\Core\Contract\RequestInterface;
use Syntexa\Core\Contract\ResponseInterface;
use Syntexa\Core\Response;
use Syntexa\Inspector\InspectorModule;

#[AsRequestHandler(for: ProfilerRequest::class)]
class ProfilerHandler
{
    public function __construct(
        private ?InspectorModule $inspector = null
    ) {
    }

    public function handle(RequestInterface $request, ResponseInterface $response = null): ResponseInterface
    {
        if (!$this->inspector) {
            return Response::json(['events' => [], 'total' => 0]);
        }

        $history = $this->inspector->getHistory();
        
        // Sort by timestamp (most recent first)
        usort($history, function ($a, $b) {
            return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
        });

        // Limit results
        /** @var ProfilerRequest $request */
        $limit = $request->limit ?? 50;
        $events = array_slice($history, 0, $limit);

        // Format events for display
        $formattedEvents = array_map(function ($event) {
            return [
                'id' => $event['id'] ?? null,
                'type' => $event['type'] ?? 'unknown',
                'timestamp' => $event['timestamp'] ?? 0,
                'time' => isset($event['timestamp']) 
                    ? date('Y-m-d H:i:s', (int)$event['timestamp']) . '.' . substr((string)($event['timestamp'] - (int)$event['timestamp']), 2, 3)
                    : null,
                'payload' => $event['payload'] ?? [],
                'segments' => $event['segments'] ?? [],
                'duration' => $this->calculateTotalDuration($event['segments'] ?? []),
            ];
        }, $events);

        $data = [
            'events' => $formattedEvents,
            'total' => count($history)
        ];
        
        return Response::json($data);
    }

    private function calculateTotalDuration(array $segments): ?float
    {
        if (empty($segments)) {
            return null;
        }

        $durations = array_filter(
            array_column($segments, 'payload'),
            fn($payload) => isset($payload['duration'])
        );

        if (empty($durations)) {
            return null;
        }

        return array_sum(array_column($durations, 'duration'));
    }
}
