<?php

declare(strict_types=1);

namespace Syntexa\DevTools\Application;

use Syntexa\Core\Attributes\AsRequestHandler;
use Syntexa\Core\Contract\RequestInterface;
use Syntexa\Core\Contract\ResponseInterface;
use Syntexa\Core\Environment;
use Syntexa\Core\Response;

#[AsRequestHandler(for: LogsRequest::class)]
class LogsHandler
{
    public function __construct(
        private Environment $environment
    ) {
    }

    public function handle(RequestInterface $request, ResponseInterface $response = null): ResponseInterface
    {
        // Get project root (assuming we're in packages/syntexa/dev-tools/src/Application)
        $projectRoot = dirname(__DIR__, 5);
        $logDir = $projectRoot . "/var/log";
        $files = $this->getLogFiles($logDir);
        
        $currentFile = $request->file ?? ($files[0] ?? 'error.log');
        $logPath = $logDir . '/' . $currentFile;

        $logs = [];
        if (file_exists($logPath) && is_readable($logPath)) {
            /** @var LogsRequest $request */
            $logs = $this->readLogFile($logPath, $request->lines ?? 100, $request->filter ?? null);
        }

        $data = [
            'logs' => $logs,
            'files' => $files,
            'currentFile' => $currentFile,
            'totalLines' => count($logs)
        ];
        
        return Response::json($data);
    }

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

    private function readLogFile(string $filePath, int $lines, ?string $filter): array
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return [];
        }

        // Read file in reverse to get last N lines
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
            $buffer = array_shift($linesArray); // Keep incomplete line in buffer

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

                $result[] = [
                    'line' => $line,
                    'timestamp' => $this->extractTimestamp($line),
                ];

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
                array_unshift($result, [
                    'line' => $line,
                    'timestamp' => $this->extractTimestamp($line),
                ]);
            }
        }

        fclose($handle);

        // Reverse to get chronological order
        return array_reverse($result);
    }

    private function extractTimestamp(string $line): ?string
    {
        // Try to extract timestamp from common log formats
        if (preg_match('/\[(\d{4}-\d{2}-\d{2}[T\s]\d{2}:\d{2}:\d{2}[.\d]*[Z]?)\]/', $line, $matches)) {
            return $matches[1];
        }
        if (preg_match('/(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})/', $line, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
