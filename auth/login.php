<?php

session_start();
require_once __DIR__ . "/../config/database.php";

// Run login check only when the form is submitted.
if (isset($_POST['login']))
{
    // Read and clean form values.
    $id = trim((string)($_POST['id'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $login_error_message = "Please check your ID, username, and password.";

    // Escape input before using it in queries.
    $id = mysqli_real_escape_string($conn, $id);
    $username = mysqli_real_escape_string($conn, $username);

    // First check if the user is an admin.
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

    // If not admin, check if the user is an employee.
    $sql = "SELECT * FROM employee WHERE emp_id='$id' AND username='$username' LIMIT 1";
    $emp_query = mysqli_query($conn, $sql);

    if ($emp_query && mysqli_num_rows($emp_query) > 0)
    {
        $row = mysqli_fetch_assoc($emp_query);

        if (isset($row['status']) && $row['status'] !== 'active') {
            $login_error = true;
            $login_error_message = "Your account is inactive. Please contact the admin.";
        } elseif (password_verify($password, $row['password']))
        {
            $_SESSION['role'] = "employee";
            $_SESSION['id'] = $row['emp_id'];
            $_SESSION['username'] = $row['username'];

            // Save employee login time in the session table.
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

    // If nothing matched, show the login error.
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

:root {
    --bg: #111b2d;
    --bg-top: #16233a;
    --card: #ffffff;
    --card-soft: #f8fafc;
    --text: #0b1324;
    --muted: #6b7280;
    --line: #dbe3ee;
    --accent: #2f6fed;
    --accent-hover: #2459c7;
    --shadow: 0 28px 70px rgba(4, 11, 24, 0.32);
}

body {
    font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif;
    background:
        radial-gradient(circle at top center, rgba(78, 120, 255, 0.08), transparent 24%),
        linear-gradient(180deg, var(--bg-top) 0%, var(--bg) 100%);
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 32px 24px;
    color: var(--text);
}

.login-container {
    display: flex;
    width: 100%;
    max-width: 940px;
    min-height: 560px;
    background: var(--card);
    border-radius: 24px;
    box-shadow: var(--shadow);
    overflow: hidden;
}

.login-image-side {
    width: 38%;
    background:
        linear-gradient(180deg, #f7faff 0%, #edf3fb 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px;
    border-right: 1px solid rgba(15, 23, 42, 0.06);
}

.logo-frame {
    width: 100%;
    max-width: 300px;
}

.logo-frame img {
    display: block;
    width: 100%;
    height: auto;
}

.login-form-side {
    flex: 1;
    padding: 64px 56px;
    background: var(--card);
    width: 100%;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.login-header {
    margin-bottom: 36px;
}

.login-header h1 {
    font-size: 38px;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 6px;
    letter-spacing: -0.04em;
}

.form-box {
    width: 100%;
    max-width: 420px;
}

.form-group {
    margin-bottom: 18px;
}

.form-box label {
    display: block;
    margin-bottom: 8px;
    font-size: 11px;
    font-weight: 700;
    color: #425066;
    text-transform: uppercase;
    letter-spacing: 0.14em;
}

input {
    width: 100%;
    padding: 16px 17px;
    border: 1px solid var(--line);
    border-radius: 14px;
    font-size: 15px;
    color: var(--text);
    background: var(--card-soft);
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
}

input::placeholder {
    color: #99a5b5;
}

input:focus {
    outline: none;
    border-color: rgba(47, 111, 237, 0.42);
    box-shadow: 0 0 0 4px rgba(47, 111, 237, 0.1);
    background: #ffffff;
}

button {
    width: 100%;
    padding: 16px;
    background: var(--accent);
    color: white;
    border: none;
    border-radius: 14px;
    cursor: pointer;
    font-weight: 700;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.18em;
    transition: background 0.2s ease;
    margin-top: 22px;
}

button:hover {
    background: var(--accent-hover);
}

button:active {
    transform: translateY(0);
}

.error-message {
    background: #fff1f2;
    border: 1px solid #fecdd3;
    border-radius: 12px;
    padding: 13px 14px;
    color: #991b1b;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 20px;
}

@media (max-width: 920px) {
    .login-container {
        max-width: 480px;
        display: block;
    }

    .login-image-side {
        display: none;
    }

    .login-form-side {
        padding: 44px 32px;
    }

    .login-header h1 {
        font-size: 32px;
    }
}

@media (max-width: 520px) {
    body {
        padding: 18px;
    }

    .login-container {
        border-radius: 20px;
    }

    .login-form-side {
        padding: 36px 22px 30px;
    }

    .login-header h1 {
        font-size: 28px;
    }
}

</style>
</head>

<body>

<div class="login-container">
    <!-- Left side logo area -->
    <div class="login-image-side">
        <div class="logo-frame">
            <img src="../assets/medilogo.png" alt="Mannath Medicals">
        </div>
    </div>

    <!-- Right side login form -->
    <div class="login-form-side">
        <div class="login-header">
            <h1>Medivault</h1>
        </div>

        <?php if (isset($login_error) && $login_error): ?>
        <div class="error-message"><?php echo htmlspecialchars($login_error_message ?? "Invalid Details", ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="form-box">
            <form method="POST">
                <!-- User ID input -->
                <div class="form-group">
                    <label for="id">User ID</label>
                    <input type="text" id="id" name="id" placeholder="Enter your ID" value="<?php echo htmlspecialchars($_POST['id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <!-- Username input -->
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" placeholder="Enter your username" value="<?php echo htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <!-- Password input -->
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                </div>

                <!-- Login button -->
                <button type="submit" name="login">Login</button>
            </form>
        </div>
    </div>

</div>

</body>
</html>
