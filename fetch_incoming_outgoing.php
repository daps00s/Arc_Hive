<?php
session_start();
require 'db_connection.php';
require 'log_activity.php';
require 'vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Disable error display
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('error_log', __DIR__ . '/logs/error_log.log');
error_reporting(E_ALL);

/**
 * Sends JSON response and exits
 * @param array $data
 * @param int $statusCode
 */
function sendResponse(array $data, int $statusCode = 200): void
{
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

/**
 * Validate user session and CSRF token
 * @param PDO $pdo
 * @return array ['user_id'=>int,'user_department_ids'=>array]
 * @throws Exception
 */
function validateUserSession(PDO $pdo): array
{
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not logged in.', 401);
    }

    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    $expected = $_SESSION['csrf_token'] ?? null;
    if (!$expected || $csrfHeader !== $expected) {
        throw new Exception('Invalid CSRF token.', 403);
    }

    $userId = (int)$_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT user_department_id, department_id FROM user_departments WHERE user_id = ?");
    $stmt->execute([$userId]);
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$result) {
        throw new Exception('User is not associated with any department.', 403);
    }

    return [
        'user_id' => $userId,
        'user_department_ids' => array_column($result, 'user_department_id'),
        'department_ids' => array_column($result, 'department_id')
    ];
}

/**
 * Validates input parameters
 * @param string $interval
 * @param string|null $startDate
 * @param string|null $endDate
 * @param string|null $departmentId
 * @return array
 * @throws Exception
 */
function validateInput(string $interval, ?string $startDate, ?string $endDate, ?string $departmentId): array
{
    $validIntervals = ['day', 'week', 'month', 'range'];
    if (!in_array($interval, $validIntervals, true)) {
        throw new Exception('Invalid interval specified.', 400);
    }

    if ($interval === 'range') {
        if (empty($startDate) || empty($endDate)) {
            throw new Exception('Start and end dates are required for custom range.', 400);
        }
        $s = DateTime::createFromFormat('Y-m-d', $startDate);
        $e = DateTime::createFromFormat('Y-m-d', $endDate);
        if (!$s || !$e) {
            throw new Exception('Invalid date format. Use YYYY-MM-DD.', 400);
        }
        if ($e < $s) {
            throw new Exception('End date cannot be earlier than start date.', 400);
        }
    }

    $departmentId = $departmentId ? (int)$departmentId : null;

    return [
        'interval' => $interval,
        'startDate' => $startDate,
        'endDate' => $endDate,
        'departmentId' => $departmentId
    ];
}

/**
 * Builds named placeholders for SQL
 * @param array $items
 * @param string $prefix
 * @return array [placeholdersString, assocParamsArray]
 */
function buildNamedPlaceholders(array $items, string $prefix = 'p'): array
{
    if (empty($items)) {
        $items = [-1];
    }
    $placeholders = [];
    $params = [];
    foreach (array_values($items) as $i => $v) {
        $key = ':' . $prefix . $i;
        $placeholders[] = $key;
        $params[$key] = (int)$v;
    }
    return [implode(',', $placeholders), $params];
}

