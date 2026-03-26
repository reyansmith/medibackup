<?php

session_start();
require_once __DIR__ . "/../config/database.php";

if (isset($_POST['login']))
{
    $id = trim((string)($_POST['id'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $login_error_message = "Invalid Details";

    $id = mysqli_real_escape_string($conn, $id);
    $username = mysqli_real_escape_string($conn, $username);

    // CHECK ADMIN
    $sql = "SELECT * FROM admin WHERE admin_id='$id' AND username='$username'";
    $admin_query = mysqli_query($conn, $sql);

    if (mysqli_num_rows($admin_query) > 0)
    {
        $row = mysqli_fetch_assoc($admin_query);

        if (password_verify($password, $row['password']))
        {
            $_SESSION['role'] = "admin";
            $_SESSION['id'] = $row['admin_id'];
            $_SESSION['username'] = $row['username'];
            unset($_SESSION['login_session_id']);

            header("Location: ../admin/dashboard.php");
            exit();
        }
    }

    // CHECK EMPLOYEE
    $sql = "SELECT * FROM employee WHERE emp_id='$id' AND username='$username' LIMIT 1";
    $emp_query = mysqli_query($conn, $sql);

    if ($emp_query && mysqli_num_rows($emp_query) > 0)
    {
        $row = mysqli_fetch_assoc($emp_query);

        if (isset($row['status']) && $row['status'] !== 'active') {
            $login_error = true;
            $login_error_message = "Access Denied";
        } elseif (password_verify($password, $row['password']))
        {
            $_SESSION['role'] = "employee";
            $_SESSION['id'] = $row['emp_id'];
            $_SESSION['username'] = $row['username'];

            // Save login session
            $loginSessionId = "SES" . date("ymdHis") . rand(1000, 9999);
            $_SESSION['login_session_id'] = $loginSessionId;

            $emp_id = mysqli_real_escape_string($conn, $row['emp_id']);
            $loginSessionId = mysqli_real_escape_string($conn, $loginSessionId);
            $sql = "INSERT INTO `session` (session_id, emp_id, login_time, logout_time) VALUES ('$loginSessionId', '$emp_id', NOW(), NULL)";
            mysqli_query($conn, $sql);

            header("Location: ../employee/dashboard.php");
            exit();
        }
    }

    $login_error = true;
}
?>


<!DOCTYPE html>
<html>
<head>
<title>Mannath Medicals Login</title>
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    background: linear-gradient(135deg, #0f172a 0%, #1a2744 100%);
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
}

.login-container {
    width: 100%;
    max-width: 420px;
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
    padding: 50px 40px;
    animation: slideIn 0.4s ease-out;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.login-header {
    text-align: center;
    margin-bottom: 40px;
}

.login-header h1 {
    font-size: 26px;
    font-weight: 700;
    color: #111827;
    margin-bottom: 8px;
    letter-spacing: -0.5px;
}

.login-header p {
    font-size: 13px;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.form-box {
    width: 100%;
}

.form-group {
    margin-bottom: 20px;
}

.form-box label {
    display: block;
    margin-bottom: 8px;
    font-size: 13px;
    font-weight: 600;
    color: #374151;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

input {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 14px;
    color: #111827;
    background: #ffffff;
    transition: all 0.2s ease;
}

input::placeholder {
    color: #9ca3af;
}

input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    background: #f8faff;
}

button {
    width: 100%;
    padding: 12px;
    background: #3b82f6;
    color: white;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 700;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    transition: all 0.3s ease;
    margin-top: 16px;
}

button:hover {
    background: #2563eb;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
    transform: translateY(-1px);
}

button:active {
    transform: translateY(0);
}

/* Error Message */
.error-message {
    background: #fee2e2;
    border: 1px solid #fecaca;
    border-radius: 6px;
    padding: 12px 14px;
    color: #991b1b;
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 20px;
    text-align: center;
}

</style>
</head>

<body>

<div class="login-container">

    <div class="login-header">
        <h1>Mannath Medicals</h1>
        <p>Login to Your Account</p>
    </div>

    <?php if (isset($login_error) && $login_error): ?>
    <div class="error-message"><?php echo htmlspecialchars($login_error_message ?? "Invalid Details", ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="form-box">
        <form method="POST">
            <div class="form-group">
                <label for="id">User ID</label>
                <input type="text" id="id" name="id" placeholder="Enter your ID" required>
            </div>

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" placeholder="Enter your username" required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Enter your password" required>
            </div>

            <button type="submit" name="login">Login</button>
        </form>
    </div>

</div>

</body>
</html>
