<?php

// start session
session_start();
// connect to db
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/session_audit.php";

// check admin login
if (!isset($_SESSION['role']) || $_SESSION['role'] !== "admin") {
    header("Location: ../auth/login.php");
    exit();
}

$message = "";
$error_message = "";
$section = (isset($_GET['section']) && $_GET['section'] === "users") ? "users" : "sessions";
$session_view = (isset($_GET['session_view']) && $_GET['session_view'] === "history") ? "history" : "current";
$show_create_form = false;

// Close leftover open sessions after the daily 10:30 PM cutoff.
closeSessionsAfterDailyCutoff($conn);

// remove employee (set inactive)
if ($_SERVER['REQUEST_METHOD'] === "GET" && isset($_GET['delete'])) {
    $delete_id = trim($_GET['delete']);
    if ($delete_id === "") {
        $error_message = "Employee ID missing.";
    } else {
        $delete_id = mysqli_real_escape_string($conn, $delete_id);
        $sql = "UPDATE employee SET status = 'inactive' WHERE emp_id = '$delete_id' AND status = 'active'";
        mysqli_query($conn, $sql);
        if (mysqli_affected_rows($conn) > 0) {
            $sql = "UPDATE `session` SET logout_time = NOW() WHERE emp_id = '$delete_id' AND logout_time IS NULL";
            mysqli_query($conn, $sql);
            $message = "Employee removed.";
        } else {
            $sql = "SELECT emp_id, status FROM employee WHERE emp_id = '$delete_id' LIMIT 1";
            $result = mysqli_query($conn, $sql);
            if ($result && mysqli_num_rows($result) > 0) {
                $row = mysqli_fetch_assoc($result);
                if (isset($row['status']) && $row['status'] === 'inactive') {
                    $error_message = "Already inactive.";
                } else {
                    $error_message = "Could not remove employee.";
                }
            } else {
                $error_message = "Employee not found.";
            }
        }
    }
}

// add new employee from create form in user management section
if ($_SERVER['REQUEST_METHOD'] === "POST" && isset($_POST['action'])) {
    if ($_POST['action'] === "create_user") {
        $show_create_form = true;
        $new_id = trim($_POST['new_emp_id'] ?? "");
        $new_username = trim($_POST['new_username'] ?? "");
        $new_email = trim($_POST['new_email'] ?? "");
        $new_password = $_POST['new_password'] ?? "";
        if ($new_id === "" || $new_username === "" || $new_email === "" || $new_password === "") {
            $error_message = "Fill all fields.";
        } else {
            $new_id = mysqli_real_escape_string($conn, $new_id);
            $new_username = mysqli_real_escape_string($conn, $new_username);
            $new_email = mysqli_real_escape_string($conn, $new_email);
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $hashed_password = mysqli_real_escape_string($conn, $hashed_password);
            // First check by employee ID so duplicate IDs do not crash the insert.
            $sql = "SELECT emp_id, status FROM employee WHERE emp_id = '$new_id' LIMIT 1";
            $id_result = mysqli_query($conn, $sql);
            if ($id_result && mysqli_num_rows($id_result) > 0) {
                $existing_id_user = mysqli_fetch_assoc($id_result);
                if (isset($existing_id_user['status']) && $existing_id_user['status'] === 'inactive') {
                    // Reactivate the old employee record with the same ID.
                    $sql = "UPDATE employee
                            SET username = '$new_username',
                                email = '$new_email',
                                password = '$hashed_password',
                                status = 'active'
                            WHERE emp_id = '$new_id'";
                    if (mysqli_query($conn, $sql)) {
                        $message = "Employee added.";
                        $show_create_form = false;
                    } else {
                        $error_message = "Could not reactivate employee.";
                    }
                } else {
                    $error_message = "Employee ID already exists.";
                }
            } else {
                // Then check by username so duplicate usernames do not crash the insert.
                $sql = "SELECT emp_id, status FROM employee WHERE username = '$new_username' LIMIT 1";
                $result = mysqli_query($conn, $sql);
                if ($result && mysqli_num_rows($result) > 0) {
                    $existing_user = mysqli_fetch_assoc($result);
                    if (isset($existing_user['status']) && $existing_user['status'] === 'inactive') {
                        // Reactivate the old employee record instead of inserting a new one.
                        $existing_emp_id = mysqli_real_escape_string($conn, $existing_user['emp_id']);
                        $sql = "UPDATE employee
                                SET emp_id = '$new_id',
                                    username = '$new_username',
                                    email = '$new_email',
                                    password = '$hashed_password',
                                    status = 'active'
                                WHERE emp_id = '$existing_emp_id'";
                        if (mysqli_query($conn, $sql)) {
                            $message = "Employee added.";
                            $show_create_form = false;
                        } else {
                            $error_message = "Could not reactivate employee.";
                        }
                    } else {
                        $error_message = "Employee already exists.";
                    }
                } else {
                    // Username does not exist, so create a new active employee.
                    $sql = "INSERT INTO employee (emp_id, username, email, password) VALUES ('$new_id', '$new_username', '$new_email', '$hashed_password')";
                    if (mysqli_query($conn, $sql)) {
                        $message = "Employee added.";
                        $show_create_form = false;
                    } else {
                        $error_message = "Could not add employee.";
                    }
                }
            }
        }
    }
}

