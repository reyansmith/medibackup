<?php

session_start();
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/session_audit.php";

if (isset($_SESSION['role'], $_SESSION['id']) && $_SESSION['role'] === 'employee') {
    $empId = (string)$_SESSION['id'];
    // Close any employee session still marked as open.
    closeOpenEmployeeSessions($conn, $empId);
}

$_SESSION = [];
session_destroy();
header("Location: login.php");
exit();
?>
