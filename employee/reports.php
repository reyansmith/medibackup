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
// Enforce tab-close and 10:30 PM session rules.
enforceEmployeeSessionRules($conn);
// Check session audit
ensureEmployeeSessionAudit($conn);

// Get employee id from session
$empId = (string)$_SESSION['id'];

// Get filter type (day, week, month)
$filterType = $_GET['range'] ?? 'month';
$allowedRanges = ['day', 'week', 'month'];
if (!in_array($filterType, $allowedRanges, true)) {
    $filterType = 'month';
}

// Get selected day
$selectedDay = $_GET['day'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDay)) {
    $selectedDay = date('Y-m-d');
}

// Get selected week
$selectedWeek = $_GET['week'] ?? date('o-\WW');
if (!preg_match('/^\d{4}-W\d{2}$/', $selectedWeek)) {
    $selectedWeek = date('o-\WW');
}

// Get selected month
$selectedMonth = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}


// Set date range and label for filter
if ($filterType === 'day') {
    $startDate = $selectedDay;
    $endDate = $selectedDay;
    $rangeLabel = date('d M Y', strtotime($selectedDay));
} elseif ($filterType === 'week') {
    [$weekYear, $weekNumber] = array_pad(explode('-W', $selectedWeek), 2, '');
    $weekYear = ctype_digit($weekYear) ? (int)$weekYear : (int)date('o');
    $weekNumber = ctype_digit($weekNumber) ? (int)$weekNumber : (int)date('W');

    $weekStart = (new DateTimeImmutable())->setISODate($weekYear, $weekNumber, 1);
    $weekEnd = $weekStart->modify('+6 days');
    $startDate = $weekStart->format('Y-m-d');
    $endDate = $weekEnd->format('Y-m-d');
    $selectedWeek = $weekStart->format('o-\WW');
    $rangeLabel = $weekStart->format('d M') . ' - ' . $weekEnd->format('d M Y');
} else {
    $startDate = $selectedMonth . '-01';
    $endDate = date('Y-m-t', strtotime($startDate));
    $rangeLabel = date('F Y', strtotime($startDate));
}


// Initialize report variables
$salesTotal = 0.0;
$billCount = 0;
$avgBill = 0.0;
$paymentRows = [];

// Initialize data arrays
$dateSalesRows = [];
$monthlyBillingRows = [];
$recentBillRows = [];


