<?php

session_start();
require_once __DIR__ . "/../config/database.php";

// Only admin can access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== "admin") {
    header("Location: ../auth/login.php");
    exit();
}

$message = "";
$error_message = "";
$section = (isset($_GET['section']) && $_GET['section'] === "users") ? "users" : "sessions";
$session_view = (isset($_GET['session_view']) && $_GET['session_view'] === "history") ? "history" : "current";

/* ---------------- SOFT DELETE EMPLOYEE ---------------- */
if (isset($_GET['delete'])) {
    $delete_id = trim($_GET['delete']);

    if ($delete_id === "") {
        $error_message = "Invalid employee ID.";
    } else {
        $delete_id = mysqli_real_escape_string($conn, $delete_id);

        $sql = "UPDATE employee SET status = 'inactive' WHERE emp_id = '$delete_id' AND status = 'active'";
        mysqli_query($conn, $sql);

        if (mysqli_affected_rows($conn) > 0) {
            $sql = "UPDATE `session` SET logout_time = NOW() WHERE emp_id = '$delete_id' AND logout_time IS NULL";
            mysqli_query($conn, $sql);
            $message = "Employee removed successfully!";
        } else {
            $sql = "SELECT emp_id, status FROM employee WHERE emp_id = '$delete_id' LIMIT 1";
            $result = mysqli_query($conn, $sql);

            if ($result && mysqli_num_rows($result) > 0) {
                $row = mysqli_fetch_assoc($result);

                if (isset($row['status']) && $row['status'] === 'inactive') {
                    $error_message = "Employee is already inactive.";
                } else {
                    $error_message = "Employee could not be removed.";
                }
            } else {
                $error_message = "Employee not found.";
            }
        }
    }
}

/* ---------------- USER MANAGEMENT (CREATE / UPDATE) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === "POST" && isset($_POST['action'])) {
    if ($_POST['action'] === "create_user") {
        $new_id = trim($_POST['new_emp_id'] ?? "");
        $new_username = trim($_POST['new_username'] ?? "");
        $new_email = trim($_POST['new_email'] ?? "");
        $new_password = $_POST['new_password'] ?? "";

        if ($new_id === "" || $new_username === "" || $new_email === "" || $new_password === "") {
            $error_message = "Please fill all fields to create a user.";
        } else {
            $new_id = mysqli_real_escape_string($conn, $new_id);
            $new_username = mysqli_real_escape_string($conn, $new_username);
            $new_email = mysqli_real_escape_string($conn, $new_email);
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $hashed_password = mysqli_real_escape_string($conn, $hashed_password);

            $sql = "SELECT emp_id FROM employee WHERE emp_id = '$new_id'";
            $result = mysqli_query($conn, $sql);

            if ($result && mysqli_num_rows($result) > 0) {
                $error_message = "Employee ID already exists.";
            } else {
                $sql = "INSERT INTO employee (emp_id, username, email, password) VALUES ('$new_id', '$new_username', '$new_email', '$hashed_password')";
                if (mysqli_query($conn, $sql)) {
                    $message = "User created successfully.";
                } else {
                    $error_message = "Failed to create user.";
                }
            }
        }
    }

    // if ($_POST['action'] === "update_user") {
    //     $edit_id = trim($_POST['edit_emp_id'] ?? "");
    //     $edit_username = trim($_POST['edit_username'] ?? "");
    //     $edit_email = trim($_POST['edit_email'] ?? "");
    //     $edit_password = $_POST['edit_password'] ?? "";

    //     if ($edit_id === "" || $edit_username === "" || $edit_email === "") {
    //         $error_message = "ID, username, and email are required for update.";
    //     } else {
    //         if ($edit_password !== "") {
    //             $hashed_password = password_hash($edit_password, PASSWORD_DEFAULT);
    //             $stmt_update = $conn->prepare("UPDATE employee SET username = ?, email = ?, password = ? WHERE emp_id = ?");
    //             $stmt_update->bind_param("ssss", $edit_username, $edit_email, $hashed_password, $edit_id);
    //         } else {
    //             $stmt_update = $conn->prepare("UPDATE employee SET username = ?, email = ? WHERE emp_id = ?");
    //             $stmt_update->bind_param("sss", $edit_username, $edit_email, $edit_id);
    //         }

    //         if ($stmt_update->execute()) {
    //             if ($stmt_update->affected_rows > 0) {
    //                 $message = "User updated successfully.";
    //             } else {
    //                 $error_message = "No changes made or user not found.";
    //             }
    //         } else {
    //             $error_message = "Failed to update user.";
    //         }
    //         $stmt_update->close();
    //     }
    // }
}

/* ---------------- FETCH SESSION TABLE ---------------- */
$session_rows = [];

if ($session_view === "history") {
    $session_sql = "
        SELECT s.emp_id, e.username, e.status, s.login_time, s.logout_time
        FROM `session` s
        LEFT JOIN employee e ON e.emp_id = s.emp_id
        ORDER BY s.login_time DESC
    ";
} else {
    $session_sql = "
        SELECT s.emp_id, e.username, e.status, s.login_time, s.logout_time
        FROM `session` s
        LEFT JOIN employee e ON e.emp_id = s.emp_id
        WHERE s.logout_time IS NULL
          AND (e.status = 'active' OR e.status IS NULL)
        ORDER BY s.login_time DESC
    ";
}

