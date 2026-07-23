<?php
// Test code for Virtual Dashboard with extended features and PHP logic

// Simulated dynamic data (Normally this would come from a database)
$totalUsers = 12450;
$activeAssets = 8192;
$revenue = 45231.50;
$systemLoad = 78;

$recentActivities = [
    ['id' => 'TX-10293', 'client' => 'Acme Corp', 'date' => 'Jul 23, 2026', 'amount' => 1240.00, 'status' => 'Completed'],
    ['id' => 'TX-10294', 'client' => 'Global Industries', 'date' => 'Jul 23, 2026', 'amount' => 850.50, 'status' => 'Pending'],
    ['id' => 'TX-10295', 'client' => 'TechNova Inc.', 'date' => 'Jul 22, 2026', 'amount' => 4320.00, 'status' => 'Completed'],
    ['id' => 'TX-10296', 'client' => 'Oceanic Logistics', 'date' => 'Jul 22, 2026', 'amount' => 120.00, 'status' => 'Failed'],
    ['id' => 'TX-10297', 'client' => 'Stark Enterprises', 'date' => 'Jul 21, 2026', 'amount' => 9500.00, 'status' => 'Completed'],
    ['id' => 'TX-10298', 'client' => 'Wayne Tech', 'date' => 'Jul 21, 2026', 'amount' => 3100.25, 'status' => 'Pending'],
];

