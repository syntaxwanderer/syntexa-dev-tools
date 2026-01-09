<?php

declare(strict_types=1);

namespace Syntexa\DevTools\Application;

use Syntexa\Core\Attributes\AsRequestHandler;
use Syntexa\Core\Contract\RequestInterface;
use Syntexa\Core\Contract\ResponseInterface;
use Syntexa\Core\Environment;
use Syntexa\Core\Response;

#[AsRequestHandler(for: MetricsRequest::class)]
class MetricsHandler
{
    public function __construct(
        private Environment $environment
    ) {
    }

    public function handle(RequestInterface $request, ResponseInterface $response = null): ResponseInterface
    {
        // Request is already validated as MetricsRequest by the framework
        $appNameSlug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $this->environment->appName)));
        // Get project root (assuming we're in packages/syntexa/dev-tools/src/Application)
        $projectRoot = dirname(__DIR__, 5);
        $statsFile = $projectRoot . "/var/server-stats-{$appNameSlug}.json";
        $swooleStatsFile = $projectRoot . "/var/swoole-stats-{$appNameSlug}.json";

        $swooleStats = file_exists($swooleStatsFile) 
            ? json_decode(file_get_contents($swooleStatsFile), true) 
            : [];
        
        $appStats = file_exists($statsFile) 
            ? json_decode(file_get_contents($statsFile), true) 
            : [];

        // Format Swoole metrics
        $swoole = [
            'connections' => [
                'active' => $swooleStats['connection_num'] ?? 0,
                'total' => $swooleStats['connection_num'] ?? 0,
            ],
            'workers' => [
                'active' => $swooleStats['worker_num'] ?? 0,
                'idle' => $swooleStats['idle_worker_num'] ?? 0,
                'total' => $this->environment->swooleWorkerNum,
            ],
            'requests' => [
                'total' => $swooleStats['request_count'] ?? 0,
            ],
            'coroutines' => [
                'active' => $swooleStats['coroutine_num'] ?? 0,
            ],
        ];

        // Format application metrics
        $application = [
            'requests' => [
                'total' => $appStats['requests'] ?? 0,
                'errors' => $appStats['errors'] ?? 0,
            ],
            'uptime' => [
                'seconds' => $appStats['uptime'] ?? 0,
                'formatted' => $this->formatUptime($appStats['uptime'] ?? 0),
            ],
        ];

        // Memory metrics
        $memory = [
            'current' => [
                'bytes' => $swooleStats['memory_total'] ?? memory_get_usage(true),
                'formatted' => $this->formatBytes($swooleStats['memory_total'] ?? memory_get_usage(true)),
            ],
            'peak' => [
                'bytes' => $swooleStats['memory_peak'] ?? memory_get_peak_usage(true),
                'formatted' => $this->formatBytes($swooleStats['memory_peak'] ?? memory_get_peak_usage(true)),
            ],
        ];

        $data = [
            'swoole' => $swoole,
            'application' => $application,
            'memory' => $memory,
            'timestamp' => microtime(true)
        ];
        
        return Response::json($data);
    }

    private function formatUptime(int $seconds): string
    {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        $parts = [];
        if ($days > 0) $parts[] = "{$days}d";
        if ($hours > 0) $parts[] = "{$hours}h";
        if ($minutes > 0) $parts[] = "{$minutes}m";
        $parts[] = "{$secs}s";

        return implode(' ', $parts);
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