$session_rows = [];
if ($session_view === "history") {
    $session_sql = "SELECT s.emp_id, e.username, e.status, s.login_time, s.logout_time FROM `session` s LEFT JOIN employee e ON e.emp_id = s.emp_id ORDER BY s.login_time DESC";
} else {
    // Show only the latest open session row for each employee.
    $session_sql = "
        SELECT s.emp_id, e.username, e.status, s.login_time, s.logout_time
        FROM `session` s
        INNER JOIN (
            SELECT emp_id, MAX(login_time) AS latest_login_time
            FROM `session`
            WHERE logout_time IS NULL
            GROUP BY emp_id
        ) latest_session
            ON latest_session.emp_id = s.emp_id
           AND latest_session.latest_login_time = s.login_time
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
        <!-- This dropdown switches between session view and user management. -->
        <form method="GET" class="employees-section-form">
            <label for="section"><strong>Choose Section:</strong></label>
            <select name="section" id="section" onchange="this.form.submit()">
                <option value="sessions" <?php echo ($section === "sessions") ? "selected" : ""; ?>>Session Table</option>
                <option value="users" <?php echo ($section === "users") ? "selected" : ""; ?>>User Management</option>
            </select>
            <?php if ($section === "sessions") { ?>
                <input type="hidden" name="session_view" value="<?php echo $session_view; ?>">
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
            <!-- These links switch between current login and login history. -->
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

            <!-- Button to show or hide the add employee form. -->
            <div class="employees-users-toolbar">
                <button type="button" id="addEmployeeBtn" class="employees-user-btn">
                    <?php echo $show_create_form ? "Cancel" : "Add Employee"; ?>
                </button>
            </div>

            <!-- Hidden create form. It opens when the button is clicked. -->
            <div class="employees-user-grid<?php echo $show_create_form ? " is-open" : ""; ?>" id="employeeCreatePanel">
                <form method="POST" class="employees-user-card">
                    <input type="hidden" name="action" value="create_user">
                    <h4 class="employees-user-title">Add Employee</h4>
                    <input type="text" name="new_emp_id" placeholder="Employee ID" value="<?php echo $_POST['new_emp_id'] ?? ''; ?>" required class="employees-user-input">
                    <input type="text" name="new_username" placeholder="Username" value="<?php echo $_POST['new_username'] ?? ''; ?>" required class="employees-user-input">
                    <input type="email" name="new_email" placeholder="Email" value="<?php echo $_POST['new_email'] ?? ''; ?>" required class="employees-user-input">
                    <input type="password" name="new_password" placeholder="Password" required class="employees-user-input">
                    <button type="submit" class="employees-user-btn">Save Employee</button>
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

<script>
// Show or hide the add employee panel.
(function () {
    var addEmployeeBtn = document.getElementById('addEmployeeBtn');
    var employeeCreatePanel = document.getElementById('employeeCreatePanel');

    if (!addEmployeeBtn || !employeeCreatePanel) return;

    addEmployeeBtn.addEventListener('click', function () {
        var isOpen = employeeCreatePanel.classList.toggle('is-open');
        addEmployeeBtn.textContent = isOpen ? 'Cancel' : 'Add Employee';
    });
})();
</script>

<?php include __DIR__ . "/../includes/footer.php"; ?>











