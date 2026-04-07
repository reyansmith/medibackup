<?php

// Generate a unique session audit ID for employee
function generateEmployeeSessionAuditId(): string
{
    try {
        return 'SES' . date('ymdHis') . strtoupper(bin2hex(random_bytes(2)));
    } catch (Throwable $e) {
        // Fallback if random_bytes fails
        return 'SES' . date('ymdHis') . str_pad((string)mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
    }
}

function closeOpenEmployeeSessions(mysqli $conn, string $empId, ?string $logoutTime = null): void
{
    if ($empId === '') {
        return;
    }

    $logoutTime = $logoutTime ?: date('Y-m-d H:i:s');
    $stmt = $conn->prepare("UPDATE `session` SET logout_time = ? WHERE emp_id = ? AND logout_time IS NULL");
    if ($stmt) {
        $stmt->bind_param("ss", $logoutTime, $empId);
        $stmt->execute();
        $stmt->close();
    }
}

function closeSessionsAfterDailyCutoff(mysqli $conn): void
{
    // After 10:30 PM, treat any still-open sessions as logged out.
    $now = new DateTimeImmutable();
    $cutoff = $now->setTime(22, 30, 0);
    if ($now < $cutoff) {
        return;
    }

    $logoutTime = $cutoff->format('Y-m-d H:i:s');
    $stmt = $conn->prepare("UPDATE `session` SET logout_time = ? WHERE logout_time IS NULL");
    if ($stmt) {
        $stmt->bind_param("s", $logoutTime);
        $stmt->execute();
        $stmt->close();
    }
}

function forceEmployeeLogout(mysqli $conn, bool $jsonResponse = false): void
{
    // End both the database session row and the PHP login session.
    $empId = isset($_SESSION['id']) ? (string)$_SESSION['id'] : '';
    closeOpenEmployeeSessions($conn, $empId);

    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    if ($jsonResponse) {
        http_response_code(401);
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode(["error" => "Logged out"]);
        exit();
    }

    header("Location: ../auth/login.php");
    exit();
}

function enforceEmployeeSessionRules(mysqli $conn, bool $jsonResponse = false): void
{
    if (!isset($_SESSION['role'], $_SESSION['id']) || $_SESSION['role'] !== 'employee') {
        return;
    }

    closeSessionsAfterDailyCutoff($conn);

    $empId = (string)$_SESSION['id'];
    $sessionAuditId = isset($_SESSION['login_session_id']) ? (string)$_SESSION['login_session_id'] : '';
    if ($empId === '' || $sessionAuditId === '') {
        return;
    }

    $stmt = $conn->prepare("SELECT logout_time FROM `session` WHERE session_id = ? AND emp_id = ? LIMIT 1");
    if (!$stmt) {
        return;
    }

    $stmt->bind_param("ss", $sessionAuditId, $empId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    // If the DB session was already closed, force the employee out of the app too.
    if ($row && !empty($row['logout_time'])) {
        forceEmployeeLogout($conn, $jsonResponse);
    }
}

// Ensure an employee session audit record exists
function ensureEmployeeSessionAudit(mysqli $conn): void
{
    if (!isset($_SESSION['role'], $_SESSION['id']) || $_SESSION['role'] !== 'employee') {
        return;
    }

    $empId = (string)$_SESSION['id'];
    if ($empId === '') {
        return;
    }

    $sessionAuditId = isset($_SESSION['login_session_id']) ? (string)$_SESSION['login_session_id'] : '';

    if ($sessionAuditId !== '') {
        // Check if session already exists in DB
        $checkStmt = $conn->prepare("SELECT 1 FROM `session` WHERE session_id = ? LIMIT 1");
        if ($checkStmt) {
            $checkStmt->bind_param("s", $sessionAuditId);
            $checkStmt->execute();
            $existing = $checkStmt->get_result()->fetch_row();
            $checkStmt->close();

            if ($existing) {
                return;
            }
        }
    } else {
        // Generate new session audit ID
        $sessionAuditId = generateEmployeeSessionAuditId();
        $_SESSION['login_session_id'] = $sessionAuditId;
    }

    // Insert new session audit record
    $insertStmt = $conn->prepare("INSERT INTO `session` (session_id, emp_id, login_time, logout_time) VALUES (?, ?, NOW(), NULL)");
    if ($insertStmt) {
        $insertStmt->bind_param("ss", $sessionAuditId, $empId);
        $insertStmt->execute();
        $insertStmt->close();
    }
}
?>