// Helper function for badges
function getStatusBadge($status) {
    switch (strtolower($status)) {
        case 'completed': return '<span class="status-badge status-completed">Completed</span>';
        case 'pending': return '<span class="status-badge status-pending">Pending</span>';
        case 'failed': return '<span class="status-badge status-failed">Failed</span>';
        default: return '<span class="status-badge">Unknown</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Virtual Dashboard Test Environment</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #0f172a;
            --card-bg: #1e293b;
            --card-hover: #334155;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --accent-blue: #3b82f6;
            --accent-purple: #8b5cf6;
            --accent-green: #10b981;
            --accent-red: #ef4444;
            --accent-orange: #f59e0b;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background-color: var(--bg-dark); color: var(--text-main); display: flex; min-height: 100vh; }

        /* Sidebar */
        .sidebar { width: 260px; background-color: var(--card-bg); padding: 2rem; display: flex; flex-direction: column; gap: 2rem; border-right: 1px solid rgba(255, 255, 255, 0.05); }
        .sidebar h2 { font-size: 1.5rem; font-weight: 700; background: linear-gradient(135deg, var(--accent-blue), var(--accent-purple)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .nav-links { display: flex; flex-direction: column; gap: 0.5rem; }
        .nav-link { color: var(--text-muted); text-decoration: none; padding: 0.75rem 1rem; border-radius: 0.5rem; transition: all 0.3s ease; font-weight: 500; display: flex; align-items: center; justify-content: space-between; }
        .nav-link:hover, .nav-link.active { background-color: rgba(59, 130, 246, 0.1); color: var(--accent-blue); }
        .badge { background: var(--accent-blue); color: #fff; font-size: 0.7rem; padding: 0.2rem 0.5rem; border-radius: 1rem; font-weight: 600; }

        /* Main Content */
        .main-content { flex: 1; padding: 2rem 3rem; display: flex; flex-direction: column; gap: 2rem; overflow-y: auto; }
        
        /* Top Navigation Bar */
        .top-nav { display: flex; justify-content: space-between; align-items: center; padding-bottom: 1rem; border-bottom: 1px solid rgba(255, 255, 255, 0.05); }
        .search-bar { display: flex; align-items: center; background: rgba(255, 255, 255, 0.05); padding: 0.5rem 1rem; border-radius: 2rem; width: 300px; }
        .search-bar input { background: transparent; border: none; color: var(--text-main); outline: none; margin-left: 0.5rem; width: 100%; }
        .nav-actions { display: flex; align-items: center; gap: 1.5rem; }
        .icon-btn { background: none; border: none; color: var(--text-muted); cursor: pointer; position: relative; transition: color 0.3s ease; font-size: 1.2rem; }
        .icon-btn:hover { color: var(--text-main); }
        .notification-dot { position: absolute; top: 0; right: 0; width: 8px; height: 8px; background-color: var(--accent-red); border-radius: 50%; }
        
        .header h1 { font-size: 2rem; font-weight: 600; margin-top: 1rem; }
        .user-profile { display: flex; align-items: center; gap: 1rem; cursor: pointer; padding: 0.5rem; border-radius: 2rem; transition: background 0.3s; }
        .user-profile:hover { background: rgba(255,255,255,0.05); }
        .avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--accent-purple), var(--accent-blue)); display:flex; align-items:center; justify-content:center; font-weight: bold;}

        /* Quick Actions */
        .quick-actions { display: flex; gap: 1rem; margin-bottom: 1rem; }
        .action-btn { background: var(--card-bg); color: var(--text-main); border: 1px solid rgba(255,255,255,0.1); padding: 0.75rem 1.5rem; border-radius: 0.5rem; cursor: pointer; transition: all 0.3s ease; font-weight: 500; display:flex; align-items:center; gap: 0.5rem;}
        .action-btn:hover { background: var(--card-hover); border-color: var(--accent-blue); }
        .action-btn.primary { background: var(--accent-blue); border-color: var(--accent-blue); }
        .action-btn.primary:hover { background: #2563eb; }

        /* Dashboard Grid */
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; }
        .stat-card { background-color: var(--card-bg); padding: 1.5rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); border: 1px solid rgba(255, 255, 255, 0.05); transition: transform 0.3s ease; position: relative; overflow: hidden; }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: var(--accent-blue); opacity: 0; transition: opacity 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-card:hover::before { opacity: 1; }
        .stat-title { color: var(--text-muted); font-size: 0.875rem; font-weight: 500; margin-bottom: 0.5rem; display:flex; justify-content: space-between; align-items:center;}
        .stat-value { font-size: 1.875rem; font-weight: 700; }
        .stat-change { font-size: 0.875rem; font-weight: 500; margin-top: 0.5rem; display: inline-block; }
        .positive { color: var(--accent-green); }
        .negative { color: var(--accent-red); }

        /* Complex Grid Layout */
        .complex-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; }
        
        .panel { background-color: var(--card-bg); padding: 2rem; border-radius: 1rem; border: 1px solid rgba(255, 255, 255, 0.05); }
        .panel h3 { margin-bottom: 1.5rem; font-size: 1.1rem; font-weight: 600; display:flex; justify-content: space-between; align-items:center;}

        /* Chart Area Placeholder */
        .chart-placeholder { height: 250px; display: flex; align-items: center; justify-content: center; color: var(--text-muted); border: 1px dashed rgba(148, 163, 184, 0.2); border-radius: 0.5rem; background: linear-gradient(180deg, rgba(255,255,255,0.02) 0%, transparent 100%); }

        /* Status Widget */
        .progress-group { margin-bottom: 1.5rem; }
        .progress-header { display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.875rem; color: var(--text-muted); }
        .progress-bar-bg { height: 8px; background: rgba(255, 255, 255, 0.1); border-radius: 4px; overflow: hidden; }
        .progress-bar-fill { height: 100%; border-radius: 4px; transition: width 1s ease-in-out;}
        .fill-blue { background: var(--accent-blue); width: <?= $systemLoad ?>%; }
        .fill-purple { background: var(--accent-purple); width: 45%; }
        .fill-orange { background: var(--accent-orange); width: 92%; }

        /* Recent Activity Table */
        .table-controls { display:flex; gap: 1rem; margin-bottom: 1rem; }
        .filter-btn { background: transparent; border: 1px solid rgba(255,255,255,0.1); color: var(--text-muted); padding: 0.4rem 1rem; border-radius: 2rem; cursor: pointer; font-size: 0.85rem; }
        .filter-btn.active { background: rgba(59, 130, 246, 0.2); color: var(--accent-blue); border-color: var(--accent-blue); }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 1rem; text-align: left; border-bottom: 1px solid rgba(255, 255, 255, 0.05); }
        th { color: var(--text-muted); font-weight: 500; font-size: 0.875rem; }
        td { font-size: 0.95rem; }
        tr:hover td { background-color: rgba(255, 255, 255, 0.02); }
        .status-badge { padding: 0.25rem 0.75rem; border-radius: 1rem; font-size: 0.8rem; font-weight: 500; }
        .status-completed { background: rgba(16, 185, 129, 0.1); color: var(--accent-green); }
        .status-pending { background: rgba(245, 158, 11, 0.1); color: var(--accent-orange); }
        .status-failed { background: rgba(239, 68, 68, 0.1); color: var(--accent-red); }
        
        /* Modal (Hidden by default) */
        .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); display: none; align-items: center; justify-content: center; z-index: 1000; backdrop-filter: blur(4px); }
        .modal-content { background: var(--card-bg); padding: 2rem; border-radius: 1rem; width: 400px; border: 1px solid rgba(255,255,255,0.1); }
        .modal-content h3 { margin-bottom: 1rem; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem; }
        .form-group input { width: 100%; padding: 0.75rem; border-radius: 0.5rem; background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.1); color: #fff; outline: none; }
        .form-group input:focus { border-color: var(--accent-blue); }
        .modal-actions { display: flex; justify-content: flex-end; gap: 1rem; margin-top: 2rem; }
        
    </style>
