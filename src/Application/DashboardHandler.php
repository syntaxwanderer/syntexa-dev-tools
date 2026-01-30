<?php

declare(strict_types=1);

namespace Syntexa\DevTools\Application;

use Syntexa\Core\Attributes\AsRequestHandler;
use Syntexa\Core\Attributes\AsResponse;
use Syntexa\Core\Contract\RequestInterface;
use Syntexa\Core\Contract\ResponseInterface;
use Syntexa\Core\Environment;
use Syntexa\Core\Response;

#[AsRequestHandler(for: DashboardRequest::class)]
class DashboardHandler
{
    public function __construct(
        private Environment $environment
    ) {
    }

    public function handle(RequestInterface $request, ResponseInterface $response = null): ResponseInterface
    {
        // Request is already validated as DashboardRequest by the framework
        $appName = $this->environment->appName ?? 'Unknown';
        $html = $this->getDashboardHtml($appName);
        
        return Response::html($html);
    }

    private function getDashboardHtml(string $appName): string
    {
        $title = htmlspecialchars($appName ? "Syntexa Dev Tools - {$appName}" : "Syntexa Dev Tools");
        
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Syntexa Dev Tools</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #0d1117;
            color: #c9d1d9;
            line-height: 1.6;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        header {
            background: #161b22;
            border: 1px solid #30363d;
            border-radius: 6px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        h1 {
            color: #58a6ff;
            margin-bottom: 10px;
        }
        
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 1px solid #30363d;
        }
        
        .tab {
            padding: 10px 20px;
            background: transparent;
            border: none;
            color: #8b949e;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
        }
        
        .tab:hover {
            color: #c9d1d9;
        }
        
        .tab.active {
            color: #58a6ff;
            border-bottom-color: #58a6ff;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .card {
            background: #161b22;
            border: 1px solid #30363d;
            border-radius: 6px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .card h2 {
            color: #58a6ff;
            margin-bottom: 15px;
            font-size: 18px;
        }
        
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .metric {
            background: #0d1117;
            border: 1px solid #21262d;
            border-radius: 4px;
            padding: 15px;
        }
        
        .metric-label {
            color: #8b949e;
            font-size: 12px;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        
        .metric-value {
            color: #c9d1d9;
            font-size: 24px;
            font-weight: 600;
        }
        
        .metric-unit {
            color: #8b949e;
            font-size: 14px;
            margin-left: 5px;
        }
        
        .log-viewer {
            background: #0d1117;
            border: 1px solid #21262d;
            border-radius: 4px;
            padding: 15px;
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
            font-size: 12px;
            max-height: 600px;
            overflow-y: auto;
        }
        
        .log-line {
            padding: 4px 0;
            border-bottom: 1px solid #21262d;
        }
        
        .log-line:last-child {
            border-bottom: none;
        }
        
        .log-timestamp {
            color: #8b949e;
            margin-right: 10px;
        }
        
        .controls {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .controls input,
        .controls select {
            background: #0d1117;
            border: 1px solid #30363d;
            color: #c9d1d9;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .controls input:focus,
        .controls select:focus {
            outline: none;
            border-color: #58a6ff;
        }
        
        .profiler-event {
            background: #0d1117;
            border: 1px solid #21262d;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 10px;
        }
        
        .profiler-event-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        
        .profiler-event-type {
            color: #58a6ff;
            font-weight: 600;
        }
        
        .profiler-event-time {
            color: #8b949e;
            font-size: 12px;
        }
        
        .profiler-segments {
            margin-top: 10px;
            padding-left: 20px;
            border-left: 2px solid #30363d;
        }
        
        .profiler-segment {
            margin: 5px 0;
            color: #8b949e;
            font-size: 12px;
        }
        
        .status-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 8px;
        }
        
        .status-online {
            background: #3fb950;
        }
        
        .status-offline {
            background: #f85149;
        }
        
        .refresh-btn {
            background: #238636;
            border: 1px solid #2ea043;
            color: #fff;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .refresh-btn:hover {
            background: #2ea043;
        }
        
        .auto-refresh {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .auto-refresh input[type="checkbox"] {
            width: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🔧 
HTML;
        
        $html .= $title;
        
        $html .= <<<'HTML'
</h1>
            <div class="auto-refresh">
                <label>
                    <input type="checkbox" id="autoRefresh" checked>
                    Auto-refresh (2s)
                </label>
                <span class="status-indicator status-online" id="statusIndicator"></span>
                <span id="lastUpdate">Never</span>
            </div>
        </header>
        
        <div class="tabs">
            <button class="tab active" data-tab="metrics">Metrics</button>
            <button class="tab" data-tab="logs">Logs</button>
            <button class="tab" data-tab="profiler">Profiler</button>
            <button class="tab" data-tab="blockchain">Blockchain</button>
        </div>
        
        <div id="metrics" class="tab-content active">
            <div class="card">
                <h2>Swoole Metrics</h2>
                <div class="metrics-grid" id="swooleMetrics"></div>
            </div>
            
            <div class="card">
                <h2>Application Metrics</h2>
                <div class="metrics-grid" id="appMetrics"></div>
            </div>
            
            <div class="card">
                <h2>Memory</h2>
                <div class="metrics-grid" id="memoryMetrics"></div>
            </div>
        </div>
        
        <div id="logs" class="tab-content">
            <div class="card">
                <h2>Log Viewer</h2>
                <div class="controls">
                    <select id="logFile">
                        <option value="">Loading...</option>
                    </select>
                    <input type="text" id="logFilter" placeholder="Filter logs...">
                    <input type="number" id="logLines" value="100" min="10" max="1000" step="10">
                    <button class="refresh-btn" onclick="loadLogs()">Refresh</button>
                </div>
                <div class="log-viewer" id="logViewer"></div>
            </div>
        </div>
        
        <div id="profiler" class="tab-content">
            <div class="card">
                <h2>Profiler Events</h2>
                <div class="controls">
                    <input type="number" id="profilerLimit" value="50" min="10" max="200" step="10">
                    <button class="refresh-btn" onclick="loadProfiler()">Refresh</button>
                </div>
                <div id="profilerEvents"></div>
            </div>
        </div>
        
        <div id="blockchain" class="tab-content">
            <div class="card">
                <h2>Blockchain Overview</h2>
                <div class="controls">
                    <button class="refresh-btn" onclick="loadBlockchain()">Refresh</button>
                </div>
                <div id="blockchainOverview"></div>
            </div>
            <div class="card">
                <h2>Nodes</h2>
                <div id="blockchainNodes"></div>
            </div>
            <div class="card">
                <h2>Recent Transactions</h2>
                <div id="blockchainTransactions"></div>
            </div>
        </div>
    </div>
    
    <script>
        let autoRefreshInterval = null;
        let lastUpdateTime = null;
        
        // Tab switching
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                tab.classList.add('active');
                document.getElementById(tab.dataset.tab).classList.add('active');
                
                // Load data for active tab
                if (tab.dataset.tab === 'metrics') {
                    loadMetrics();
                } else if (tab.dataset.tab === 'logs') {
                    loadLogFiles();
                    loadLogs();
                } else if (tab.dataset.tab === 'profiler') {
                    loadProfiler();
                } else if (tab.dataset.tab === 'blockchain') {
                    loadBlockchain();
                }
            });
        });
        
        // Auto-refresh toggle
        document.getElementById('autoRefresh').addEventListener('change', (e) => {
            if (e.target.checked) {
                startAutoRefresh();
            } else {
                stopAutoRefresh();
            }
        });
        
        function startAutoRefresh() {
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
            }
            autoRefreshInterval = setInterval(() => {
                const activeTab = document.querySelector('.tab.active').dataset.tab;
                if (activeTab === 'metrics') {
                    loadMetrics();
                } else if (activeTab === 'logs') {
                    loadLogs();
                } else if (activeTab === 'profiler') {
                    loadProfiler();
                } else if (activeTab === 'blockchain') {
                    loadBlockchain();
                }
            }, 2000);
        }
        
        function stopAutoRefresh() {
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
            }
        }
        
