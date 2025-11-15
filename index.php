<?php

// ==================== HELPER FUNCTIONS ====================

/**
 * Sends a JSON response with proper CORS headers
 */
function send_json_response($data, $code, $is_success)
{
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    header("Content-Type: application/json; charset=UTF-8");

    http_response_code($code);
    $response = ['success' => $is_success];

    if ($is_success) {
        $response['data'] = $data;
    } else {
        $response['error'] = $data;
    }

    echo json_encode($response);
    exit;
}

/**
 * Gets JSON input data from request body
 */
function get_input_data()
{
    $input = file_get_contents('php://input');
    return json_decode($input, true);
}

/**
 * Sends a successful JSON response
 */
function send_response($data, $code = 200)
{
    send_json_response($data, $code, true);
}

/**
 * Sends an error JSON response
 */
function send_error($message, $code = 400)
{
    send_json_response($message, $code, false);
}

/**
 * Converts ISO 8601 timestamp to MySQL datetime format
 * Handles both UTC (Z) and timezone offset formats
 */
function convert_to_mysql_datetime($timestamp)
{
    try {
        // If already in MySQL format, return as-is
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $timestamp)) {
            return $timestamp;
        }
        // Parse ISO 8601 format
        $dt = new DateTime($timestamp);
        // Convert to MySQL datetime format (Y-m-d H:i:s)
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        // If parsing fails, return current time
        return date('Y-m-d H:i:s');
    }
}

// ==================== CORS PREFLIGHT ====================

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    header("Access-Control-Max-Age: 86400");
    http_response_code(200);
    exit;
}

// ==================== DATABASE CONFIGURATION ====================

$servername = 'localhost';
$dbname = 'clockmate';
$username = 'root';
$password = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$servername;dbname=$dbname;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (\PDOException $e) {
    send_error('Database connection failed: ' . $e->getMessage(), 500);
}

// ==================== ROUTER SETUP ====================

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = trim($uri, '/');
$uri_parts = explode('/', $uri);
$method = $_SERVER['REQUEST_METHOD'];

// Remove base path segments
if (isset($uri_parts[0]) && $uri_parts[0] === 'clockmate') {
    array_shift($uri_parts);
}
if (isset($uri_parts[0]) && $uri_parts[0] === 'api') {
    array_shift($uri_parts);
}
if (isset($uri_parts[0]) && $uri_parts[0] === 'index.php') {
    array_shift($uri_parts);
}

$route = implode('/', $uri_parts);

// ==================== TEST ENDPOINT ====================

if ($route === '' || $route === 'test' || $route === 'index.php') {
    send_response([
        'message' => 'ClockMate API is running!',
        'version' => '1.0.0',
        'timestamp' => date('Y-m-d H:i:s'),
        'timezone' => date_default_timezone_get()
    ]);
}

// ==================== CLOCK ENDPOINTS ====================

// Record Clock Event
if ($route === 'clock/event') {
    if ($method !== 'POST') {
        send_error('Method not allowed', 405);
    }
    $data = get_input_data();

    // Validate input
    if (empty($data['employeeId']) || empty($data['type']) || !in_array($data['type'], ['in', 'out'])) {
        send_error('Employee ID and valid type (in/out) are required.');
    }

    try {
        // Use the timestamp as-is if provided (already in MySQL format from frontend)
        // Otherwise use server's current time
        $timestamp = isset($data['timestamp']) && !empty($data['timestamp'])
            ? $data['timestamp']
            : date('Y-m-d H:i:s');

        // Validate timestamp format (basic check)
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $timestamp)) {
            // If not in MySQL format, try to convert
            $timestamp = convert_to_mysql_datetime($timestamp);
        }

        // Insert clock event
        $stmt = $pdo->prepare("
            INSERT INTO clock_events (employee_id, event_type, timestamp) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$data['employeeId'], $data['type'], $timestamp]);
        $inserted_id = $pdo->lastInsertId();

        // Fetch the inserted record to return exact DB values
        $stmt = $pdo->prepare("
            SELECT id, employee_id, event_type as type, timestamp
            FROM clock_events 
            WHERE id = ?
        ");
        $stmt->execute([$inserted_id]);
        $inserted_record = $stmt->fetch();

        // Return the inserted record for verification
        send_response([
            'message' => 'Clock event recorded successfully.',
            'record' => $inserted_record
        ], 201);
    } catch (\PDOException $e) {
        send_error('Failed to record clock event: ' . $e->getMessage(), 500);
    }
}