// Get sales summary for the period
$summaryStmt = $conn->prepare("
    SELECT COALESCE(SUM(total_amount), 0) AS sales_total,
           COUNT(*) AS bill_count,
           COALESCE(AVG(total_amount), 0) AS avg_bill
    FROM bill
    WHERE emp_id = ?
      AND DATE(bill_date) BETWEEN ? AND ?
");
$summaryStmt->bind_param("sss", $empId, $startDate, $endDate);
$summaryStmt->execute();
$summaryRow = $summaryStmt->get_result()->fetch_assoc();
if ($summaryRow) {
    $salesTotal = (float)$summaryRow['sales_total'];
    $billCount = (int)$summaryRow['bill_count'];
    $avgBill = (float)$summaryRow['avg_bill'];
}
$summaryStmt->close();


// Get date-wise sales for the period
$dateSalesStmt = $conn->prepare("
        SELECT DATE(bill_date) AS sale_date,
                     COUNT(*) AS bill_count,
                     COALESCE(SUM(total_amount), 0) AS sales_total
        FROM bill
        WHERE emp_id = ?
            AND DATE(bill_date) BETWEEN ? AND ?
        GROUP BY DATE(bill_date)
        ORDER BY DATE(bill_date) DESC
");
$dateSalesStmt->bind_param("sss", $empId, $startDate, $endDate);
$dateSalesStmt->execute();
$dateSalesRes = $dateSalesStmt->get_result();
while ($row = $dateSalesRes->fetch_assoc()) {
        $dateSalesRows[] = $row;
}
$dateSalesStmt->close();


// Get payment method split for the period
$paymentStmt = $conn->prepare("
        SELECT COALESCE(payment_method, 'Unknown') AS payment_method,
                     COUNT(*) AS bill_count,
                     COALESCE(SUM(total_amount), 0) AS total_amount
        FROM bill
        WHERE emp_id = ?
            AND DATE(bill_date) BETWEEN ? AND ?
        GROUP BY payment_method
        ORDER BY total_amount DESC
");
$paymentStmt->bind_param("sss", $empId, $startDate, $endDate);
$paymentStmt->execute();
$paymentRes = $paymentStmt->get_result();
while ($row = $paymentRes->fetch_assoc()) {
        $paymentRows[] = $row;
}
$paymentStmt->close();


// Get monthly billing report (last 6 months)
$monthlyBillingStmt = $conn->prepare("
    SELECT DATE_FORMAT(bill_date, '%Y-%m') AS month_key,
           DATE_FORMAT(bill_date, '%b %Y') AS month_label,
           COUNT(*) AS bill_count,
           COALESCE(SUM(total_amount), 0) AS sales_total
    FROM bill
    WHERE emp_id = ?
    GROUP BY DATE_FORMAT(bill_date, '%Y-%m'), DATE_FORMAT(bill_date, '%b %Y')
    ORDER BY month_key DESC
    LIMIT 6
");
$monthlyBillingStmt->bind_param("s", $empId);
$monthlyBillingStmt->execute();
$monthlyBillingRes = $monthlyBillingStmt->get_result();
while ($row = $monthlyBillingRes->fetch_assoc()) {
    $monthlyBillingRows[] = $row;
}
$monthlyBillingStmt->close();


// Get recent bills for the period (max 15)
$recentBillStmt = $conn->prepare("
        SELECT bill_id, bill_date, customer_name, payment_method, total_amount
        FROM bill
        WHERE emp_id = ?
            AND DATE(bill_date) BETWEEN ? AND ?
        ORDER BY bill_date DESC
        LIMIT 15
");
$recentBillStmt->bind_param("sss", $empId, $startDate, $endDate);
$recentBillStmt->execute();
$recentBillRes = $recentBillStmt->get_result();
while ($row = $recentBillRes->fetch_assoc()) {
        $recentBillRows[] = $row;
}
$recentBillStmt->close();


// Prepare data for payment and sales charts
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

$dateSalesLabels = [];
$dateSalesValues = [];
foreach (array_reverse($dateSalesRows) as $row) {
    $dateSalesLabels[] = date('d M', strtotime((string)$row['sale_date']));
    $dateSalesValues[] = (float)$row['sales_total'];
}
if (empty($dateSalesLabels)) {
    $dateSalesLabels = ['No Data'];
    $dateSalesValues = [0];
}


// Include header and sidebar
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar.php";
?>

<div class="main reports-page employee-reports-page">
    <div class="topbar">
        <div class="topbar-text">
            <h2>Employee Reports</h2>
            <p><?php echo $rangeLabel; ?></p>
        </div>
        <div class="top-actions">
            <form method="GET" class="reports-filter-form">
                <label for="range">Filter</label>
                <select id="range" name="range">
                    <option value="day" <?php echo $filterType === 'day' ? 'selected' : ''; ?>>Day</option>
                    <option value="week" <?php echo $filterType === 'week' ? 'selected' : ''; ?>>Week</option>
                    <option value="month" <?php echo $filterType === 'month' ? 'selected' : ''; ?>>Month</option>
                </select>

                <?php if ($filterType === 'day') { ?>
                    <input type="date" name="day" value="<?php echo $selectedDay; ?>">
                <?php } elseif ($filterType === 'week') {
    [$weekYear, $weekNumber] = array_pad(explode('-W', $selectedWeek), 2, '');
    $weekYear = ctype_digit($weekYear) ? (int)$weekYear : (int)date('o');
    $weekNumber = ctype_digit($weekNumber) ? (int)$weekNumber : (int)date('W');

    $weekStart = (new DateTimeImmutable())->setISODate($weekYear, $weekNumber, 1);
    $weekEnd = $weekStart->modify('+6 days');
    $startDate = $weekStart->format('Y-m-d');
    $endDate = $weekEnd->format('Y-m-d');
    $selectedWeek = $weekStart->format('o-\WW');
    $rangeLabel = $weekStart->format('d M') . ' - ' . $weekEnd->format('d M Y');
} else { ?>
                    <input type="month" name="month" value="<?php echo $selectedMonth; ?>">
                <?php } ?>

                <button type="submit" class="btn btn-primary btn-sm">Apply</button>
            </form>
            <a href="../auth/logout.php" class="logout-btn">Logout</a>
        </div>
    </div>

    <div class="cards reports-cards">
        <div class="card">
            <h4>Date-wise Sales</h4>
            <h2>&#8377; <?php echo number_format($salesTotal, 2); ?></h2>
        </div>

        <div class="card">
            <h4>Total Bills</h4>
            <h2><?php echo $billCount; ?></h2>
        </div>

        <div class="card">
            <h4>Average Bill</h4>
            <h2>&#8377; <?php echo number_format($avgBill, 2); ?></h2>
        </div>
    </div>

    <div class="reports-grid">
        <div class="box">
            <h3>Date-wise Sales Report</h3>
            <div class="chart-compact reports-chart">
                <canvas id="employeeDateSalesChart"></canvas>
            </div>
            <div class="table-wrap">
                <table class="leaderboard-table reports-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Bills</th>
                            <th>Sales</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($dateSalesRows)) { ?>
                            <tr>
                                <td colspan="3">No sales found for this filter.</td>
                            </tr>
                        <?php } else { ?>
                            <?php foreach ($dateSalesRows as $row) { ?>
                                <tr>
                                    <td><?php echo date('d M Y', strtotime((string)$row['sale_date'])); ?></td>
                                    <td><?php echo (int)$row['bill_count']; ?></td>
                                    <td>&#8377; <?php echo number_format((float)$row['sales_total'], 2); ?></td>
                                </tr>
                            <?php } ?>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="box">
            <h3>Payment Method Report</h3>
            <div class="chart-compact reports-chart">
                <canvas id="employeePaymentChart"></canvas>
            </div>
            <div class="table-wrap">
                <table class="leaderboard-table reports-table">
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
                                <td colspan="3">No payment data found.</td>
                            </tr>
                        <?php } else { ?>
                            <?php foreach ($paymentRows as $row) { ?>
                                <tr>
                                    <td><?php echo $row['payment_method']; ?></td>
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

    <div class="reports-grid">
        <div class="box">
            <h3>Monthly Billing Report</h3>
            <div class="table-wrap">
                <table class="leaderboard-table reports-table">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Bills</th>
                            <th>Sales</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($monthlyBillingRows)) { ?>
                            <tr>
                                <td colspan="3">No monthly billing data found.</td>
                            </tr>
                        <?php } else { ?>
                            <?php foreach ($monthlyBillingRows as $row) { ?>
                                <tr>
                                    <td><?php echo $row['month_label']; ?></td>
                                    <td><?php echo (int)$row['bill_count']; ?></td>
                                    <td>&#8377; <?php echo number_format((float)$row['sales_total'], 2); ?></td>
                                </tr>
                            <?php } ?>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="box">
            <h3>My Transaction History</h3>
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
                        <?php if (empty($recentBillRows)) { ?>
                            <tr>
                                <td colspan="5">No transactions found for this filter.</td>
                            </tr>
                        <?php } else { ?>
                            <?php foreach ($recentBillRows as $row) { ?>
                                <tr>
                                    <td><?php echo (string)$row['bill_id']; ?></td>
                                    <td><?php echo date("d M Y, h:i A", strtotime($row['bill_date'])); ?></td>
                                    <td><?php echo $row['customer_name'] ?: '-'; ?></td>
                                    <td><?php echo $row['payment_method'] ?: '-'; ?></td>
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



<?php include __DIR__ . "/../includes/footer.php"; ?>

<script>
(function () {
    if (typeof Chart === 'undefined') return;

    var dateLabels = <?php echo $dateSalesLabels ? json_encode($dateSalesLabels) : '[]'; ?>;
    var dateValues = <?php echo $dateSalesValues ? json_encode($dateSalesValues) : '[]'; ?>;
    var paymentLabels = <?php echo $paymentLabels ? json_encode($paymentLabels) : '[]'; ?>;
    var paymentValues = <?php echo $paymentValues ? json_encode($paymentValues) : '[]'; ?>;

    var moneyFormat = function (value) {
        return 'Rs ' + Number(value || 0).toLocaleString('en-IN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    };

    var dateCanvas = document.getElementById('employeeDateSalesChart');
    if (dateCanvas) {
        new Chart(dateCanvas, {
            type: 'line',
            data: {
                labels: dateLabels,
                datasets: [{
                    label: 'Sales',
                    data: dateValues,
                    borderColor: '#1d4ed8',
                    backgroundColor: 'rgba(29, 78, 216, 0.12)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 2,
                    pointHoverRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return moneyFormat(context.parsed.y);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function (value) {
                                return Number(value).toLocaleString('en-IN');
                            }
                        }
                    }
                }
            }
        });
    }

    var paymentCanvas = document.getElementById('employeePaymentChart');
    if (paymentCanvas) {
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
                                return label + ': ' + moneyFormat(context.parsed);
                            }
                        }
                    }
                }
            }
        });
    }
})();
</script>