$session_query = $conn->query($session_sql);
if ($session_query) {
    while ($row = mysqli_fetch_assoc($session_query)) {
        $session_rows[] = $row;
    }
}

/* ---------------- FETCH USERS ---------------- */
$users = [];
$users_query = mysqli_query($conn, "SELECT emp_id, username, email FROM employee WHERE status = 'active' ORDER BY username ASC");
if ($users_query) {
    while ($row = mysqli_fetch_assoc($users_query)) {
        $users[] = $row;
    }
}
?>

<?php include __DIR__ . "/../includes/header.php"; ?>
<?php include __DIR__ . "/../includes/sidebar.php"; ?>

<div class="main employees-main">
    <div class="topbar">
        <div>
            <h2>Employees</h2>
            <p>Manage employee records</p>
        </div>
        <a href="../auth/logout.php" class="logout-btn">Logout</a>
    </div>

    <div class="box employees-box">
        <form method="GET" class="employees-section-form">
            <label for="section"><strong>Choose Section:</strong></label>
            <select name="section" id="section" onchange="this.form.submit()">
                <option value="sessions" <?php echo ($section === "sessions") ? "selected" : ""; ?>>Session Table</option>
                <option value="users" <?php echo ($section === "users") ? "selected" : ""; ?>>User Management</option>
            </select>
            <?php if ($section === "sessions") { ?>
                <input type="hidden" name="session_view" value="<?php echo htmlspecialchars($session_view, ENT_QUOTES, 'UTF-8'); ?>">
            <?php } ?>
        </form>

        <?php if ($message) { ?>
            <p class="status-success"><?php echo $message; ?></p>
        <?php } ?>
        <?php if ($error_message) { ?>
            <p class="status-error"><?php echo $error_message; ?></p>
        <?php } ?>

        <?php if ($section === "sessions") { ?>
            <h3 class="employees-section-title">Session Table</h3>
            <div class="inv-nav">
                <a href="?section=sessions&session_view=current" class="<?php echo ($session_view === "current") ? "on" : ""; ?>">Current Login</a>
                <a href="?section=sessions&session_view=history" class="<?php echo ($session_view === "history") ? "on" : ""; ?>">Login History</a>
            </div>
            <div class="table-wrap">
            <table class="leaderboard-table employees-table">
                <thead>
                    <tr>
                        <th>Employee ID</th>
                        <th>Username</th>
                        <th>Login Time</th>
                        <th>Logout Time</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($session_rows)) { ?>
                    <tr>
                        <td colspan="4" class="inv-empty-row">
                            <?php echo ($session_view === "current") ? "No employees are currently logged in." : "No session records found."; ?>
                        </td>
                    </tr>
                <?php } else { ?>
                    <?php foreach ($session_rows as $session_row) { ?>
                        <?php $isInactiveEmployee = isset($session_row['status']) && $session_row['status'] === 'inactive'; ?>
                        <tr>
                            <td><?php echo $session_row['emp_id']; ?></td>
                            <td><?php echo $session_row['username'] ?? "-"; ?></td>
                            <td><?php echo $session_row['login_time']; ?></td>
                            <td>
                                <?php if (!empty($session_row['logout_time'])) { ?>
                                    <?php echo $session_row['logout_time']; ?>
                                <?php } elseif ($isInactiveEmployee) { ?>
                                    <span class="employees-live-status">Logged Out</span>
                                <?php } else { ?>
                                    <span class="employees-live-status">Still Logged In</span>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                <?php } ?>
                </tbody>
            </table>
            </div>
        <?php } ?>

        <?php if ($section === "users") { ?>
            <h3 class="employees-section-title">User Management</h3>

            <div class="employees-user-grid">
                <form method="POST" class="employees-user-card">
                    <input type="hidden" name="action" value="create_user">
                    <h4 class="employees-user-title">Create User</h4>
                    <input type="text" name="new_emp_id" placeholder="Employee ID" required class="employees-user-input">
                    <input type="text" name="new_username" placeholder="Username" required class="employees-user-input">
                    <input type="email" name="new_email" placeholder="Email" required class="employees-user-input">
                    <input type="password" name="new_password" placeholder="Password" required class="employees-user-input">
                    <button type="submit" class="employees-user-btn">Create</button>
                </form>
            </div>

            <h4 class="employees-subtitle">Employee Users</h4>
            <div class="table-wrap">
            <table class="leaderboard-table employees-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($users)) { ?>
                    <tr>
                        <td colspan="4" class="inv-empty-row">No users found.</td>
                    </tr>
                <?php } else { ?>
                    <?php foreach ($users as $user) { ?>
                        <tr>
                            <td><?php echo $user['emp_id']; ?></td>
                            <td><?php echo $user['username']; ?></td>
                            <td><?php echo $user['email']; ?></td>
                            <td class="employees-action-cell">
                                <a href="?section=users&delete=<?php echo urlencode($user['emp_id']); ?>"
                                   onclick="return confirm('Delete this user?')"
                                   class="btn btn-sm employees-remove-btn">
                                   Remove
                                </a>
                            </td>
                        </tr>
                    <?php } ?>
                <?php } ?>
                </tbody>
            </table>
            </div>
        <?php } ?>

    </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>











