<?php

declare(strict_types=1);

namespace Syntexa\DevTools\Application\Blockchain;

use PDO;
use Syntexa\Orm\Blockchain\BlockchainConfig;

/**
 * Blockchain Data Collector
 * 
 * Collects blockchain data from all nodes and provides unified interface
 */
class BlockchainDataCollector
{
    public function __construct(
        private BlockchainConfig $config
    ) {
    }

    /**
     * Get overview statistics
     */
    public function getOverview(): array
    {
        if (!$this->config->enabled) {
            return [
                'enabled' => false,
                'message' => 'Blockchain is not enabled',
            ];
        }

        $participants = $this->config->participants ?? [];
        $totalTransactions = 0;
        $transactionsByNode = [];
        $transactionsByOperation = [];
        $transactionsByEntity = [];

        // Collect data from current node's blockchain DB
        if ($this->config->hasBlockchainDb()) {
            try {
                $pdo = $this->getBlockchainConnection();
                
                // Total transactions
                $stmt = $pdo->query('SELECT COUNT(*) as count FROM blockchain_transactions');
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $totalTransactions = (int)($result['count'] ?? 0);

                // Transactions by node
                $stmt = $pdo->query('
                    SELECT node_id, COUNT(*) as count 
                    FROM blockchain_transactions 
                    GROUP BY node_id
                ');
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $transactionsByNode[$row['node_id']] = (int)$row['count'];
                }

                // Transactions by operation
                $stmt = $pdo->query('
                    SELECT operation, COUNT(*) as count 
                    FROM blockchain_transactions 
                    GROUP BY operation
                ');
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $transactionsByOperation[$row['operation']] = (int)$row['count'];
                }

                // Transactions by entity
                $stmt = $pdo->query('
                    SELECT entity_class, COUNT(*) as count 
                    FROM blockchain_transactions 
                    GROUP BY entity_class
                    ORDER BY count DESC
                    LIMIT 10
                ');
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $transactionsByEntity[$row['entity_class']] = (int)$row['count'];
                }
            } catch (\Throwable $e) {
                // Ignore DB errors
            }
        }

        return [
            'enabled' => true,
            'meta' => [
                'timestamp' => date('c'),
                'total_nodes' => count($participants),
                'active_nodes' => count($participants), // TODO: Check actual status
                'current_node' => $this->config->nodeId ?? 'unknown',
            ],
            'statistics' => [
                'total_transactions' => $totalTransactions,
                'transactions_by_node' => $transactionsByNode,
                'transactions_by_operation' => $transactionsByOperation,
                'transactions_by_entity' => $transactionsByEntity,
            ],
            'sync_status' => [
                'synced_nodes' => count($participants), // TODO: Check actual sync status
                'out_of_sync' => 0,
                'last_sync_check' => date('c'),
            ],
        ];
    }

    /**
     * Get nodes information
     */
    public function getNodes(): array
    {
        if (!$this->config->enabled) {
            return ['nodes' => []];
        }

        $participants = $this->config->participants ?? [];
        $nodes = [];

        foreach ($participants as $nodeId) {
            $nodeData = $this->getNodeData($nodeId);
            $nodes[] = $nodeData;
        }

        return ['nodes' => $nodes];
    }