</head>
<body>
    <div class="sidebar">
        <h2>VirtualDash <span style="font-size:0.8rem; color:var(--text-muted); font-weight:normal;">TEST</span></h2>
        <nav class="nav-links">
            <a href="#" class="nav-link active">Dashboard</a>
            <a href="#" class="nav-link">Analytics</a>
            <a href="#" class="nav-link">Assets <span class="badge">12</span></a>
            <a href="#" class="nav-link">Transactions</a>
            <a href="#" class="nav-link">System Logs</a>
            <a href="#" class="nav-link">Settings</a>
        </nav>
        
        <div style="margin-top: auto; padding: 1rem; background: rgba(255,255,255,0.02); border-radius: 0.5rem;">
            <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.5rem;">Storage Quota</p>
            <div class="progress-bar-bg" style="height: 6px; margin-bottom: 0.5rem;">
                <div class="progress-bar-fill fill-blue" style="width: 82%;"></div>
            </div>
            <p style="font-size: 0.75rem; color: var(--text-muted);">8.2 GB of 10 GB used</p>
        </div>
    </div>

    <div class="main-content">
        <div class="top-nav">
            <div class="search-bar">
                <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16" style="color:var(--text-muted)"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z"/></svg>
                <input type="text" placeholder="Search assets, logs, users...">
            </div>
            <div class="nav-actions">
                <button class="icon-btn">
                    <svg width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path d="M8 16a2 2 0 0 0 2-2H6a2 2 0 0 0 2 2zM8 1.918l-.797.161A4.002 4.002 0 0 0 4 6c0 .628-.134 2.197-.459 3.742-.16.767-.376 1.566-.663 2.258h10.244c-.287-.692-.502-1.49-.663-2.258C12.134 8.197 12 6.628 12 6a4.002 4.002 0 0 0-3.203-3.92L8 1.917zM14.22 12c.223.447.481.801.78 1H1c.299-.199.557-.553.78-1C2.68 10.2 3 6.88 3 6c0-2.42 1.72-4.44 4.005-4.901a1 1 0 1 1 1.99 0A5.002 5.002 0 0 1 13 6c0 .88.32 4.2 1.22 6z"/></svg>
                    <span class="notification-dot"></span>
                </button>
                <div class="user-profile">
                    <div style="text-align: right;">
                        <span style="display:block; font-size: 0.9rem;">Admin User</span>
                        <span style="display:block; font-size: 0.75rem; color: var(--text-muted);">IT Department</span>
                    </div>
                    <div class="avatar">AD</div>
                </div>
            </div>
        </div>

        <div class="header">
            <div>
                <h1>Dashboard Overview</h1>
                <p style="color: var(--text-muted); margin-top: 0.5rem; font-size: 0.9rem;">Monitor asset tracking, performance metrics, and logs in real-time.</p>
            </div>
        </div>
        
        <div class="quick-actions">
            <button class="action-btn primary" onclick="document.getElementById('addModal').style.display='flex'">+ Add New Asset</button>
            <button class="action-btn">Generate Report</button>
            <button class="action-btn">Export CSV</button>
        </div>

        <div class="dashboard-grid">
            <div class="stat-card">
                <div class="stat-title">Total Users <span style="font-size: 1.2rem;">👥</span></div>
                <div class="stat-value"><?= number_format($totalUsers) ?></div>
                <div class="stat-change positive">↑ 12.5% this month</div>
            </div>
            <div class="stat-card">
                <div class="stat-title">Active Assets <span style="font-size: 1.2rem;">📦</span></div>
                <div class="stat-value"><?= number_format($activeAssets) ?></div>
                <div class="stat-change positive">↑ 4.2% this month</div>
            </div>
            <div class="stat-card">
                <div class="stat-title">System Load <span style="font-size: 1.2rem;">⚡</span></div>
                <div class="stat-value"><?= $systemLoad ?>%</div>
                <div class="stat-change negative">↓ 2.1% from yesterday</div>
            </div>
            <div class="stat-card">
                <div class="stat-title">Monthly Revenue <span style="font-size: 1.2rem;">💰</span></div>
                <div class="stat-value">$<?= number_format($revenue, 2) ?></div>
                <div class="stat-change positive">↑ 8.4% this month</div>
            </div>
        </div>

        <div class="complex-grid">
            <div class="panel">
                <h3>Revenue Overview 
                    <select style="background:transparent; border:1px solid rgba(255,255,255,0.1); color:var(--text-muted); padding:0.2rem; border-radius:0.25rem;">
                        <option>This Year</option>
                        <option>Last Year</option>
                    </select>
                </h3>
                <div class="chart-placeholder">
                    <div style="text-align: center;">
                        <svg width="40" height="40" fill="currentColor" viewBox="0 0 16 16" style="margin-bottom: 1rem; opacity: 0.5;"><path fill-rule="evenodd" d="M0 0h1v15h15v1H0V0Zm14.817 3.113a.5.5 0 0 1 .07.704l-4.5 5.5a.5.5 0 0 1-.74.037L7.06 6.767l-3.656 5.027a.5.5 0 0 1-.808-.588l4-5.5a.5.5 0 0 1 .758-.06l2.609 2.61 4.15-5.073a.5.5 0 0 1 .704-.07Z"/></svg><br>
                        Interactive Chart Area<br>
                        <span style="font-size: 0.8rem;">(Data visualization ready)</span>
                    </div>
                </div>
            </div>

            <div class="panel">
                <h3>Server Health</h3>
                <div class="progress-group">
                    <div class="progress-header">
                        <span>CPU Usage</span>
                        <span><?= $systemLoad ?>%</span>
                    </div>
                    <div class="progress-bar-bg">
                        <div class="progress-bar-fill fill-blue"></div>
                    </div>
                </div>
                
                <div class="progress-group">
                    <div class="progress-header">
                        <span>Memory Allocation</span>
                        <span>45%</span>
                    </div>
                    <div class="progress-bar-bg">
                        <div class="progress-bar-fill fill-purple"></div>
                    </div>
                </div>

                <div class="progress-group">
                    <div class="progress-header">
                        <span>Storage Capacity</span>
                        <span>92%</span>
                    </div>
                    <div class="progress-bar-bg">
                        <div class="progress-bar-fill fill-orange"></div>
                    </div>
                </div>
                
                <div style="margin-top: 2rem; padding: 1rem; background: rgba(16,185,129,0.1); border-radius: 0.5rem; display:flex; align-items:center; gap: 0.5rem;">
                    <div style="width:10px; height:10px; border-radius:50%; background:var(--accent-green);"></div>
                    <span style="font-size: 0.9rem; color: var(--accent-green);">All systems operational</span>
                </div>
            </div>
        </div>

        <div class="panel">
            <h3>Recent Transactions</h3>
            <div class="table-controls">
                <button class="filter-btn active">All</button>
                <button class="filter-btn">Completed</button>
                <button class="filter-btn">Pending</button>
                <button class="filter-btn">Failed</button>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Transaction ID</th>
                        <th>Client</th>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentActivities as $activity): ?>
                    <tr>
                        <td style="font-family: monospace; color: var(--text-muted);"><?= $activity['id'] ?></td>
                        <td><?= htmlspecialchars($activity['client']) ?></td>
                        <td style="color: var(--text-muted);"><?= $activity['date'] ?></td>
                        <td style="font-weight: 500;">$<?= number_format($activity['amount'], 2) ?></td>
                        <td><?= getStatusBadge($activity['status']) ?></td>
                        <td><button style="background:transparent; border:none; color:var(--accent-blue); cursor:pointer; font-size:0.9rem;">View</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Hidden Add Asset Modal -->
    <div class="modal-overlay" id="addModal">
        <div class="modal-content">
            <h3>Add New Asset</h3>
            <form onsubmit="event.preventDefault(); alert('Asset added successfully (Test)'); document.getElementById('addModal').style.display='none';">
                <div class="form-group">
                    <label>Asset Name</label>
                    <input type="text" placeholder="e.g. Dell XPS 15" required>
                </div>
                <div class="form-group">
                    <label>Serial Number</label>
                    <input type="text" placeholder="SN-XXXX-XXXX" required>
                </div>
                <div class="form-group">
                    <label>Department</label>
                    <input type="text" placeholder="e.g. IT, HR, Finance">
                </div>
                <div class="modal-actions">
                    <button type="button" class="action-btn" onclick="document.getElementById('addModal').style.display='none'">Cancel</button>
                    <button type="submit" class="action-btn primary">Save Asset</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
