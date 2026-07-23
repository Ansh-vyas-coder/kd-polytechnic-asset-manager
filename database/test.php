<?php
// Test code for Virtual Dashboard
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Virtual Dashboard Test</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #0f172a;
            --card-bg: #1e293b;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --accent-blue: #3b82f6;
            --accent-purple: #8b5cf6;
            --accent-green: #10b981;
            --accent-red: #ef4444;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            background-color: var(--bg-dark);
            color: var(--text-main);
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 250px;
            background-color: var(--card-bg);
            padding: 2rem;
            display: flex;
            flex-direction: column;
            gap: 2rem;
            border-right: 1px solid rgba(255, 255, 255, 0.05);
        }

        .sidebar h2 {
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--accent-blue), var(--accent-purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .nav-links {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .nav-link {
            color: var(--text-muted);
            text-decoration: none;
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .nav-link:hover, .nav-link.active {
            background-color: rgba(59, 130, 246, 0.1);
            color: var(--accent-blue);
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 2rem 3rem;
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            font-size: 2rem;
            font-weight: 600;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent-purple), var(--accent-blue));
        }

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
        }

        .stat-card {
            background-color: var(--card-bg);
            padding: 1.5rem;
            border-radius: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.2), 0 4px 6px -2px rgba(0, 0, 0, 0.1);
        }

        .stat-title {
            color: var(--text-muted);
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .stat-value {
            font-size: 1.875rem;
            font-weight: 700;
        }

        .stat-change {
            font-size: 0.875rem;
            font-weight: 500;
            margin-top: 0.5rem;
            display: inline-block;
        }

        .positive { color: var(--accent-green); }
        .negative { color: var(--accent-red); }

        /* Chart Area Placeholder */
        .chart-container {
            background-color: var(--card-bg);
            padding: 2rem;
            border-radius: 1rem;
            height: 300px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .chart-placeholder {
            color: var(--text-muted);
            font-size: 1.2rem;
            text-align: center;
            line-height: 1.5;
        }

    </style>
</head>
<body>
    <div class="sidebar">
        <h2>VirtualDash</h2>
        <nav class="nav-links">
            <a href="#" class="nav-link active">Overview</a>
            <a href="#" class="nav-link">Analytics</a>
            <a href="#" class="nav-link">Assets</a>
            <a href="#" class="nav-link">Settings</a>
        </nav>
    </div>

    <div class="main-content">
        <div class="header">
            <h1>Dashboard</h1>
            <div class="user-profile">
                <span>Welcome, Admin</span>
                <div class="avatar"></div>
            </div>
        </div>

        <div class="dashboard-grid">
            <div class="stat-card">
                <div class="stat-title">Total Users</div>
                <div class="stat-value">12,450</div>
                <div class="stat-change positive">↑ 12.5% this month</div>
            </div>
            <div class="stat-card">
                <div class="stat-title">Active Assets</div>
                <div class="stat-value">8,192</div>
                <div class="stat-change positive">↑ 4.2% this month</div>
            </div>
            <div class="stat-card">
                <div class="stat-title">System Load</div>
                <div class="stat-value">34%</div>
                <div class="stat-change negative">↓ 2.1% from yesterday</div>
            </div>
            <div class="stat-card">
                <div class="stat-title">Revenue</div>
                <div class="stat-value">$45,231</div>
                <div class="stat-change positive">↑ 8.4% this month</div>
            </div>
        </div>

        <div class="chart-container">
            <div class="chart-placeholder">
                Interactive Chart Area<br>
                <span style="font-size: 0.9rem;">(Ready for Chart.js or D3.js integration)</span>
            </div>
        </div>
    </div>
</body>
</html>