    /**
     * Get node data
     */
    private function getNodeData(string $nodeId): array
    {
        $totalTransactions = 0;
        $lastTransaction = null;

        // Try to get data from blockchain DB
        if ($this->config->hasBlockchainDb()) {
            try {
                $pdo = $this->getBlockchainConnection();
                
                // Count transactions for this node
                $stmt = $pdo->prepare('SELECT COUNT(*) as count FROM blockchain_transactions WHERE node_id = :node_id');
                $stmt->execute(['node_id' => $nodeId]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $totalTransactions = (int)($result['count'] ?? 0);

                // Get last transaction
                $stmt = $pdo->prepare('
                    SELECT * FROM blockchain_transactions 
                    WHERE node_id = :node_id 
                    ORDER BY created_at DESC 
                    LIMIT 1
                ');
                $stmt->execute(['node_id' => $nodeId]);
                $lastTx = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($lastTx) {
                    $lastTransaction = $lastTx['timestamp'] ?? $lastTx['created_at'] ?? null;
                }
            } catch (\Throwable $e) {
                // Ignore DB errors
            }
        }

        return [
            'id' => $nodeId,
            'name' => $this->getNodeName($nodeId),
            'url' => $this->getNodeUrl($nodeId),
            'status' => 'online', // TODO: Check actual status
            'blockchain_db' => $this->config->dbName ?? 'unknown',
            'statistics' => [
                'total_transactions' => $totalTransactions,
                'last_transaction' => $lastTransaction,
                'transactions_per_hour' => 0, // TODO: Calculate
            ],
            'rabbitmq' => [
                'connected' => true, // TODO: Check actual connection
                'queue' => 'blockchain.' . $nodeId,
                'messages_pending' => 0, // TODO: Get from RabbitMQ
            ],
        ];
    }

    /**
     * Get transactions
     */
    public function getTransactions(int $limit = 50, int $offset = 0, ?string $nodeId = null): array
    {
        if (!$this->config->enabled || !$this->config->hasBlockchainDb()) {
            return [
                'transactions' => [],
                'pagination' => [
                    'total' => 0,
                    'page' => 1,
                    'per_page' => $limit,
                ],
            ];
        }

        try {
            $pdo = $this->getBlockchainConnection();

            // Ensure schema exists (create table if needed)
            $this->ensureSchema($pdo);

            // Build query
            $where = [];
            $params = [];
            
            if ($nodeId) {
                $where[] = 'node_id = :node_id';
                $params['node_id'] = $nodeId;
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            // Get total count
            $countStmt = $pdo->prepare("SELECT COUNT(*) as count FROM blockchain_transactions $whereClause");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['count'];

            // Get transactions
            $stmt = $pdo->prepare("
                SELECT * FROM blockchain_transactions 
                $whereClause
                ORDER BY created_at DESC 
                LIMIT :limit OFFSET :offset
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            $stmt->execute();
            
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format transactions
            $formatted = array_map(function ($tx) {
                return [
                    'id' => $tx['transaction_id'] ?? '',
                    'transaction_id' => $tx['transaction_id'] ?? '',
                    'node_id' => $tx['node_id'] ?? '',
                    'entity_class' => $tx['entity_class'] ?? '',
                    'entity_id' => (int)($tx['entity_id'] ?? 0),
                    'operation' => $tx['operation'] ?? '',
                    'fields' => json_decode($tx['fields'] ?? '{}', true),
                    'timestamp' => $tx['timestamp'] ?? $tx['created_at'] ?? '',
                    'signature' => $tx['signature'] ?? null,
                    'block_id' => $tx['block_id'] ?? 'pending',
                    'block_height' => (int)($tx['block_height'] ?? 0),
                ];
            }, $transactions);

            return [
                'transactions' => $formatted,
                'pagination' => [
                    'total' => $total,
                    'page' => (int)floor($offset / $limit) + 1,
                    'per_page' => $limit,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'transactions' => [],
                'pagination' => [
                    'total' => 0,
                    'page' => 1,
                    'per_page' => $limit,
                ],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get blockchain connection
     */
    private function getBlockchainConnection(): PDO
    {
        $host = $this->config->dbHost ?? 'localhost';
        $port = $this->config->dbPort ?? 5432;
        $dbname = $this->config->dbName ?? 'syntexa_blockchain';
        $user = $this->config->dbUser ?? 'postgres';
        $password = $this->config->dbPassword ?? '';

        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $dbname);
        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * Get node name from node ID
     */
    private function getNodeName(string $nodeId): string
    {
        $names = [
            'blockchain-server' => 'Master Server (Blockchain)',
            'shop1' => 'Shop 1 (POS)',
            'office' => 'Office (CRM/ERP)',
            'blog' => 'Blog (Web)',
            'eshop' => 'Online Shop',
        ];

        return $names[$nodeId] ?? ucfirst($nodeId);
    }

    /**
     * Get node URL from node ID
     */
    private function getNodeUrl(string $nodeId): string
    {
        $urls = [
            'blockchain-server' => 'http://localhost:8080',
            'shop1' => 'http://localhost:8081',
            'office' => 'http://localhost:8083',
            'blog' => 'http://localhost:8082',
            'eshop' => 'http://localhost:8084',
        ];

        return $urls[$nodeId] ?? 'http://localhost';
    }

    /**
     * Ensure blockchain schema exists (create table if needed)
     */
    private function ensureSchema(PDO $pdo): void
    {
        static $initialized = false;
        if ($initialized) {
            return;
        }

        // Minimal schema: only blockchain_transactions table
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS blockchain_transactions (
    id BIGSERIAL PRIMARY KEY,
    transaction_id VARCHAR(64) UNIQUE NOT NULL,
    block_id VARCHAR(255) NOT NULL,
    block_height BIGINT NOT NULL,
    node_id VARCHAR(255) NOT NULL,
    entity_class VARCHAR(255) NOT NULL,
    entity_id INTEGER NOT NULL,
    operation VARCHAR(50) NOT NULL,
    fields JSONB NOT NULL,
    timestamp TIMESTAMP NOT NULL,
    nonce TEXT NOT NULL,
    signature TEXT,
    created_at TIMESTAMP DEFAULT NOW()
);
SQL);

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_blockchain_entity ON blockchain_transactions(entity_class, entity_id);');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_blockchain_height ON blockchain_transactions(block_height);');

        $initialized = true;
    }
}
