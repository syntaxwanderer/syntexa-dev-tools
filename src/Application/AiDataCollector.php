<?php

declare(strict_types=1);

namespace Syntexa\DevTools\Application;

use Syntexa\Core\Environment;
use Syntexa\Core\ModuleRegistry;
use Syntexa\Core\Discovery\AttributeDiscovery;
use Syntexa\Core\IntelligentAutoloader;
use Syntexa\Inspector\InspectorModule;
use Syntexa\Inspector\Profiler;
use Syntexa\Orm\Blockchain\BlockchainConfig;
use PDO;

class AiDataCollector
{
    private ?string $projectRoot = null;

    public function __construct(
        private Environment $environment,
        private ?InspectorModule $inspector = null
    ) {
        // Try to get inspector from Profiler if not injected
        if ($this->inspector === null) {
            $this->inspector = $this->getInspectorFromProfiler();
        }
    }

    /**
     * Try to get InspectorModule from Profiler static instance using reflection
     */
    private function getInspectorFromProfiler(): ?InspectorModule
    {
        try {
            $reflection = new \ReflectionClass(Profiler::class);
            $property = $reflection->getStaticPropertyValue('inspector');
            if ($property instanceof InspectorModule) {
                return $property;
            }
        } catch (\Throwable $e) {
            // Ignore reflection errors - inspector may not be set yet
        }
        return null;
    }

    public function collect(): array
    {
        return [
            'meta' => $this->collectMeta(),
            'metrics' => $this->collectMetrics(),
            'requests' => $this->collectRequests(50),
            'errors' => $this->collectErrors(),
            'logs' => $this->collectLogs(100),
            'profiler' => $this->collectProfiler(),
            'system' => $this->collectSystem(),
            'blockchain' => $this->collectBlockchain(),
            'database' => $this->collectDatabase(),
            'rabbitmq' => $this->collectRabbitMQ(),
            'recommendations' => $this->generateRecommendations(),
        ];
    }

