<?php

session_start();
require_once __DIR__ . "/../config/database.php";

// Allow only admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== "admin") {
    header("Location: ../auth/login.php");
    exit();
}

$filterType = $_GET['range'] ?? 'month';
$allowedRanges = ['day', 'week', 'month'];
if (!in_array($filterType, $allowedRanges, true)) {
    $filterType = 'month';
}

$selectedDay = $_GET['day'] ?? date('Y-m-d');
$selectedWeek = $_GET['week'] ?? date('o-\WW');
$selectedMonth = $_GET['month'] ?? date('Y-m');

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

$backParams = "range=" . urlencode($filterType);
if ($filterType === 'day') {
    $backParams .= "&day=" . urlencode($selectedDay);
} elseif ($filterType === 'week') {
    $backParams .= "&week=" . urlencode($selectedWeek);
} else {
    $backParams .= "&month=" . urlencode($selectedMonth);
}
$backUrl = 'reports.php?' . $backParams;

// Helper functions for common database queries
function fetchTotal($conn, $query, $start, $end) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $start, $end);
    $stmt->execute();
    $val = (float) $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    return $val;
}

function fetchResults($conn, $query, $start, $end) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $start, $end);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    $stmt->close();
    return $data;
}

// Get total values
$salesTotal = fetchTotal($conn, "SELECT COALESCE(SUM(total_amount), 0) AS total FROM bill WHERE DATE(bill_date) BETWEEN ? AND ?", $startDate, $endDate);
$purchaseTotal = fetchTotal($conn, "SELECT COALESCE(SUM(total_amount), 0) AS total FROM purchase WHERE DATE(purchase_date) BETWEEN ? AND ?", $startDate, $endDate);
$profit = $salesTotal - $purchaseTotal;
$purchaseCount = (int) fetchTotal($conn, "SELECT COUNT(*) AS total FROM purchase WHERE DATE(purchase_date) BETWEEN ? AND ?", $startDate, $endDate);
$profitLabel = "No Profit No Loss";
$profitColor = "#6b7280";
if ($profit > 0) {
    $profitLabel = "Profit";
    $profitColor = "#16a34a";
} elseif ($profit < 0) {
    $profitLabel = "Loss";
    $profitColor = "#dc2626";
}

