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