    private function collectMeta(): array
    {
        $appNameSlug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $this->environment->appName ?? 'unknown')));
        // Get project root (same approach as MetricsHandler)
        $projectRoot = dirname(__DIR__, 5);
        $statsFile = $projectRoot . "/var/server-stats-{$appNameSlug}.json";
        
        $uptime = 0;
        // Use same approach as MetricsHandler
        $appStats = file_exists($statsFile) 
            ? json_decode(file_get_contents($statsFile), true) 
            : [];
        
        if (is_array($appStats)) {
            // Use uptime from file if available (it's updated on each request)
            if (isset($appStats['uptime'])) {
                $uptime = (int)$appStats['uptime'];
            } elseif (isset($appStats['start_time'])) {
                // Fallback: calculate from start_time
                $uptime = time() - (int)$appStats['start_time'];
            }
        }

        return [
            'timestamp' => (new \DateTimeImmutable())->format('c'),
            'server' => [
                'name' => $this->environment->appName ?? 'Unknown',
                'version' => '1.0.0', // TODO: Get from composer.json or version file
                'uptime' => $uptime,
                'environment' => $this->environment->isDev() ? 'development' : 'production',
                'php_version' => PHP_VERSION,
                'swoole_version' => function_exists('swoole_version') ? swoole_version() : null,
            ],
        ];
    }

    private function collectMetrics(): array
    {
        $appNameSlug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $this->environment->appName ?? 'unknown')));
        $projectRoot = $this->getProjectRoot();
        $statsFile = $projectRoot . "/var/server-stats-{$appNameSlug}.json";
        $swooleStatsFile = $projectRoot . "/var/swoole-stats-{$appNameSlug}.json";

        // Use same approach as MetricsHandler
        $swooleStats = file_exists($swooleStatsFile) 
            ? json_decode(file_get_contents($swooleStatsFile), true) 
            : [];
        
        $appStats = file_exists($statsFile) 
            ? json_decode(file_get_contents($statsFile), true) 
            : [];
        
        // Ensure arrays
        if (!is_array($swooleStats)) {
            $swooleStats = [];
        }
        if (!is_array($appStats)) {
            $appStats = [];
        }

        // Calculate uptime for per_second calculation
        $uptime = $appStats['uptime'] ?? 0;
        $totalRequests = $swooleStats['request_count'] ?? 0;
        $perSecond = $uptime > 0 ? round($totalRequests / $uptime, 2) : 0;

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
                'total' => $totalRequests,
                'per_second' => $perSecond,
            ],
            'coroutines' => [
                'active' => $swooleStats['coroutine_num'] ?? 0,
            ],
        ];

        // Format application metrics
        $totalAppRequests = $appStats['requests'] ?? 0;
        $totalErrors = $appStats['errors'] ?? 0;
        $totalSuccess = max(0, $totalAppRequests - $totalErrors);
        
        // Calculate average response time from inspector history
        $avgResponseTime = $this->calculateAverageResponseTime();

        $application = [
            'requests' => [
                'total' => $totalAppRequests,
                'errors' => $totalErrors,
                'success' => $totalSuccess,
            ],
            'uptime' => $uptime,
            'average_response_time' => $avgResponseTime,
        ];

        // Memory metrics
        $memoryLimit = $this->getMemoryLimit();
        $memory = [
            'current' => $swooleStats['memory_total'] ?? memory_get_usage(true),
            'peak' => $swooleStats['memory_peak'] ?? memory_get_peak_usage(true),
            'limit' => $memoryLimit,
        ];

        return [
            'swoole' => $swoole,
            'application' => $application,
            'memory' => $memory,
        ];
    }

    /**
     * Calculate average response time from inspector history
     */
    private function calculateAverageResponseTime(): float
    {
        if (!$this->inspector) {
            return 0.0;
        }

        $history = $this->inspector->getHistory();
        $httpRequests = array_filter($history, fn($event) => 
            $event['type'] === 'http_request' && isset($event['payload']['duration'])
        );

        if (empty($httpRequests)) {
            return 0.0;
        }

        $totalDuration = array_sum(array_map(fn($event) => 
            (float)($event['payload']['duration'] ?? 0), $httpRequests
        ));

        return round($totalDuration / count($httpRequests), 2);
    }

    /**
     * Get memory limit in bytes
     */
    private function getMemoryLimit(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }

        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int)$limit;

        switch ($last) {
            case 'g':
                $value *= 1024;
                // no break
            case 'm':
                $value *= 1024;
                // no break
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    private function collectRequests(int $limit): array
    {
        if (!$this->inspector) {
            return [];
        }

        $history = $this->inspector->getHistory();
        
        // Filter only http_request events
        $requests = array_filter($history, fn($event) => 
            $event['type'] === 'http_request'
        );
        
        // Sort by timestamp DESC (newest first)
        usort($requests, fn($a, $b) => 
            ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0)
        );
        
        // Take last $limit
        $requests = array_slice($requests, 0, $limit);
        
        // Format data
        return array_map(fn($event) => $this->formatRequest($event), $requests);
    }

    /**
     * Format request event for AI response
     */
    private function formatRequest(array $event): array
    {
        $payload = $event['payload'] ?? [];
        
        return [
            'id' => $event['id'] ?? '',
            'timestamp' => $event['timestamp'] ?? 0,
            'method' => $payload['method'] ?? 'GET',
            'path' => $payload['path'] ?? '/',
            'status' => $payload['status'] ?? 200,
            'duration_ms' => $payload['duration'] ?? 0,
            'memory_bytes' => $payload['memory'] ?? 0,
            'headers' => [
                'request' => $payload['request_headers'] ?? [],
                'response' => $payload['response_headers'] ?? [],
            ],
            'query' => $payload['query'] ?? [],
            'segments' => $this->formatSegments($event['segments'] ?? []),
        ];
    }

    /**
     * Format profiler segments
     */
    private function formatSegments(array $segments): array
    {
        return array_map(fn($segment) => [
            'type' => $segment['type'] ?? 'unknown',
            'timestamp' => $segment['timestamp'] ?? 0,
            'payload' => $segment['payload'] ?? [],
        ], $segments);
    }

    private function collectErrors(): array
    {
        if (!$this->inspector) {
            return [];
        }

        $history = $this->inspector->getHistory();
        
        // Filter errors (status >= 400) from http_request events
        $errors = array_filter($history, fn($event) => 
            $event['type'] === 'http_request' && 
            (($event['payload']['status'] ?? 200) >= 400)
        );
        
        // Sort by timestamp DESC (newest first)
        usort($errors, fn($a, $b) => 
            ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0)
        );
        
        // Format errors with stack traces if available
        return array_map(fn($event) => $this->formatError($event), $errors);
    }

    /**
     * Format error event for AI response
     */
    private function formatError(array $event): array
    {
        $payload = $event['payload'] ?? [];
        
        $error = [
            'id' => $event['id'] ?? '',
            'timestamp' => $event['timestamp'] ?? 0,
            'type' => $event['type'] ?? 'http_request',
            'method' => $payload['method'] ?? 'GET',
            'path' => $payload['path'] ?? '/',
            'status' => $payload['status'] ?? 500,
            'duration_ms' => $payload['duration'] ?? 0,
        ];

        // Try to extract error information from payload or segments
        $errorMessage = null;
        $errorFile = null;
        $errorLine = null;
        $errorTrace = [];

        // Check if error info is in payload
        if (isset($payload['error'])) {
            $errorData = is_array($payload['error']) ? $payload['error'] : ['message' => $payload['error']];
            $errorMessage = $errorData['message'] ?? null;
            $errorFile = $errorData['file'] ?? null;
            $errorLine = $errorData['line'] ?? null;
            $errorTrace = $errorData['trace'] ?? [];
        }

        // Check segments for error information
        $segments = $event['segments'] ?? [];
        foreach ($segments as $segment) {
            if (($segment['type'] ?? '') === 'error' || ($segment['type'] ?? '') === 'exception') {
                $segmentPayload = $segment['payload'] ?? [];
                if (!$errorMessage && isset($segmentPayload['message'])) {
                    $errorMessage = $segmentPayload['message'];
                }
                if (!$errorFile && isset($segmentPayload['file'])) {
                    $errorFile = $segmentPayload['file'];
                }
                if (!$errorLine && isset($segmentPayload['line'])) {
                    $errorLine = $segmentPayload['line'];
                }
                if (empty($errorTrace) && isset($segmentPayload['trace'])) {
                    $errorTrace = $segmentPayload['trace'];
                }
            }
        }

        // If no error details found, try to extract from response headers or body
        if (!$errorMessage) {
            $responseHeaders = $payload['response_headers'] ?? [];
            // Could check for error in response, but for now just use status
            $errorMessage = "HTTP {$error['status']} Error";
        }

        $error['error'] = [
            'message' => $errorMessage,
            'code' => $this->extractErrorCode($errorMessage),
            'file' => $errorFile,
            'line' => $errorLine,
            'trace' => $this->formatStackTrace($errorTrace),
            'context' => $this->extractErrorContext($payload, $segments),
        ];

        return $error;
    }

    /**
     * Extract error code from error message
     */
    private function extractErrorCode(string $message): ?string
    {
        // Try to extract SQL error codes (e.g., SQLSTATE[42P01])
        if (preg_match('/SQLSTATE\[([^\]]+)\]/', $message, $matches)) {
            return $matches[1];
        }
        // Try to extract HTTP status codes
        if (preg_match('/HTTP (\d+)/', $message, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Format stack trace for AI
     */
    private function formatStackTrace(array $trace): array
    {
        if (empty($trace)) {
            return [];
        }

        // If trace is already formatted, return as is
        if (isset($trace[0]) && is_array($trace[0]) && isset($trace[0]['file'])) {
            return $trace;
        }

        // If trace is string, try to parse it
        if (is_string($trace[0] ?? null)) {
            // Simple parsing - could be improved
            return array_map(fn($line) => ['line' => $line], $trace);
        }

        return [];
    }

    /**
     * Extract error context (queries, parameters, etc.)
     */
    private function extractErrorContext(array $payload, array $segments): array
    {
        $context = [];

        // Look for database queries in segments
        foreach ($segments as $segment) {
            if (($segment['type'] ?? '') === 'database_query') {
                $segmentPayload = $segment['payload'] ?? [];
                if (isset($segmentPayload['query'])) {
                    $context['query'] = $segmentPayload['query'];
                }
                if (isset($segmentPayload['params'])) {
                    $context['params'] = $segmentPayload['params'];
                }
            }
        }

        return $context;
    }

    private function collectLogs(int $lines): array
    {
        $projectRoot = $this->getProjectRoot();
        $logDir = $projectRoot . '/var/log';
        
        if (!is_dir($logDir)) {
            return [
                'recent' => [],
                'files' => [],
                'total_lines' => 0,
            ];
        }

        // Get all .log files
        $files = $this->getLogFiles($logDir);
        
        // Read recent lines from all log files
        $recentLogs = [];
        foreach ($files as $file) {
            $logPath = $logDir . '/' . $file;
            if (file_exists($logPath) && is_readable($logPath)) {
                $fileLogs = $this->readLogFile($logPath, $lines, null);
                foreach ($fileLogs as $log) {
                    $recentLogs[] = $this->parseLogLine($log['line'] ?? '', $file);
                }
            }
        }

        // Sort by timestamp DESC (newest first)
        usort($recentLogs, fn($a, $b) => 
            ($b['timestamp'] ?? '') <=> ($a['timestamp'] ?? '')
        );

        // Take last $lines
        $recentLogs = array_slice($recentLogs, 0, $lines);

        return [
            'recent' => $recentLogs,
            'files' => $files,
            'total_lines' => count($recentLogs),
        ];
    }

    /**
     * Get list of log files
     */
    private function getLogFiles(string $logDir): array
    {
        if (!is_dir($logDir)) {
            return [];
        }

        $files = [];
        $items = scandir($logDir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $logDir . '/' . $item;
            if (is_file($path) && pathinfo($path, PATHINFO_EXTENSION) === 'log') {
                $files[] = $item;
            }
        }

        rsort($files); // Most recent first
        return $files;
    }

    /**
     * Read last N lines from log file
     */
    private function readLogFile(string $filePath, int $lines, ?string $filter): array
    {
        $handle = @fopen($filePath, 'r');
        if (!$handle) {
            return [];
        }

        $fileSize = filesize($filePath);
        if ($fileSize === 0) {
            fclose($handle);
            return [];
        }

        $buffer = '';
        $lineCount = 0;
        $result = [];
        $chunkSize = min(8192, $fileSize);

        // Start from end of file
        fseek($handle, -$chunkSize, SEEK_END);
        $readSize = $chunkSize;

        while ($lineCount < $lines && ftell($handle) > 0) {
            $data = fread($handle, $readSize);
            $buffer = $data . $buffer;

            // Process complete lines
            $linesArray = explode("\n", $buffer);
            $buffer = array_shift($linesArray);

            // Process lines in reverse
            for ($i = count($linesArray) - 1; $i >= 0; $i--) {
                $line = trim($linesArray[$i]);
                if (empty($line)) {
                    continue;
                }

                // Apply filter if provided
                if ($filter && stripos($line, $filter) === false) {
                    continue;
                }

                $result[] = ['line' => $line];
                $lineCount++;
                if ($lineCount >= $lines) {
                    break;
                }
            }

            // Move back further
            if (ftell($handle) > 0) {
                $newPos = max(0, ftell($handle) - $chunkSize);
                fseek($handle, $newPos);
                $readSize = ftell($handle) > 0 ? $chunkSize : ftell($handle) + $chunkSize;
            }
        }

        // Add remaining buffer if any
        if ($lineCount < $lines && !empty(trim($buffer))) {
            $line = trim($buffer);
            if ($filter === null || stripos($line, $filter) !== false) {
                array_unshift($result, ['line' => $line]);
            }
        }

        fclose($handle);

        // Reverse to get chronological order
        return array_reverse($result);
    }

    /**
     * Parse log line into structured format
     */
    private function parseLogLine(string $line, string $file): array
    {
        $timestamp = $this->extractTimestamp($line);
        $level = $this->extractLogLevel($line);
        
        return [
            'timestamp' => $timestamp ?: (new \DateTimeImmutable())->format('c'),
            'level' => $level,
            'message' => $this->extractLogMessage($line),
            'context' => $this->extractLogContext($line),
            'file' => $file,
        ];
    }

    /**
     * Extract timestamp from log line
     */
    private function extractTimestamp(string $line): ?string
    {
        // Try various timestamp formats
        if (preg_match('/\[(\d{4}-\d{2}-\d{2}[T\s]\d{2}:\d{2}:\d{2}[.\d]*[Z]?)\]/', $line, $matches)) {
            return $matches[1];
        }
        if (preg_match('/(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})/', $line, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Extract log level from log line
     */
    private function extractLogLevel(string $line): string
    {
        $levels = ['ERROR', 'WARNING', 'WARN', 'INFO', 'DEBUG', 'TRACE'];
        foreach ($levels as $level) {
            if (stripos($line, $level) !== false) {
                return strtoupper($level);
            }
        }
        return 'INFO';
    }

    /**
     * Extract log message (remove timestamp and level)
     */
    private function extractLogMessage(string $line): string
    {
        // Remove timestamp patterns
        $line = preg_replace('/\[\d{4}-\d{2}-\d{2}[T\s]\d{2}:\d{2}:\d{2}[.\d]*[Z]?\]/', '', $line);
        $line = preg_replace('/\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}/', '', $line);
        
        // Remove log level
        $line = preg_replace('/\b(ERROR|WARNING|WARN|INFO|DEBUG|TRACE)\b/i', '', $line);
        
        return trim($line);
    }

    /**
     * Extract context from log line (file, line, etc.)
     */
    private function extractLogContext(string $line): array
    {
        $context = [];

        // Try to extract file and line
        if (preg_match('/([\/\w]+\.php)(?::(\d+))?/', $line, $matches)) {
            $context['file'] = $matches[1];
            if (isset($matches[2])) {
                $context['line'] = (int)$matches[2];
            }
        }

        // Try to extract function
        if (preg_match('/->(\w+)\(/', $line, $matches)) {
            $context['function'] = $matches[1];
        }

        return $context;
    }

    private function collectProfiler(): array
    {
        if (!$this->inspector) {
            return [
                'events' => [],
                'statistics' => [],
            ];
        }

        $history = $this->inspector->getHistory();
        
        // Sort by timestamp DESC (newest first)
        usort($history, fn($a, $b) => 
            ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0)
        );

        // Get last 50 events
        $events = array_slice($history, 0, 50);
        
        // Format events
        $formattedEvents = array_map(fn($event) => [
            'id' => $event['id'] ?? '',
            'type' => $event['type'] ?? 'unknown',
            'timestamp' => $event['timestamp'] ?? 0,
            'payload' => $event['payload'] ?? [],
            'segments' => $this->formatSegments($event['segments'] ?? []),
            'duration' => $this->calculateTotalDuration($event['segments'] ?? []),
        ], $events);

        // Calculate statistics
        $statistics = $this->calculateProfilerStatistics($history);

        return [
            'events' => $formattedEvents,
            'statistics' => $statistics,
        ];
    }

    /**
     * Calculate total duration from segments
     */
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

    /**
     * Calculate profiler statistics
     */
    private function calculateProfilerStatistics(array $history): array
    {
        $totalEvents = count($history);
        $eventTypes = [];
        $totalDuration = 0;
        $eventCount = 0;

        foreach ($history as $event) {
            $type = $event['type'] ?? 'unknown';
            $eventTypes[$type] = ($eventTypes[$type] ?? 0) + 1;
            
            $duration = $this->calculateTotalDuration($event['segments'] ?? []);
            if ($duration !== null) {
                $totalDuration += $duration;
                $eventCount++;
            }
        }

        return [
            'total_events' => $totalEvents,
            'event_types' => $eventTypes,
            'average_duration_ms' => $eventCount > 0 ? round($totalDuration / $eventCount, 2) : 0,
            'total_duration_ms' => round($totalDuration, 2),
        ];
    }

    private function collectSystem(): array
    {
        // Get module registry information
        $modules = $this->collectModules();
        
        // Get routes information
        $routes = $this->collectRoutes();
        
        // Get autoloader information
        $autoloader = $this->collectAutoloader();
        
        // Get cache information
        $cache = $this->collectCache();

        return [
            'cache' => $cache,
            'autoloader' => $autoloader,
            'modules' => $modules,
            'routes' => $routes,
        ];
    }

    /**
     * Collect module information
     */
    private function collectModules(): array
    {
        try {
            ModuleRegistry::initialize();
            $allModules = ModuleRegistry::getModules();
            
            return array_map(fn($module) => [
                'name' => $module['name'] ?? 'unknown',
                'type' => $module['type'] ?? 'unknown',
                'namespace' => $module['namespace'] ?? '',
                'active' => $module['config']['active'] ?? true,
                'role' => $module['config']['role'] ?? 'observer',
            ], $allModules);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Collect routes information
     */
    private function collectRoutes(): array
    {
        try {
            AttributeDiscovery::initialize();
            $allRoutes = AttributeDiscovery::getRoutes();
            
            return array_map(fn($route) => [
                'path' => $route['path'] ?? '',
                'method' => $route['method'] ?? 'GET',
                'handler' => $route['handler'] ?? '',
                'request' => $route['request'] ?? '',
            ], $allRoutes);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Collect autoloader information
     */
    private function collectAutoloader(): array
    {
        try {
            IntelligentAutoloader::initialize();
            $mappings = ModuleRegistry::getModuleAutoloadMappings();
            
            return [
                'mappings_count' => count($mappings),
                'namespaces' => array_keys($mappings),
            ];
        } catch (\Throwable $e) {
            return [
                'mappings_count' => 0,
                'namespaces' => [],
            ];
        }
    }

    /**
     * Collect cache information
     */
    private function collectCache(): array
    {
        $projectRoot = $this->getProjectRoot();
        $cacheDir = $projectRoot . '/var/cache';
        
        if (!is_dir($cacheDir)) {
            return [
                'enabled' => false,
                'directory' => $cacheDir,
                'files_count' => 0,
                'size_bytes' => 0,
            ];
        }

        $files = glob($cacheDir . '/**/*', GLOB_BRACE);
        $files = array_filter($files, 'is_file');
        $totalSize = 0;
        foreach ($files as $file) {
            $totalSize += filesize($file);
        }

        return [
            'enabled' => true,
            'directory' => $cacheDir,
            'files_count' => count($files),
            'size_bytes' => $totalSize,
        ];
    }

    private function collectBlockchain(): ?array
    {
        try {
            $config = BlockchainConfig::fromEnv();
            
            if (!$config->enabled) {
                return null;
            }

            $nodeId = $config->nodeId ?? 'unknown';
            $participants = $config->participants ?? [];
            
            // Get transaction count from blockchain DB if available
            $totalTransactions = 0;
            $recentTransactions = [];
            
            if ($config->hasBlockchainDb()) {
                try {
                    $pdo = $this->getBlockchainConnection($config);
                    $stmt = $pdo->query('SELECT COUNT(*) as count FROM blockchain_transactions');
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $totalTransactions = (int)($result['count'] ?? 0);
                    
                    // Get recent transactions
                    $stmt = $pdo->query('SELECT * FROM blockchain_transactions ORDER BY created_at DESC LIMIT 10');
                    $recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (\Throwable $e) {
                    // Ignore DB errors
                }
            }

            return [
                'enabled' => true,
                'node_id' => $nodeId,
                'participants' => $participants,
                'total_transactions' => $totalTransactions,
                'nodes' => $this->getBlockchainNodes($config),
                'recent_transactions' => array_map(fn($tx) => [
                    'transaction_id' => $tx['transaction_id'] ?? '',
                    'node_id' => $tx['node_id'] ?? '',
                    'entity_class' => $tx['entity_class'] ?? '',
                    'entity_id' => $tx['entity_id'] ?? 0,
                    'operation' => $tx['operation'] ?? '',
                    'timestamp' => $tx['timestamp'] ?? '',
                ], $recentTransactions),
                'sync_issues' => [],
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get blockchain connection
     */
    private function getBlockchainConnection(BlockchainConfig $config): PDO
    {
        $host = $config->dbHost ?? 'localhost';
        $port = $config->dbPort ?? 5432;
        $dbname = $config->dbName ?? 'syntexa_blockchain';
        $user = $config->dbUser ?? 'postgres';
        $password = $config->dbPassword ?? '';

        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $dbname);
        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * Get blockchain nodes information
     */
    private function getBlockchainNodes(BlockchainConfig $config): array
    {
        $nodes = [];
        $participants = $config->participants ?? [];
        
        foreach ($participants as $participant) {
            $nodes[] = [
                'id' => $participant,
                'status' => 'active', // TODO: Check actual status
                'last_activity' => null, // TODO: Get from DB
                'transactions_count' => 0, // TODO: Get from DB
                'sync_status' => 'synced', // TODO: Check sync status
                'db_name' => $config->dbName ?? '',
            ];
        }
        
        return $nodes;
    }

    private function collectDatabase(): array
    {
        try {
            // Get main database connection info from environment
            $dbHost = $_ENV['DB_HOST'] ?? 'localhost';
            $dbPort = (int)($_ENV['DB_PORT'] ?? 5432);
            $dbName = $_ENV['DB_NAME'] ?? 'syntexa';
            
            // Get blockchain databases
            $blockchainDbs = [];
            $config = BlockchainConfig::fromEnv();
            if ($config->enabled && $config->hasBlockchainDb()) {
                $blockchainDbs[] = [
                    'node_id' => $config->nodeId ?? 'unknown',
                    'name' => $config->dbName ?? '',
                    'transactions_count' => 0, // TODO: Get from DB
                ];
            }

            return [
                'main_db' => [
                    'driver' => 'pgsql',
                    'host' => $dbHost,
                    'port' => $dbPort,
                    'name' => $dbName,
                    'connection_pool' => [
                        'active' => 0, // TODO: Get from ConnectionPool
                        'idle' => 0,
                        'max' => 10,
                    ],
                    'query_stats' => [
                        'total' => 0, // TODO: Track queries
                        'slow_queries' => 0,
                    ],
                ],
                'blockchain_dbs' => $blockchainDbs,
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function collectRabbitMQ(): ?array
    {
        try {
            $config = BlockchainConfig::fromEnv();
            
            if (!$config->enabled || !$config->hasRabbitMQ()) {
                return null;
            }

            return [
                'enabled' => true,
                'host' => $config->rabbitmqHost ?? 'localhost',
                'port' => $config->rabbitmqPort ?? 5672,
                'vhost' => $config->rabbitmqVhost ?? '/',
                'exchanges' => [
                    [
                        'name' => $config->rabbitmqExchange ?? 'syntexa_blockchain',
                        'type' => 'fanout',
                        'messages_published' => 0, // TODO: Track published messages
                    ],
                ],
                'queues' => [
                    [
                        'name' => 'blockchain.' . ($config->nodeId ?? 'unknown'),
                        'messages_ready' => 0, // TODO: Get from RabbitMQ API
                        'messages_unacked' => 0,
                        'consumers' => 0,
                    ],
                ],
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function generateRecommendations(): array
    {
        $recommendations = [];
        
        // Check memory usage
        $memory = $this->collectMetrics()['memory'] ?? [];
        $memoryLimit = $memory['limit'] ?? PHP_INT_MAX;
        $memoryCurrent = $memory['current'] ?? 0;
        if ($memoryLimit > 0 && $memoryCurrent > 0) {
            $memoryPercent = ($memoryCurrent / $memoryLimit) * 100;
            if ($memoryPercent > 80) {
                $recommendations[] = [
                    'type' => 'warning',
                    'category' => 'memory',
                    'message' => "Memory usage is high: " . round($memoryPercent, 1) . "%",
                    'action' => 'Consider optimizing memory usage or increasing memory limit',
                ];
            }
        }
        
        // Check error rate
        $metrics = $this->collectMetrics();
        $appMetrics = $metrics['application'] ?? [];
        $totalRequests = $appMetrics['requests']['total'] ?? 0;
        $totalErrors = $appMetrics['requests']['errors'] ?? 0;
        if ($totalRequests > 0) {
            $errorRate = ($totalErrors / $totalRequests) * 100;
            if ($errorRate > 5) {
                $recommendations[] = [
                    'type' => 'error',
                    'category' => 'errors',
                    'message' => "High error rate: " . round($errorRate, 1) . "%",
                    'action' => 'Review error logs and fix issues',
                ];
            }
        }
        
        // Check blockchain sync
        $blockchain = $this->collectBlockchain();
        if ($blockchain !== null && !empty($blockchain['sync_issues'])) {
            $recommendations[] = [
                'type' => 'warning',
                'category' => 'blockchain',
                'message' => 'Blockchain sync issues detected',
                'action' => 'Check blockchain synchronization between nodes',
            ];
        }
        
        return $recommendations;
    }

    /**
     * Get project root directory
     * Uses the same logic as MetricsHandler
     */
    private function getProjectRoot(): string
    {
        // Use the same approach as MetricsHandler - don't cache, calculate each time
        // Assuming we're in packages/syntexa/dev-tools/src/Application
        return dirname(__DIR__, 5);
    }

}
