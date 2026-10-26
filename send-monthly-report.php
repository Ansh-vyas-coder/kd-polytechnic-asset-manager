<?php
/**
 * send-monthly-report.php
 * Generates and emails the Monthly Asset Status Report (Missing, Under Maintenance,
 * Not Working, Retired, and Overall Statistics) to all administrators.
 *
 * Can be run via:
 * 1. CLI / Cron Job: php send-monthly-report.php
 * 2. Web browser by an Admin: send-monthly-report.php?action=send_now
 * 3. Hosting cPanel Cron URL: send-monthly-report.php?cron_token=kdp_monthly_asset_report_cron
 */

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

// Define a default cron token for automated scheduling
define('CRON_SECRET_TOKEN', 'kdp_monthly_asset_report_cron');

// Determine execution context
$is_cli = (php_sapi_name() === 'cli');
$is_admin = (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
$has_valid_cron_token = (isset($_GET['cron_token']) && hash_equals(CRON_SECRET_TOKEN, (string)$_GET['cron_token']));
$is_forced = (isset($_GET['force']) && $_GET['force'] === '1') || in_array('--force', $argv ?? []);

// Security check
if (!$is_cli && !$is_admin && !$has_valid_cron_token) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

/**
 * Executes the monthly report dispatch.
 * @param mysqli $conn
 * @param bool $force Force sending even if already sent this month
 * @return array Results summary
 */
function run_monthly_asset_report(mysqli $conn, bool $force = false): array {
    $current_month_key = date('Y-m'); // e.g. "2026-10"
    $month_display_name = date('F Y');  // e.g. "October 2026"
    $log_dir = __DIR__ . '/logs';
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0777, true);
    }
    $last_sent_file = $log_dir . '/last_monthly_report_month.txt';

    // Check if already sent for this month (unless forced)
    if (!$force && file_exists($last_sent_file)) {
        $last_sent_month = trim(file_get_contents($last_sent_file));
        if ($last_sent_month === $current_month_key) {
            return [
                'success' => true,
                'already_sent' => true,
                'message' => "Monthly report for {$month_display_name} has already been sent to administrators."
            ];
        }
    }

    // 1. Gather Asset Status Statistics
    $total_assets       = 0;
    $active_assets      = 0;
    $missing_assets     = 0;
    $maintenance_assets = 0;
    $not_working_assets = 0;
    $retired_assets     = 0;

    $stat_query = $conn->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count,
            SUM(CASE WHEN LOWER(status) = 'missing' THEN 1 ELSE 0 END) as missing_count,
            SUM(CASE WHEN LOWER(status) LIKE '%maintenance%' THEN 1 ELSE 0 END) as maintenance_count,
            SUM(CASE WHEN LOWER(status) LIKE '%not working%' THEN 1 ELSE 0 END) as not_working_count,
            SUM(CASE WHEN LOWER(status) IN ('retired', 'scrap') THEN 1 ELSE 0 END) as retired_count
        FROM assets
    ");

    if ($stat_query && $row = $stat_query->fetch_assoc()) {
        $total_assets       = (int)($row['total'] ?? 0);
        $active_assets      = (int)($row['active_count'] ?? 0);
        $missing_assets     = (int)($row['missing_count'] ?? 0);
        $maintenance_assets = (int)($row['maintenance_count'] ?? 0);
        $not_working_assets = (int)($row['not_working_count'] ?? 0);
        $retired_assets     = (int)($row['retired_count'] ?? 0);
        $stat_query->free();
    }

    // 2. Fetch Detailed List of Flagged Assets (Missing, Under Maintenance, Not Working, Retired)
    $flagged_items = [];
    $flagged_query = $conn->query("
        SELECT id, item_no, asset_name, category_id, location, status, cost, remarks
        FROM assets
        WHERE status != 'active'
        ORDER BY 
            CASE 
                WHEN LOWER(status) = 'missing' THEN 1
                WHEN LOWER(status) LIKE '%not working%' THEN 2
                WHEN LOWER(status) LIKE '%maintenance%' THEN 3
                ELSE 4
            END,
            asset_name ASC
        LIMIT 60
    ");

    if ($flagged_query) {
        $flagged_items = $flagged_query->fetch_all(MYSQLI_ASSOC);
        $flagged_query->free();
    }

    // 3. Compile Report Data Payload
    $report_data = [
        'month_name'         => $month_display_name,
        'total_assets'       => $total_assets,
        'active_assets'      => $active_assets,
        'missing_assets'     => $missing_assets,
        'maintenance_assets' => $maintenance_assets,
        'not_working_assets' => $not_working_assets,
        'retired_assets'     => $retired_assets,
        'flagged_items'      => $flagged_items,
    ];

    // 4. Fetch All Active Administrator Accounts
    $admin_list = [];
    $admin_query = $conn->query("SELECT id, full_name, email FROM users WHERE role = 'admin' AND email_verified = 1");
    if ($admin_query) {
        while ($admin_row = $admin_query->fetch_assoc()) {
            if (!empty($admin_row['email'])) {
                $admin_list[] = $admin_row;
            }
        }
        $admin_query->free();
    }

    if (empty($admin_list)) {
        return [
            'success' => false,
            'message' => 'No verified administrator email addresses found.'
        ];
    }

    // 5. Send Report Email to Each Admin
    $dashboard_url = get_app_base_url() . "/dashboard.php";
    $sent_count = 0;
    $failed_count = 0;

    foreach ($admin_list as $admin) {
        $res = send_monthly_asset_report_email(
            $admin['full_name'],
            $admin['email'],
            $report_data,
            $dashboard_url
        );

        if (!empty($res['success'])) {
            $sent_count++;
        } else {
            $failed_count++;
        }
    }

    // 6. Record timestamp & month tracker
    file_put_contents($last_sent_file, $current_month_key);
    $history_log = $log_dir . '/monthly_report_history.log';
    $log_line = sprintf(
        "[%s] Sent to %d admins (Failed: %d) | Total: %d | Missing: %d | Maintenance: %d | Not Working: %d\n",
        date('Y-m-d H:i:s'),
        $sent_count,
        $failed_count,
        $total_assets,
        $missing_assets,
        $maintenance_assets,
        $not_working_assets
    );
    @file_put_contents($history_log, $log_line, FILE_APPEND);

    return [
        'success'      => true,
        'already_sent' => false,
        'sent_to'      => $sent_count,
        'failed'       => $failed_count,
        'stats'        => $report_data,
        'message'      => "Monthly asset report for {$month_display_name} sent successfully to {$sent_count} administrator(s)."
    ];
}

// Execute
$result = run_monthly_asset_report($conn, $is_forced);

// Output based on caller only if not included internally by dashboard.php
if (!defined('MONTHLY_REPORT_INCLUDED')) {
    if ($is_cli) {
        echo "========================================\n";
        echo "K.D. Polytechnic Asset Manager - Monthly Report\n";
        echo "========================================\n";
        echo "Status: " . ($result['success'] ? 'SUCCESS' : 'FAILED') . "\n";
        echo "Message: " . $result['message'] . "\n";
        if (!empty($result['sent_to'])) {
            echo "Sent to: {$result['sent_to']} Admin(s)\n";
        }
        echo "========================================\n";
    } else {
        // If request asks for HTML/redirect vs JSON
        if (isset($_GET['redirect']) && $_GET['redirect'] === 'dashboard') {
            $status_type = $result['success'] ? 'success' : 'error';
            header("Location: dashboard.php?status={$status_type}&message=" . urlencode($result['message']));
            exit();
        }

        header('Content-Type: application/json');
        echo json_encode($result);
    }
}
