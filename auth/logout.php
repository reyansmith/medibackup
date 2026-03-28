<?php

session_start();
require_once __DIR__ . "/../config/database.php";

if (isset($_SESSION['role'], $_SESSION['id']) && $_SESSION['role'] === 'employee') {
    $loginSessionId = isset($_SESSION['login_session_id']) ? (string)$_SESSION['login_session_id'] : '';
    $empId = (string)$_SESSION['id'];

    if ($loginSessionId !== '') {
        $stmt = $conn->prepare("UPDATE `session` SET logout_time = NOW() WHERE session_id = ? AND emp_id = ? AND logout_time IS NULL");
        if ($stmt) {
            $stmt->bind_param("ss", $loginSessionId, $empId);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        $stmt = $conn->prepare("UPDATE `session` SET logout_time = NOW() WHERE emp_id = ? AND logout_time IS NULL ORDER BY login_time DESC LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $empId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

$_SESSION = [];
session_destroy();
header("Location: login.php");
exit();
?>