        function updateStatus(online) {
            const indicator = document.getElementById('statusIndicator');
            indicator.className = 'status-indicator ' + (online ? 'status-online' : 'status-offline');
        }
        
        function updateLastUpdate() {
            lastUpdateTime = new Date();
            document.getElementById('lastUpdate').textContent = 
                'Last update: ' + lastUpdateTime.toLocaleTimeString();
        }
        
        // Load metrics
        async function loadMetrics() {
            try {
                const response = await fetch('/dev-tools/api/metrics');
                if (!response.ok) throw new Error('Failed to load metrics');
                
                const data = await response.json();
                updateStatus(true);
                updateLastUpdate();
                
                // Swoole metrics
                const swooleHtml = `
                    <div class="metric">
                        <div class="metric-label">Active Connections</div>
                        <div class="metric-value">${data.swoole.connections.active}<span class="metric-unit"></span></div>
                    </div>
                    <div class="metric">
                        <div class="metric-label">Active Workers</div>
                        <div class="metric-value">${data.swoole.workers.active}<span class="metric-unit">/ ${data.swoole.workers.total}</span></div>
                    </div>
                    <div class="metric">
                        <div class="metric-label">Idle Workers</div>
                        <div class="metric-value">${data.swoole.workers.idle}<span class="metric-unit"></span></div>
                    </div>
                    <div class="metric">
                        <div class="metric-label">Total Requests</div>
                        <div class="metric-value">${data.swoole.requests.total.toLocaleString()}<span class="metric-unit"></span></div>
                    </div>
                    <div class="metric">
                        <div class="metric-label">Active Coroutines</div>
                        <div class="metric-value">${data.swoole.coroutines.active}<span class="metric-unit"></span></div>
                    </div>
                `;
                document.getElementById('swooleMetrics').innerHTML = swooleHtml;
                
                // Application metrics
                const appHtml = `
                    <div class="metric">
                        <div class="metric-label">Total Requests</div>
                        <div class="metric-value">${data.application.requests.total.toLocaleString()}<span class="metric-unit"></span></div>
                    </div>
                    <div class="metric">
                        <div class="metric-label">Errors</div>
                        <div class="metric-value">${data.application.requests.errors.toLocaleString()}<span class="metric-unit"></span></div>
                    </div>
                    <div class="metric">
                        <div class="metric-label">Uptime</div>
                        <div class="metric-value">${data.application.uptime.formatted}<span class="metric-unit"></span></div>
                    </div>
                `;
                document.getElementById('appMetrics').innerHTML = appHtml;
                
                // Memory metrics
                const memoryHtml = `
                    <div class="metric">
                        <div class="metric-label">Current Memory</div>
                        <div class="metric-value">${data.memory.current.formatted}<span class="metric-unit"></span></div>
                    </div>
                    <div class="metric">
                        <div class="metric-label">Peak Memory</div>
                        <div class="metric-value">${data.memory.peak.formatted}<span class="metric-unit"></span></div>
                    </div>
                `;
                document.getElementById('memoryMetrics').innerHTML = memoryHtml;
            } catch (error) {
                console.error('Error loading metrics:', error);
                updateStatus(false);
            }
        }
        
