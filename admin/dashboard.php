<?php

// start session
session_start();
// connect to database
require_once __DIR__ . "/../config/database.php";

// check if admin is logged in
if (!isset($_SESSION['role']) || $_SESSION['role'] != "admin") {
    header("Location: ../auth/login.php");
    exit();
}

// get low stock count (less than 10)
$lowStock = 0;
$q = $conn->query("SELECT COUNT(*) as total FROM stock WHERE quantity < 10");
if ($q) {
    $row = $q->fetch_assoc();
    $lowStock = $row['total'];
}

// get expiring soon count (next 30 days)
$expiring = 0;
$q = $conn->query("SELECT COUNT(*) as total FROM stock WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
if ($q) {
    $row = $q->fetch_assoc();
    $expiring = $row['total'];
}

// get today's sales
$sales = 0;
$q = $conn->query("SELECT SUM(total_amount) as total FROM bill WHERE DATE(bill_date)=CURDATE()");
if ($q) {
    $row = $q->fetch_assoc();
    $sales = $row['total'] ?? 0;
}

// get today's bills count
$todayBills = 0;
$q = $conn->query("SELECT COUNT(*) as total FROM bill WHERE DATE(bill_date)=CURDATE()");
if ($q) {
    $row = $q->fetch_assoc();
    $todayBills = $row['total'];
}

// get top employees (last 30 days)
$leaderboard = [];
$sql = "SELECT e.username AS employee_name, COUNT(b.bill_id) AS bill_count, COALESCE(SUM(b.total_amount), 0) AS total_sales FROM employee e LEFT JOIN bill b ON b.emp_id = e.emp_id AND DATE(b.bill_date) BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND CURDATE() GROUP BY e.emp_id, e.username ORDER BY total_sales DESC, bill_count DESC, e.username ASC LIMIT 5";
$q = $conn->query($sql);
if ($q) {
    while ($row = $q->fetch_assoc()) {
        $leaderboard[] = $row;
    }
}

// get recent transactions
$recentTransactions = [];
$sql = "SELECT b.bill_id, b.bill_date, b.customer_name, b.total_amount, e.username AS employee_name FROM bill b LEFT JOIN employee e ON e.emp_id = b.emp_id ORDER BY b.bill_date DESC LIMIT 8";
$q = $conn->query($sql);
if ($q) {
    while ($row = $q->fetch_assoc()) {
        $recentTransactions[] = $row;
    }
}
?>

<?php include __DIR__ . "/../includes/header.php"; ?>
<?php include __DIR__ . "/../includes/sidebar.php"; ?>

<div class="main dashboard-page">

    <div class="topbar">
        <div class="topbar-text">
            <h2>Dashboard</h2>
        </div>

        <div class="top-actions">
            <a href="../auth/logout.php" class="logout-btn">Logout</a>
        </div>
    </div>

    <!-- CARDS -->
    <div class="cards">

        <div class="card">
            <h4><i class="fas fa-exclamation-triangle" style="margin-right: 6px;"></i>Low Stock</h4>
            <h2><?php echo $lowStock; ?></h2>
        </div>

        <div class="card">
            <h4><i class="fas fa-clock" style="margin-right: 6px;"></i>Expiring Soon</h4>
            <h2><?php echo $expiring; ?></h2>
        </div>

        <div class="card">
            <h4><i class="fas fa-dollar-sign" style="margin-right: 6px;"></i>Today's Sales</h4>
            <h2>&#8377; <?php echo number_format((float)$sales, 2); ?></h2>
        </div>

        <div class="card">
            <h4><i class="fas fa-receipt" style="margin-right: 6px;"></i>Today's Bills</h4>
            <h2><?php echo (int)$todayBills; ?></h2>
        </div>

    </div>

    <div class="dashboard-grid">
        <div class="box">
            <h3><i class="fas fa-chart-bar" style="margin-right: 8px; color: #3b82f6;"></i>Sales (7 Days)</h3>
            <div class="chart-compact">
                <canvas id="salesChart"></canvas>
            </div>
        </div>

        <div class="box">
            <h3><i class="fas fa-crown" style="margin-right: 8px; color: #10b981;"></i>Top Employees (30 Days)</h3>
            <div class="table-wrap">
                <table class="leaderboard-table rank-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Employee</th>
                            <th>Bills</th>
                            <th>Sales</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($leaderboard)){ ?>
                            <tr>
                                <td colspan="4">No employee billing data found.</td>
                            </tr>
                        <?php } else { ?>
                            <?php foreach($leaderboard as $index => $item){ ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><?php echo $item['employee_name']; ?></td>
                                    <td><?php echo (int)$item['bill_count']; ?></td>
                                    <td>&#8377; <?php echo number_format((float)$item['total_sales'], 2); ?></td>
                                </tr>
                            <?php } ?>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="box recent-box">
        <h3><i class="fas fa-history" style="margin-right: 8px; color: #6366f1;"></i>Recent Transactions</h3>
        <div class="table-wrap">
            <table class="leaderboard-table transactions-table">
                <thead>
                    <tr>
                        <th>Bill</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Employee</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($recentTransactions)){ ?>
                        <tr>
                            <td colspan="5">No recent transactions found.</td>
                        </tr>
                    <?php } else { ?>
                        <?php foreach($recentTransactions as $txn){ ?>
                            <tr>
                                <td><?php echo (string)$txn['bill_id']; ?></td>
                                <td><?php echo date("d M Y, h:i A", strtotime($txn['bill_date'])); ?></td>
                                <td><?php echo $txn['customer_name'] ?? '-'; ?></td>
                                <td><?php echo $txn['employee_name'] ?? '-'; ?></td>
                                <td>&#8377; <?php echo number_format((float)$txn['total_amount'], 2); ?></td>
                            </tr>
                        <?php } ?>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php $salesDataEndpoint = 'dashboard_sales_data.php'; ?>
<?php include __DIR__ . "/../includes/footer.php"; ?>













