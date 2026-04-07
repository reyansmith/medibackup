<?php

session_start();
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/session_audit.php";

if (isset($_SESSION['role']) && $_SESSION['role'] === 'employee') {
    // Handle tab or browser close without pressing logout.
    forceEmployeeLogout($conn, true);
}

http_response_code(204);
exit();