// Get Clock Logs for Employee
if (preg_match('/^clock\/logs\/(\d+)$/', $route, $matches))
{
    if ($method !== 'GET') {
        send_error('Method not allowed', 405);
    }

    $employee_id = $matches[1];

    // Retrieve 'start' and 'end' dates from URL query parameters
    $start_date = $_GET['start'] ?? null;
    $end_date = $_GET['end'] ?? null;
    try {
        // Start building the base SQL query and parameter array
        $sql = "
      SELECT id, event_type as type, timestamp
      FROM clock_events 
      WHERE employee_id = ?
        ";
        $params = [$employee_id];

        // Conditionally add the date range filter
        if ($start_date && $end_date) {
            // Use DATE() function to compare the date part of the timestamp
            // Note: This assumes start/end dates are in 'YYYY-MM-DD' format.
            $sql .= " AND DATE(timestamp) >= ? AND DATE(timestamp) <= ?";
            $params[] = $start_date;
            $params[] = $end_date;
        }

        // Ensure logs are sorted chronologically for accurate front-end calculation
        $sql .= " ORDER BY timestamp ASC";

        // Removed LIMIT 100 to ensure all logs in the range are returned,
        // but you can add it back if necessary.

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll();

        send_response($logs);
    } catch (\PDOException $e) {
        send_error('Failed to fetch logs: ' . $e->getMessage(), 500);
    }
}

// ==================== ADMIN ENDPOINTS (Keep your existing code) ====================