        // Load log files
        async function loadLogFiles() {
            try {
                const response = await fetch('/dev-tools/api/logs?lines=1');
                if (!response.ok) throw new Error('Failed to load log files');
                
                const data = await response.json();
                const select = document.getElementById('logFile');
                select.innerHTML = data.files.map(file => 
                    `<option value="${file}" ${file === data.currentFile ? 'selected' : ''}>${file}</option>`
                ).join('');
            } catch (error) {
                console.error('Error loading log files:', error);
            }
        }
        
        // Load logs
        async function loadLogs() {
            try {
                const file = document.getElementById('logFile').value;
                const filter = document.getElementById('logFilter').value;
                const lines = document.getElementById('logLines').value;
                
                const params = new URLSearchParams({ lines });
                if (file) params.append('file', file);
                if (filter) params.append('filter', filter);
                
                const response = await fetch('/dev-tools/api/logs?' + params);
                if (!response.ok) throw new Error('Failed to load logs');
                
                const data = await response.json();
                updateStatus(true);
                updateLastUpdate();
                
                const logHtml = data.logs.map(log => `
                    <div class="log-line">
                        ${log.timestamp ? `<span class="log-timestamp">[${log.timestamp}]</span>` : ''}
                        ${escapeHtml(log.line)}
                    </div>
                `).join('');
                
                document.getElementById('logViewer').innerHTML = logHtml || '<div class="log-line">No logs found</div>';
            } catch (error) {
                console.error('Error loading logs:', error);
                updateStatus(false);
            }
        }
        