try {
    // Read JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON input.', 400);
    }

    $interval = $input['interval'] ?? '';
    $startDate = $input['startDate'] ?? null;
    $endDate = $input['endDate'] ?? null;
    $departmentId = $input['departmentId'] ?? null;

    // Validate user session
    $session = validateUserSession($pdo);
    $userId = $session['user_id'];
    $usersDepartmentIds = $session['user_department_ids'];
    $departmentIds = $session['department_ids'];

    // Validate input
    $validated = validateInput($interval, $startDate, $endDate, $departmentId);

    // Filter by department if specified
    if ($validated['departmentId'] && !in_array($validated['departmentId'], $departmentIds)) {
        throw new Exception('Invalid department ID.', 403);
    }
    $effectiveDepartmentIds = $validated['departmentId'] ? [$validated['departmentId']] : $departmentIds;
    $effectiveUserDepartmentIds = array_filter($usersDepartmentIds, function ($udId) use ($pdo, $validated) {
        $stmt = $pdo->prepare("SELECT department_id FROM user_departments WHERE user_department_id = ?");
        $stmt->execute([$udId]);
        $deptId = $stmt->fetchColumn();
        return !$validated['departmentId'] || $deptId == $validated['departmentId'];
    });

    // Chart Query
    [$udPlaceholders, $udParams] = buildNamedPlaceholders($effectiveUserDepartmentIds, 'ud');
    $chartQuery = "
        SELECT 
            DATE_FORMAT(t.transaction_time, 
                CASE :interval
                    WHEN 'day' THEN '%Y-%m-%d %H:00'
                    WHEN 'week' THEN '%Y-%u'
                    WHEN 'month' THEN '%Y-%m'
                    ELSE '%Y-%m-%d'
                END
            ) AS period,
            SUM(CASE WHEN t.transaction_type = 'upload' AND t.user_id = :userId THEN 1 ELSE 0 END) AS files_sent,
            SUM(CASE WHEN t.transaction_type = 'upload' AND t.transaction_status = 'completed' AND t.user_department_id IN ($udPlaceholders) THEN 1 ELSE 0 END) AS files_received,
            SUM(CASE WHEN t.transaction_type = 'request' THEN 1 ELSE 0 END) AS files_requested,
            SUM(CASE WHEN t.transaction_type = 'receive_request' THEN 1 ELSE 0 END) AS files_received_from_request
        FROM transactions t
        WHERE t.transaction_type IN ('upload', 'request', 'receive_request')
        AND (t.user_id = :userId OR t.user_department_id IN ($udPlaceholders))
    ";

    $chartParams = array_merge([':userId' => $userId, ':interval' => $validated['interval']], $udParams);
    if ($validated['interval'] === 'range') {
        $chartQuery .= " AND t.transaction_time BETWEEN :startDate AND :endDate";
        $chartParams[':startDate'] = $validated['startDate'] . ' 00:00:00';
        $chartParams[':endDate'] = $validated['endDate'] . ' 23:59:59';
    }

    $chartQuery .= " GROUP BY period ORDER BY period ASC";
    $stmt = $pdo->prepare($chartQuery);
    $stmt->execute($chartParams);
    $chartResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Table Query
    [$udPlaceholders2, $udParams2] = buildNamedPlaceholders($effectiveUserDepartmentIds, 'ud2');
    $tableQuery = "
        SELECT DISTINCT
            f.file_id,
            COALESCE(f.file_name, 'Unnamed File') AS file_name,
            COALESCE(dt.type_name, 'Unknown Type') AS document_type,
            t.transaction_time AS event_date,
            COALESCE(d.department_name, 'No Department') AS department_name,
            COALESCE(u.username, 'Unknown User') AS uploader,
            d.department_id,
            CASE 
                WHEN t.transaction_type = 'upload' AND t.user_id = :userId THEN 'Sent'
                WHEN t.transaction_type = 'upload' AND t.transaction_status = 'completed' AND t.user_department_id IN ($udPlaceholders2) THEN 'Received'
                ELSE 'Unknown'
            END AS direction
        FROM files f
        LEFT JOIN transactions t ON f.file_id = t.file_id
        LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
        LEFT JOIN user_departments ud ON t.user_department_id = ud.user_department_id
        LEFT JOIN departments d ON ud.department_id = d.department_id
        LEFT JOIN users u ON f.user_id = u.user_id
        WHERE t.transaction_type = 'upload'
        AND (f.file_status IS NULL OR f.file_status != 'disposed')
        AND (t.user_id = :userId OR t.user_department_id IN ($udPlaceholders2))
    ";

    $tableParams = array_merge([':userId' => $userId], $udParams2);
    if ($validated['interval'] === 'range') {
        $tableQuery .= " AND t.transaction_time BETWEEN :startDate AND :endDate";
        $tableParams[':startDate'] = $validated['startDate'] . ' 00:00:00';
        $tableParams[':endDate'] = $validated['endDate'] . ' 23:59:59';
    }

    $tableQuery .= " ORDER BY event_date ASC";
    $stmt = $pdo->prepare($tableQuery);
    $stmt->execute($tableParams);
    $filesResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Log access
    error_log(sprintf(
        "[%s] User %d fetched incoming/outgoing report (interval=%s%s)",
        date('Y-m-d H:i:s'),
        $userId,
        $validated['interval'],
        ($validated['interval'] === 'range' ? " {$validated['startDate']} to {$validated['endDate']}" : '')
    ));

    // Prepare response
    $labels = array_map(fn($r) => $r['period'] ?? '', $chartResults);
    $datasets = [
        'files_sent' => array_map(fn($r) => (int)($r['files_sent'] ?? 0), $chartResults),
        'files_received' => array_map(fn($r) => (int)($r['files_received'] ?? 0), $chartResults),
        'files_requested' => array_map(fn($r) => (int)($r['files_requested'] ?? 0), $chartResults),
        'files_received_from_request' => array_map(fn($r) => (int)($r['files_received_from_request'] ?? 0), $chartResults)
    ];

    $tableData = array_map(function ($row) {
        $row['upload_date'] = $row['event_date'] ?? null;
        unset($row['event_date']);
        return $row;
    }, $filesResults);

    sendResponse([
        'labels' => $labels,
        'datasets' => $datasets,
        'tableData' => $tableData
    ], 200);
} catch (Exception $e) {
    error_log("Error in fetch_incoming_outgoing.php: " . $e->getMessage());
    sendResponse(['error' => 'Server error: ' . $e->getMessage()], $e->getCode() && $e->getCode() >= 400 ? $e->getCode() : 500);
}
