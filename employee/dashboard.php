<?php


// Start session and check employee login
session_start();
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/session_audit.php";
if (!isset($_SESSION['role']) || $_SESSION['role'] !== "employee") {
    // Redirect to login if not employee
    header("Location: ../auth/login.php");
    exit();
}
// Check session audit
ensureEmployeeSessionAudit($conn);

// Get employee id from session
$empId = (string)$_SESSION['id'];

// Initialize dashboard variables
$todaySales = 0.0;
$todayBills = 0;
$todayItemsSold = 0;
$recentTransactions = [];
$paymentRows = [];


// Get today's sales and bill count
$todayStmt = $conn->prepare("
    SELECT COALESCE(SUM(total_amount), 0) AS sales_total,
           COUNT(*) AS bill_count
    FROM bill
    WHERE emp_id = ?
      AND DATE(bill_date) = CURDATE()
");
$todayStmt->bind_param("s", $empId);
$todayStmt->execute();
$todayRow = $todayStmt->get_result()->fetch_assoc();
if ($todayRow) {
    $todaySales = (float)($todayRow['sales_total'] ?? 0);
    $todayBills = (int)($todayRow['bill_count'] ?? 0);
}
$todayStmt->close();


// Get total items sold today
$itemsStmt = $conn->prepare("
    SELECT COALESCE(SUM(bd.quantity), 0) AS total_items
    FROM bill_details bd
    JOIN bill b ON b.bill_id = bd.bill_id
    WHERE b.emp_id = ?
      AND DATE(b.bill_date) = CURDATE()
");
$itemsStmt->bind_param("s", $empId);
$itemsStmt->execute();
$itemsRow = $itemsStmt->get_result()->fetch_assoc();
if ($itemsRow) {
    $todayItemsSold = (int)($itemsRow['total_items'] ?? 0);
}
$itemsStmt->close();


// Get today's payment method split
$paymentStmt = $conn->prepare("
        SELECT COALESCE(payment_method, 'Unknown') AS payment_method,
                     COUNT(*) AS bill_count,
                     COALESCE(SUM(total_amount), 0) AS total_amount
        FROM bill
        WHERE emp_id = ?
            AND DATE(bill_date) = CURDATE()
        GROUP BY payment_method
        ORDER BY total_amount DESC
");
$paymentStmt->bind_param("s", $empId);
$paymentStmt->execute();
$paymentRes = $paymentStmt->get_result();
while ($row = $paymentRes->fetch_assoc()) {
        $paymentRows[] = $row;
}
$paymentStmt->close();


// Get 5 most recent bills
$recentStmt = $conn->prepare("
    SELECT bill_id, bill_date, customer_name, payment_method, total_amount
    FROM bill
    WHERE emp_id = ?
    ORDER BY bill_date DESC
    LIMIT 5
");
$recentStmt->bind_param("s", $empId);
$recentStmt->execute();
$recentRes = $recentStmt->get_result();
while ($row = $recentRes->fetch_assoc()) {
    $recentTransactions[] = $row;
}
$recentStmt->close();


// Prepare data for payment chart
$paymentLabels = [];
$paymentValues = [];
foreach ($paymentRows as $row) {
    $paymentLabels[] = (string)$row['payment_method'];
    $paymentValues[] = (float)$row['total_amount'];
}
if (empty($paymentLabels)) {
    $paymentLabels = ['No Data'];
    $paymentValues = [0];
}


// Set endpoint for sales chart AJAX
$salesDataEndpoint = 'dashboard_sales_data.php';
// Include header and sidebar
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar.php";
?>

<div class="main dashboard-page employee-dashboard-page">
    <div class="topbar">
        <div class="topbar-text">
            <h2>Employee Dashboard</h2>
        </div>
        <div class="top-actions">
            <a href="../auth/logout.php" class="logout-btn">Logout</a>
        </div>
    </div>

    <div class="cards">
        <div class="card">
            <h4>Today's Sales</h4>
            <h2>&#8377; <?php echo number_format($todaySales, 2); ?></h2>
        </div>

        <div class="card">
            <h4>Bills Created Today</h4>
            <h2><?php echo $todayBills; ?></h2>
        </div>

        <div class="card">
            <h4>Total Items Sold Today</h4>
            <h2><?php echo $todayItemsSold; ?></h2>
        </div>
    </div>

    <div class="employee-dashboard-layout">
        <div class="employee-dashboard-main">
            <div class="box">
                <h3>My Weekly Sales Snapshot</h3>
                <div class="chart-compact">
                    <canvas id="salesChart"></canvas>
                </div>
            </div>

            <div class="box recent-box">
                <h3>My Recent Bills</h3>
                <div class="table-wrap">
                    <table class="leaderboard-table transactions-table">
                        <thead>
                            <tr>
                                <th>Bill #</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Payment</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentTransactions)) { ?>
                                <tr>
                                    <td colspan="5">No bills found.</td>
                                </tr>
                            <?php } else { ?>
                                <?php foreach ($recentTransactions as $txn) { ?>
                                    <tr>
                                        <td><?php echo (string)$txn['bill_id']; ?></td>
                                        <td><?php echo date("d M Y, h:i A", strtotime($txn['bill_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($txn['customer_name'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($txn['payment_method'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>&#8377; <?php echo number_format((float)$txn['total_amount'], 2); ?></td>
                                    </tr>
                                <?php } ?>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Inventory Overview Section -->
            <div class="box">
                <h3>Inventory Overview</h3>
                <?php
                // Fetch product inventory summary
                $invProducts = $conn->query("
                    SELECT p.medicine_id, p.medicine_name, p.description, IFNULL(SUM(s.quantity),0) AS total_stock
                    FROM product p
                    LEFT JOIN stock s ON s.medicine_id=p.medicine_id
                    GROUP BY p.medicine_id
                    ORDER BY p.medicine_id ASC
                ");
                ?>
                <div class="table-wrap">
                    <table class="leaderboard-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Description</th>
                                <th>Total Stock</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($invProducts && $invProducts->num_rows > 0) { ?>
                            <?php while ($row = $invProducts->fetch_assoc()) { ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['medicine_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['medicine_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['description'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo (int)$row['total_stock']; ?></td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr>
                                <td colspan="4">No inventory records found.</td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="employee-dashboard-side">
            <div class="box">
                <h3>Today's Payment Split</h3>
                <div class="chart-compact reports-chart">
                    <canvas id="employeePaymentChart"></canvas>
                </div>
                <div class="table-wrap">
                    <table class="leaderboard-table rank-table">
                        <thead>
                            <tr>
                                <th>Payment</th>
                                <th>Bills</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($paymentRows)) { ?>
                                <tr>
                                    <td colspan="3">No payments recorded today.</td>
                                </tr>
                            <?php } else { ?>
                                <?php foreach ($paymentRows as $row) { ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['payment_method'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo (int)$row['bill_count']; ?></td>
                                        <td>&#8377; <?php echo number_format((float)$row['total_amount'], 2); ?></td>
                                    </tr>
                                <?php } ?>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>


// Include footer
<?php include __DIR__ . "/../includes/footer.php"; ?>

<script>
(function () {
    if (typeof Chart === 'undefined') return;

    var paymentLabels = <?php echo json_encode($paymentLabels, JSON_UNESCAPED_UNICODE); ?>;
    var paymentValues = <?php echo json_encode($paymentValues, JSON_NUMERIC_CHECK); ?>;
    var paymentCanvas = document.getElementById('employeePaymentChart');

    if (!paymentCanvas) return;

    new Chart(paymentCanvas, {
        type: 'doughnut',
        data: {
            labels: paymentLabels,
            datasets: [{
                data: paymentValues,
                backgroundColor: ['#1d4ed8', '#0ea5e9', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '62%',
            plugins: {
                legend: {
                    position: 'bottom'
                },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            var label = context.label || '';
                            return label + ': Rs ' + Number(context.parsed || 0).toLocaleString('en-IN', {
                                minimumFractionDigits: 2,
                                maximumFractionDigits: 2
                            });
                        }
                    }
                }
            }
        }
    });
})();
</script>