        // Load profiler
        async function loadProfiler() {
            try {
                const limit = document.getElementById('profilerLimit').value;
                const response = await fetch('/dev-tools/api/profiler?limit=' + limit);
                if (!response.ok) throw new Error('Failed to load profiler');
                
                const data = await response.json();
                updateStatus(true);
                updateLastUpdate();
                
                const profilerHtml = data.events.map(event => `
                    <div class="profiler-event">
                        <div class="profiler-event-header">
                            <span class="profiler-event-type">${escapeHtml(event.type)}</span>
                            <span class="profiler-event-time">${event.time || 'N/A'}</span>
                        </div>
                        ${event.duration ? `<div>Total Duration: ${event.duration.toFixed(2)}ms</div>` : ''}
                        ${event.segments.length > 0 ? `
                            <div class="profiler-segments">
                                ${event.segments.map(seg => `
                                    <div class="profiler-segment">
                                        ${escapeHtml(seg.type)}: ${seg.payload.duration ? seg.payload.duration.toFixed(2) + 'ms' : 'N/A'}
                                    </div>
                                `).join('')}
                            </div>
                        ` : ''}
                    </div>
                `).join('');
                
                document.getElementById('profilerEvents').innerHTML = profilerHtml || '<div>No profiler events</div>';
            } catch (error) {
                console.error('Error loading profiler:', error);
                updateStatus(false);
            }
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        async function loadBlockchain() {
            try {
                // Load overview
                const overviewRes = await fetch('/dev-tools/api/blockchain/overview');
                const overview = await overviewRes.json();
                
                let overviewHtml = '';
                if (!overview.enabled) {
                    overviewHtml = '<p style="color: #8b949e;">Blockchain is not enabled</p>';
                } else {
                    overviewHtml = `
                        <div class="metrics-grid">
                            <div class="metric-card">
                                <div class="metric-label">Total Nodes</div>
                                <div class="metric-value">${overview.meta?.total_nodes || 0}</div>
                            </div>
                            <div class="metric-card">
                                <div class="metric-label">Total Transactions</div>
                                <div class="metric-value">${overview.statistics?.total_transactions || 0}</div>
                            </div>
                            <div class="metric-card">
                                <div class="metric-label">Synced Nodes</div>
                                <div class="metric-value">${overview.sync_status?.synced_nodes || 0}</div>
                            </div>
                            <div class="metric-card">
                                <div class="metric-label">Current Node</div>
                                <div class="metric-value">${overview.meta?.current_node || 'unknown'}</div>
                            </div>
                        </div>
                        <h3 style="margin-top: 20px;">Transactions by Operation</h3>
                        <div class="metrics-grid">
                            ${Object.entries(overview.statistics?.transactions_by_operation || {}).map(([op, count]) => `
                                <div class="metric-card">
                                    <div class="metric-label">${op}</div>
                                    <div class="metric-value">${count}</div>
                                </div>
                            `).join('')}
                        </div>
                    `;
                }
                document.getElementById('blockchainOverview').innerHTML = overviewHtml;
                
                // Load nodes
                const nodesRes = await fetch('/dev-tools/api/blockchain/nodes');
                const nodesData = await nodesRes.json();
                
                let nodesHtml = '';
                if (nodesData.nodes && nodesData.nodes.length > 0) {
                    nodesHtml = '<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px;">';
                    nodesData.nodes.forEach(node => {
                        nodesHtml += `
                            <div class="metric-card">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                    <h3 style="margin: 0;">${node.name}</h3>
                                    <span class="status-indicator ${node.status === 'online' ? 'status-online' : 'status-offline'}"></span>
                                </div>
                                <div style="color: #8b949e; font-size: 12px; margin-bottom: 10px;">ID: ${node.id}</div>
                                <div class="metrics-grid" style="grid-template-columns: 1fr 1fr;">
                                    <div class="metric-card" style="padding: 10px;">
                                        <div class="metric-label" style="font-size: 11px;">Transactions</div>
                                        <div class="metric-value" style="font-size: 18px;">${node.statistics?.total_transactions || 0}</div>
                                    </div>
                                    <div class="metric-card" style="padding: 10px;">
                                        <div class="metric-label" style="font-size: 11px;">Last Activity</div>
                                        <div class="metric-value" style="font-size: 11px; color: #8b949e;">
                                            ${node.statistics?.last_transaction ? new Date(node.statistics.last_transaction).toLocaleString() : 'Never'}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    nodesHtml += '</div>';
                } else {
                    nodesHtml = '<p style="color: #8b949e;">No nodes found</p>';
                }
                document.getElementById('blockchainNodes').innerHTML = nodesHtml;
                
                // Load transactions
                const txRes = await fetch('/dev-tools/api/blockchain/transactions?limit=20');
                const txData = await txRes.json();
                
                let txHtml = '';
                if (txData.transactions && txData.transactions.length > 0) {
                    txHtml = '<table style="width: 100%; border-collapse: collapse;"><thead><tr style="border-bottom: 1px solid #21262d;"><th style="text-align: left; padding: 10px;">Transaction ID</th><th style="text-align: left; padding: 10px;">Node</th><th style="text-align: left; padding: 10px;">Entity</th><th style="text-align: left; padding: 10px;">Operation</th><th style="text-align: left; padding: 10px;">Timestamp</th></tr></thead><tbody>';
                    txData.transactions.forEach(tx => {
                        const txIdShort = tx.transaction_id ? tx.transaction_id.substring(0, 16) + '...' : 'N/A';
                        const entityShort = tx.entity_class ? tx.entity_class.split('\\\\').pop() : 'N/A';
                        txHtml += `
                            <tr style="border-bottom: 1px solid #21262d;">
                                <td style="padding: 10px; font-family: monospace; font-size: 12px;">${txIdShort}</td>
                                <td style="padding: 10px;">${tx.node_id}</td>
                                <td style="padding: 10px;">${entityShort}</td>
                                <td style="padding: 10px;">${tx.operation}</td>
                                <td style="padding: 10px; color: #8b949e; font-size: 12px;">${tx.timestamp ? new Date(tx.timestamp).toLocaleString() : 'N/A'}</td>
                            </tr>
                        `;
                    });
                    txHtml += '</tbody></table>';
                } else {
                    txHtml = '<p style="color: #8b949e;">No transactions found</p>';
                }
                document.getElementById('blockchainTransactions').innerHTML = txHtml;
            } catch (error) {
                console.error('Error loading blockchain data:', error);
                document.getElementById('blockchainOverview').innerHTML = '<p style="color: #f85149;">Error loading blockchain data</p>';
            }
        }
        
        // Initialize
        loadMetrics();
        startAutoRefresh();
        
        // Log filter enter key
        document.getElementById('logFilter').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                loadLogs();
            }
        });
    </script>
</body>
</html>
HTML;
        
        return $html;
    }
}