// Get Pending Approvals
if ($route === 'admin/pending') {
    if ($method !== 'GET') {
        send_error('Method not allowed', 405);
    }

    try {
        $stmt = $pdo->query("
            SELECT id, name, email, created_at
            FROM employees 
            WHERE is_approved = 0 AND role = 'employee' 
            ORDER BY created_at ASC
        ");
        $pending = $stmt->fetchAll();
        send_response($pending);
    } catch (\PDOException $e) {
        send_error('Failed to fetch pending approvals: ' . $e->getMessage(), 500);
    }
}

// Get All Employees
if ($route === 'admin/employees') {
    if ($method !== 'GET') {
        send_error('Method not allowed', 405);
    }

    try {
        $stmt = $pdo->query("
            SELECT id, name, email, role, is_approved as status, created_at
            FROM employees 
            WHERE role = 'employee'
            ORDER BY created_at DESC
        ");
        $employees = $stmt->fetchAll();
        send_response($employees);
    } catch (\PDOException $e) {
        send_error('Failed to fetch employees: ' . $e->getMessage(), 500);
    }
}

// Get Dashboard Stats
if ($route === 'admin/stats') {
    if ($method !== 'GET') {
        send_error('Method not allowed', 405);
    }

    try {
        $stmt = $pdo->query("
            SELECT COUNT(*) as total 
            FROM employees 
            WHERE role = 'employee' AND is_approved = 1
        ");
        $totalEmployees = $stmt->fetch()['total'];

        $stmt = $pdo->query("
            SELECT COUNT(*) as pending 
            FROM employees 
            WHERE role = 'employee' AND is_approved = 0
        ");
        $pendingApprovals = $stmt->fetch()['pending'];

        $stmt = $pdo->query("
            SELECT COUNT(DISTINCT employee_id) as active 
            FROM clock_events 
            WHERE DATE(timestamp) = CURDATE()
        ");
        $activeEmployees = $stmt->fetch()['active'];

        $stmt = $pdo->query("
            SELECT COUNT(*) as clock_ins 
            FROM clock_events 
            WHERE event_type = 'in' AND DATE(timestamp) = CURDATE()
        ");
        $todayClockIns = $stmt->fetch()['clock_ins'];

        send_response([
            'totalEmployees' => (int)$totalEmployees,
            'activeEmployees' => (int)$activeEmployees,
            'pendingApprovals' => (int)$pendingApprovals,
            'todayClockIns' => (int)$todayClockIns
        ]);
    } catch (\PDOException $e) {
        send_error('Failed to fetch stats: ' . $e->getMessage(), 500);
    }
}

// Approve Employee
if (preg_match('/^admin\/approve\/(\d+)$/', $route, $matches)) {
    if ($method !== 'POST') {
        send_error('Method not allowed', 405);
    }
    $id = $matches[1];

    try {
        $stmt = $pdo->prepare("UPDATE employees SET is_approved = 1 WHERE id = ? AND is_approved = 0");
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            send_response(['message' => 'Employee approved successfully.']);
        } else {
            send_error('Employee not found or already approved.', 404);
        }
    } catch (\PDOException $e) {
        send_error('Failed to approve employee: ' . $e->getMessage(), 500);
    }
}

// Reject Employee
if (preg_match('/^admin\/reject\/(\d+)$/', $route, $matches)) {
    if ($method !== 'POST') {
        send_error('Method not allowed', 405);
    }
    $id = $matches[1];

    try {
        $stmt = $pdo->prepare("DELETE FROM employees WHERE id = ? AND is_approved = 0");
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            send_response(['message' => 'Employee rejected and removed.']);
        } else {
            send_error('Employee not found or already approved.', 404);
        }
    } catch (\PDOException $e) {
        send_error('Failed to reject employee: ' . $e->getMessage(), 500);
    }
}

// Reset PIN
if (preg_match('/^admin\/reset-pin\/(\d+)$/', $route, $matches)) {
    if ($method !== 'POST') {
        send_error('Method not allowed', 405);
    }
    $id = $matches[1];
    $data = get_input_data();

    if (empty($data['newPin']) || strlen($data['newPin']) !== 6 || !ctype_digit($data['newPin'])) {
        send_error('New PIN must be exactly 6 digits.');
    }

    try {
        $pin_hash = password_hash($data['newPin'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE pins SET pin_hash = ? WHERE employee_id = ?");
        $stmt->execute([$pin_hash, $id]);

        if ($stmt->rowCount() > 0) {
            send_response(['message' => 'PIN reset successfully.']);
        } else {
            send_error('Employee not found.', 404);
        }
    } catch (\PDOException $e) {
        send_error('Failed to reset PIN: ' . $e->getMessage(), 500);
    }
}

// Deactivate Employee
if (preg_match('/^admin\/deactivate\/(\d+)$/', $route, $matches)) {
    if ($method !== 'POST') {
        send_error('Method not allowed', 405);
    }
    $id = $matches[1];

    try {
        $stmt = $pdo->prepare("UPDATE employees SET is_approved = 0 WHERE id = ?");
        $stmt->execute([$id]);
        send_response(['message' => 'Employee deactivated.']);
    } catch (\PDOException $e) {
        send_error('Failed to deactivate: ' . $e->getMessage(), 500);
    }
}

// Reactivate Employee
if (preg_match('/^admin\/reactivate\/(\d+)$/', $route, $matches)) {
    if ($method !== 'POST') {
        send_error('Method not allowed', 405);
    }
    $id = $matches[1];

    try {
        $stmt = $pdo->prepare("UPDATE employees SET is_approved = 1 WHERE id = ?");
        $stmt->execute([$id]);
        send_response(['message' => 'Employee reactivated.']);
    } catch (\PDOException $e) {
        send_error('Failed to reactivate: ' . $e->getMessage(), 500);
    }
}

// ==================== AUTH ENDPOINTS (Keep your existing code) ====================

if ($route === 'auth/signup') {
    if ($method !== 'POST') {
        send_error('Method not allowed', 405);
    }
    $data = get_input_data();

    if (empty($data['name']) || empty($data['email']) || empty($data['pin'])) {
        send_error('Name, email, and PIN are required.');
    }

    if (strlen($data['pin']) !== 6 || !ctype_digit($data['pin'])) {
        send_error('PIN must be exactly 6 digits.');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT id FROM employees WHERE email = ?");
        $stmt->execute([$data['email']]);

        if ($stmt->fetch()) {
            $pdo->rollBack();
            send_error('Email already in use.', 409);
        }

        $stmt = $pdo->prepare("
            INSERT INTO employees (name, email, role, is_approved, created_at) 
            VALUES (?, ?, 'employee', 0, NOW())
        ");
        $stmt->execute([$data['name'], $data['email']]);
        $employee_id = $pdo->lastInsertId();

        $pin_hash = password_hash($data['pin'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO pins (employee_id, pin_hash) VALUES (?, ?)");
        $stmt->execute([$employee_id, $pin_hash]);

        $pdo->commit();
        send_response(['message' => 'Account created! Awaiting admin approval.'], 201);
    } catch (\PDOException $e) {
        $pdo->rollBack();
        send_error('Failed to create account: ' . $e->getMessage(), 500);
    }
}

if ($route === 'auth/login') {
    if ($method !== 'POST') {
        send_error('Method not allowed', 405);
    }
    $data = get_input_data();

    if (empty($data['pin'])) {
        send_error('PIN is required.');
    }

    if (strlen($data['pin']) !== 6 || !ctype_digit($data['pin'])) {
        send_error('Invalid PIN format.');
    }

    try {
        $stmt = $pdo->prepare("
            SELECT e.id, e.name, e.email, e.role, p.pin_hash 
            FROM employees e
            JOIN pins p ON e.id = p.employee_id
            WHERE e.role = 'employee' AND e.is_approved = 1
        ");
        $stmt->execute();
        $users = $stmt->fetchAll();

        $authenticated_user = null;

        foreach ($users as $user) {
            if (password_verify($data['pin'], $user['pin_hash'])) {
                $authenticated_user = $user;
                break;
            }
        }

        if ($authenticated_user) {
            unset($authenticated_user['pin_hash']);
            send_response([
                'userId' => $authenticated_user['id'],
                'name' => $authenticated_user['name'],
                'email' => $authenticated_user['email'],
                'role' => $authenticated_user['role']
            ]);
        } else {
            send_error('Invalid PIN or account not approved.', 401);
        }
    } catch (\PDOException $e) {
        send_error('Login failed: ' . $e->getMessage(), 500);
    }
}

if ($route === 'auth/pin-login') {
    if ($method !== 'POST') {
        send_error('Method not allowed', 405);
    }
    $data = get_input_data();

    if (empty($data['pin'])) {
        send_error('PIN is required.');
    }

    if (strlen($data['pin']) !== 8 || !ctype_digit($data['pin'])) {
        send_error('Invalid PIN format.');
    }

    try {
        $stmt = $pdo->prepare("
            SELECT e.id, e.name, e.email, e.role, p.pin_hash 
            FROM employees e
            JOIN pins p ON e.id = p.employee_id
            WHERE e.role = 'admin' AND e.is_approved = 1
        ");
        $stmt->execute();
        $admins = $stmt->fetchAll();

        $authenticated_admin = null;

        foreach ($admins as $admin) {
            if (password_verify($data['pin'], $admin['pin_hash'])) {
                $authenticated_admin = $admin;
                break;
            }
        }

        if ($authenticated_admin) {
            unset($authenticated_admin['pin_hash']);
            send_response([
                'userId' => $authenticated_admin['id'],
                'name' => $authenticated_admin['name'],
                'email' => $authenticated_admin['email'],
                'role' => $authenticated_admin['role']
            ]);
        } else {
            send_error('Invalid PIN or account not approved.', 401);
        }
    } catch (\PDOException $e) {
        send_error('Login failed: ' . $e->getMessage(), 500);
    }
}

// ==================== EXPORT ENDPOINTS ====================

if ($route === 'admin/export') {
    if ($method !== 'GET') {
        send_error('Method not allowed', 405);
    }

    try {
        $stmt = $pdo->query("
            SELECT e.name, e.email, ce.event_type, ce.timestamp
            FROM clock_events ce
            JOIN employees e ON ce.employee_id = e.id
            ORDER BY ce.timestamp DESC
        ");
        $data = $stmt->fetchAll();

        if (empty($data)) {
            send_error('No clock events to export.', 404);
        }

        $filename = 'clockmate_export_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Employee Name', 'Email', 'Event Type', 'Timestamp']);
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    } catch (\PDOException $e) {
        send_error('Export failed: ' . $e->getMessage(), 500);
    }
}

if (preg_match('/^admin\/export-employee\/(\d+)$/', $route, $matches)) {
    if ($method !== 'GET') {
        send_error('Method not allowed', 405);
    }
    $employee_id = $matches[1];

    try {
        $stmt = $pdo->prepare("
            SELECT e.name, e.email, ce.event_type, ce.timestamp
            FROM clock_events ce
            JOIN employees e ON ce.employee_id = e.id
            WHERE ce.employee_id = ?
            ORDER BY ce.timestamp DESC
        ");
        $stmt->execute([$employee_id]);
        $data = $stmt->fetchAll();

        if (empty($data)) {
            send_error('No clock events found for this employee.', 404);
        }

        $employee_name = $data[0]['name'];
        $filename = str_replace(' ', '_', $employee_name) . '_logs_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Employee Name', 'Email', 'Event Type', 'Timestamp']);

        foreach ($data as $row) {
            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    } catch (\PDOException $e) {
        send_error('Export failed: ' . $e->getMessage(), 500);
    }
}

// ==================== 404 NOT FOUND ====================

send_error('Endpoint not found: ' . $route, 404);