// Get detailed reports
$vendorData = fetchResults($conn, "
    SELECT v.name, COUNT(*) AS purchase_count, COALESCE(SUM(p.total_amount), 0) AS total_amount
    FROM purchase p JOIN vendor v ON v.vendor_id = p.vendor_id
    WHERE DATE(p.purchase_date) BETWEEN ? AND ?
    GROUP BY v.vendor_id, v.name ORDER BY total_amount DESC
", $startDate, $endDate);

$employeeData = fetchResults($conn, "
    SELECT e.emp_id, e.username, COUNT(*) AS bill_count, COALESCE(SUM(b.total_amount), 0) AS total_sales
    FROM bill b JOIN employee e ON e.emp_id = b.emp_id
    WHERE DATE(b.bill_date) BETWEEN ? AND ?
    GROUP BY e.emp_id, e.username ORDER BY total_sales DESC
", $startDate, $endDate);

$paymentData = fetchResults($conn, "
    SELECT COALESCE(payment_method, 'Unknown') AS payment_method, COUNT(*) AS bill_count, COALESCE(SUM(total_amount), 0) AS total_amount
    FROM bill WHERE DATE(bill_date) BETWEEN ? AND ?
    GROUP BY payment_method ORDER BY total_amount DESC
", $startDate, $endDate);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medivault Report - <?php echo htmlspecialchars($rangeLabel); ?></title>
    <link rel="icon" type="image/png" href="../assets/favicon-rounded.png?v=<?php echo filemtime(__DIR__ . '/../assets/favicon-rounded.png'); ?>">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5;
            padding: 40px 20px;
            color: #0f172a;
        }

        .report-container {
            max-width: 900px;
            margin: 0 auto;
            background: #ffffff;
            padding: 40px;
            border-radius: 12px;
            border: 1px solid #e5edf5;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .invoice-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }

        .invoice-head h2 {
            margin: 0 0 6px;
            color: #1e3a8a;
            font-size: 26px;
            letter-spacing: 0.02em;
        }

        .invoice-head p {
            margin: 0;
            color: #475569;
            font-size: 15px;
        }

        .invoice-head .header-right {
            text-align: right;
        }

        .invoice-head .header-right p {
            font-size: 14px;
            margin-bottom: 4px;
            color: #0f172a;
        }

        hr.divider {
            border: 0;
            border-top: 1px solid #e2e8f0;
            margin: 20px 0;
        }

        .invoice-subhead {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin: 20px 0;
        }

        .invoice-card {
            border: 1px solid #e5edf5;
            border-radius: 8px;
            background: #f8fbff;
            padding: 16px 20px;
        }

        .invoice-card h4 {
            margin: 0 0 8px;
            font-size: 12px;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 0.05em;
        }

        .invoice-card p {
            margin: 0;
            color: #0f172a;
            font-size: 15px;
            font-weight: 500;
        }

        .invoice-card.financial {
            background: #ffffff;
            border-color: #cbd5e1;
        }

        .invoice-card.financial h4 {
            color: #475569;
        }

        .invoice-card.financial p {
            font-size: 20px;
            font-weight: 700;
            color: #1e3a8a;
            margin-top: 8px;
        }

        .profit-value {
            font-size: 20px;
            font-weight: 700;
            margin-top: 8px;
        }

        .section-title {
            font-size: 14px;
            font-weight: 700;
            margin: 40px 0 16px 0;
            text-transform: uppercase;
            color: #0f172a;
            letter-spacing: 0.05em;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 8px;
        }

        .invoice-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-bottom: 10px;
            font-size: 14px;
            background: #fff;
            border: 1px solid #e5edf5;
            border-radius: 8px;
            overflow: hidden;
        }

        .invoice-table th {
            background: #f8fafc;
            color: #475569;
            padding: 12px 16px;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-size: 12px;
            font-weight: 600;
            border-bottom: 1px solid #e5edf5;
        }

        .invoice-table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid #eef3f8;
            color: #0f172a;
        }

        .invoice-table th.text-right,
        .invoice-table td.text-right {
            text-align: right;
        }

        .invoice-table th.text-center,
        .invoice-table td.text-center {
            text-align: center;
        }

        .invoice-table tbody tr:nth-child(even) {
            background: #f8fbff;
        }

        .invoice-table tbody tr:hover {
            background: #f3f7fc;
        }

        .invoice-table .total-row td {
            font-weight: 700;
            background: #f1f5f9;
            border-bottom: none;
            color: #0f172a;
            font-size: 15px;
        }

        .no-data {
            text-align: center;
            color: #64748b;
            padding: 30px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px dashed #cbd5e1;
            font-style: italic;
            margin-bottom: 20px;
        }

        .footer-text {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            text-align: center;
            font-size: 12px;
            color: #64748b;
            line-height: 1.6;
        }

        .bill-actions {
            display: flex;
            justify-content: center;
            gap: 14px;
            margin-top: 30px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            border: 1px solid transparent;
            cursor: pointer;
            transition: background 0.2s ease, border-color 0.2s ease, transform 0.1s ease;
        }

        .btn:active {
            transform: translateY(1px);
        }

        .btn-primary {
            background: #2563eb;
            color: #fff;
            box-shadow: 0 2px 6px rgba(37, 99, 235, 0.2);
        }

        .btn-primary:hover {
            background: #1d4ed8;
        }

        .btn-secondary {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #334155;
        }

        .btn-secondary:hover {
            background: #f1f5f9;
            border-color: #94a3b8;
            color: #0f172a;
        }

        @media print {
            @page {
                margin: 0;
            }

            body {
                background: white;
                padding: 10mm;
            }

            .report-container {
                box-shadow: none;
                border: none;
                padding: 0;
                max-width: 100%;
            }

            .bill-actions {
                display: none;
            }

            .invoice-head,
            .invoice-subhead,
            .invoice-card,
            .section-title,
            .invoice-table,
            .invoice-table tr,
            .no-data {
                page-break-inside: avoid;
                break-inside: avoid-page;
            }

            .section-title {
                margin-top: 28px;
                page-break-after: avoid;
                break-after: avoid-page;
            }

            .invoice-table {
                overflow: visible;
                margin-bottom: 18px;
            }

            .invoice-table thead {
                display: table-header-group;
            }

            .invoice-table th {
                background: #f1f5f9 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .invoice-table tbody tr:nth-child(even) {
                background: #f8fbff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .invoice-table .total-row td {
                background: #f1f5f9 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .invoice-card {
                background: #f8fbff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .invoice-card.financial {
                background: #ffffff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>

<body>
    <div class="report-container">
        <div class="invoice-head">
            <div>
                <h2>MEDIVAULT PHARMACY</h2>
                <p>Financial Report</p>
            </div>
            <div class="header-right">
                <p><strong>Date:</strong> <?php echo date('d-m-Y'); ?></p>
            </div>
        </div>

        <hr class="divider">

        <div class="invoice-subhead" style="grid-template-columns: 1fr;">
            <div class="invoice-card">
                <h4>Report Period</h4>
                <p><?php echo htmlspecialchars($rangeLabel); ?></p>
            </div>
        </div>

                <div class="invoice-subhead">
            <div class="invoice-card financial">
                <h4>Revenue</h4>
                <p>&#8377; <?php echo number_format($salesTotal, 2); ?></p>
            </div>
            <div class="invoice-card financial">
                <h4>Total Purchase</h4>
                <p>&#8377; <?php echo number_format($purchaseTotal, 2); ?></p>
            </div>
            <div class="invoice-card financial">
                <h4>Purchase Entries</h4>
                <p><?php echo $purchaseCount; ?></p>
            </div>
            <div class="invoice-card financial">
                <h4><?php echo $profitLabel; ?></h4>
                <p class="profit-value" style="color: <?php echo htmlspecialchars($profitColor, ENT_QUOTES, 'UTF-8'); ?>;">&#8377; <?php echo number_format(abs($profit), 2); ?></p>
            </div>
        </div>

        <h3 class="section-title">Vendor Purchase Details</h3>

        <?php if (empty($vendorData)) { ?>
            <div class="no-data">No purchase records found for this period.</div>
        <?php } else { ?>
            <table class="invoice-table">
                <thead>
                    <tr>
                        <th style="width: 8%;" class="text-center">No.</th>
                        <th style="width: 52%;">Vendor Name</th>
                        <th style="width: 15%;" class="text-center">Purchases</th>
                        <th style="width: 25%;" class="text-right">Total Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $counter = 1;
                    foreach ($vendorData as $vendor) { ?>
                        <tr>
                            <td class="text-center"><?php echo $counter; ?></td>
                            <td><?php echo htmlspecialchars($vendor['name']); ?></td>
                            <td class="text-center"><?php echo (int) $vendor['purchase_count']; ?></td>
                            <td class="text-right">₹ <?php echo number_format((float) $vendor['total_amount'], 2); ?></td>
                        </tr>
                        <?php $counter++; ?>
                    <?php } ?>
                    <tr class="total-row">
                        <td colspan="3" class="text-right">Grand Total</td>
                        <td class="text-right">₹ <?php echo number_format($purchaseTotal, 2); ?></td>
                    </tr>
                </tbody>
            </table>
        <?php } ?>

        <h3 class="section-title">Employee Transaction History</h3>

        <?php if (empty($employeeData)) { ?>
            <div class="no-data">No employee transactions found for this period.</div>
        <?php } else { ?>
            <table class="invoice-table">
                <thead>
                    <tr>
                        <th style="width: 8%;" class="text-center">No.</th>
                        <th style="width: 52%;">Employee Name</th>
                        <th style="width: 15%;" class="text-center">Bills</th>
                        <th style="width: 25%;" class="text-right">Total Sales</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $empCounter = 1;
                    foreach ($employeeData as $employee) { ?>
                        <tr>
                            <td class="text-center"><?php echo $empCounter; ?></td>
                            <td><?php echo htmlspecialchars($employee['username']); ?></td>
                            <td class="text-center"><?php echo (int) $employee['bill_count']; ?></td>
                            <td class="text-right">₹ <?php echo number_format((float) $employee['total_sales'], 2); ?></td>
                        </tr>
                        <?php $empCounter++; ?>
                    <?php } ?>
                    <tr class="total-row">
                        <td colspan="3" class="text-right">Total Employee Sales</td>
                        <td class="text-right">₹ <?php echo number_format($salesTotal, 2); ?></td>
                    </tr>
                </tbody>
            </table>
        <?php } ?>

        <h3 class="section-title">Payment Method Summary</h3>

        <?php if (empty($paymentData)) { ?>
            <div class="no-data">No payment records found for this period.</div>
        <?php } else { ?>
            <table class="invoice-table">
                <thead>
                    <tr>
                        <th style="width: 8%;" class="text-center">No.</th>
                        <th style="width: 52%;">Payment Method</th>
                        <th style="width: 15%;" class="text-center">Bills</th>
                        <th style="width: 25%;" class="text-right">Total Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $payCounter = 1;
                    foreach ($paymentData as $payment) { ?>
                        <tr>
                            <td class="text-center"><?php echo $payCounter; ?></td>
                            <td><?php echo htmlspecialchars($payment['payment_method']); ?></td>
                            <td class="text-center"><?php echo (int) $payment['bill_count']; ?></td>
                            <td class="text-right">₹ <?php echo number_format((float) $payment['total_amount'], 2); ?></td>
                        </tr>
                        <?php $payCounter++; ?>
                    <?php } ?>
                    <tr class="total-row">
                        <td colspan="3" class="text-right">Total Received</td>
                        <td class="text-right">₹ <?php echo number_format($salesTotal, 2); ?></td>
                    </tr>
                </tbody>
            </table>
        <?php } ?>

        <div class="bill-actions">
            <button type="button" class="btn btn-primary" onclick="window.print()">Print Report</button>
            <a href="<?php echo htmlspecialchars($backUrl); ?>" class="btn btn-secondary">Back</a>
        </div>
    </div>
</body>

</html>
