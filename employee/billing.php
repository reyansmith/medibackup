<?php

session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== "employee") {
    header("Location: ../auth/login.php");
    exit();
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/session_audit.php";

ensureEmployeeSessionAudit($conn);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$bill_generated = false;
$error = "";
$items = null;
$total_amount = 0.0;
$bill_date = date("d-m-Y");
$customer_name = "";
$customer_contact = "";
$emp_id = (string)$_SESSION['id'];
$payment_method = "Cash";
$cartRows = [
    ['medicine_id' => '', 'quantity' => '1']
];

function generateNextBillId(mysqli $conn): string
{
    $result = $conn->query("SELECT MAX(CAST(SUBSTRING(bill_id,5) AS UNSIGNED)) AS max_id FROM bill");
    $row = $result->fetch_assoc();
    $number = ($row && $row['max_id'] !== null) ? ((int)$row['max_id'] + 1) : 1;
    return "BILL" . str_pad((string)$number, 3, "0", STR_PAD_LEFT);
}

function getBillableMedicines(mysqli $conn): array
{
    $sql = "
        SELECT
            p.medicine_id,
            p.medicine_name,
            COALESCE(SUM(
                CASE
                    WHEN s.quantity > 0
                     AND s.expiry_date IS NOT NULL
                     AND s.expiry_date != '0000-00-00'
                     AND s.expiry_date >= CURDATE()
                    THEN s.quantity
                    ELSE 0
                END
            ), 0) AS available_quantity,
            (
                SELECT s2.selling_price
                FROM stock s2
                WHERE s2.medicine_id = p.medicine_id
                  AND s2.quantity > 0
                  AND s2.expiry_date IS NOT NULL
                  AND s2.expiry_date != '0000-00-00'
                  AND s2.expiry_date >= CURDATE()
                ORDER BY s2.expiry_date ASC, s2.stock_id ASC
                LIMIT 1
            ) AS selling_price
        FROM product p
        LEFT JOIN stock s ON s.medicine_id = p.medicine_id
        GROUP BY p.medicine_id, p.medicine_name
        HAVING available_quantity > 0
        ORDER BY p.medicine_name ASC
    ";

    $result = $conn->query($sql);
    $medicines = [];
    while ($row = $result->fetch_assoc()) {
        $medicines[] = [
            'id' => (string)$row['medicine_id'],
            'name' => (string)$row['medicine_name'],
            'available_quantity' => (int)$row['available_quantity'],
            'selling_price' => (float)($row['selling_price'] ?? 0),
        ];
    }

    return $medicines;
}

function buildMedicineLookup(array $medicines): array
{
    $lookup = [];
    foreach ($medicines as $medicine) {
        $lookup[$medicine['id']] = $medicine;
    }
    return $lookup;
}

function normalizeCartRows(array $medicineIds, array $quantities): array
{
    $rows = [];
    $count = max(count($medicineIds), count($quantities));

    for ($i = 0; $i < $count; $i++) {
        $medicineId = trim((string)($medicineIds[$i] ?? ''));
        $quantity = trim((string)($quantities[$i] ?? ''));

        if ($medicineId === '' && $quantity === '') {
            continue;
        }

        $rows[] = [
            'medicine_id' => $medicineId,
            'quantity' => $quantity === '' ? '' : $quantity,
        ];
    }

    return $rows;
}

$bill_id = generateNextBillId($conn);
$employeeName = (string)($_SESSION['username'] ?? 'Employee');
$medicines = getBillableMedicines($conn);
$medicineLookup = buildMedicineLookup($medicines);
$medicineMeta = [];
foreach ($medicines as $medicine) {
    $medicineMeta[$medicine['id']] = [
        'name' => $medicine['name'],
        'price' => $medicine['selling_price'],
        'available' => $medicine['available_quantity'],
    ];
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $customer_name = trim((string)($_POST['customer_name'] ?? ''));
    $customer_contact = trim((string)($_POST['customer_contact'] ?? ''));
    $payment_method = trim((string)($_POST['payment_method'] ?? ''));
    $postedMedicineIds = isset($_POST['medicine_id']) && is_array($_POST['medicine_id']) ? $_POST['medicine_id'] : [];
    $postedQuantities = isset($_POST['quantity']) && is_array($_POST['quantity']) ? $_POST['quantity'] : [];
    $submittedRows = normalizeCartRows($postedMedicineIds, $postedQuantities);

    if (!empty($submittedRows)) {
        $cartRows = $submittedRows;
    }

    $allowedPayments = ["Cash", "UPI", "Card"];

    if ($customer_name === '' || strlen($customer_name) > 100) {
        $error = "Enter a valid customer name";
    } elseif (!preg_match('/^\d{10}$/', $customer_contact)) {
        $error = "Enter a valid 10-digit customer contact number.";
    } elseif (!in_array($payment_method, $allowedPayments, true)) {
        $error = "Invalid payment method selected.";
    } elseif (empty($submittedRows)) {
        $error = "Add at least one medicine to the cart.";
    } else {
        $validatedRows = [];
        $selectedMedicines = [];

        foreach ($submittedRows as $index => $row) {
            $rowNo = $index + 1;
            $medicineId = $row['medicine_id'];
            $quantityText = $row['quantity'];

            if ($medicineId === '') {
                $error = "Select a medicine for billing.";
                break;
            }

            if (!isset($medicineLookup[$medicineId])) {
                $error = "Selected medicine in item {$rowNo} is unavailable.";
                break;
            }

            if (!ctype_digit($quantityText) || (int)$quantityText <= 0 || (int)$quantityText > 10000) {
                $error = "quantity must be greater than 0";
                break;
            }

            if (isset($selectedMedicines[$medicineId])) {
                $error = "Add each medicine only once in the cart.";
                break;
            }

            $selectedMedicines[$medicineId] = true;
            $validatedRows[] = [
                'medicine_id' => $medicineId,
                'quantity' => (int)$quantityText,
                'medicine_name' => $medicineLookup[$medicineId]['name'],
            ];
        }

        if ($error === "") {
            try {
                $conn->begin_transaction();

                $empStmt = $conn->prepare("SELECT 1 FROM employee WHERE emp_id = ? AND status = 'active' LIMIT 1");
                $empStmt->bind_param("s", $emp_id);
                $empStmt->execute();
                $empExists = $empStmt->get_result()->fetch_row();
                $empStmt->close();

                if (!$empExists) {
                    throw new RuntimeException("Access Denied");
                }

                $resolvedItems = [];
                foreach ($validatedRows as $row) {
                    $stockStmt = $conn->prepare("
                        SELECT stock_id, quantity, selling_price
                        FROM stock
                        WHERE medicine_id = ?
                          AND quantity > 0
                          AND expiry_date IS NOT NULL
                          AND expiry_date != '0000-00-00'
                          AND expiry_date >= CURDATE()
                        ORDER BY expiry_date ASC, stock_id ASC
                        LIMIT 1
                        FOR UPDATE
                    ");
                    $stockStmt->bind_param("s", $row['medicine_id']);
                    $stockStmt->execute();
                    $stockResult = $stockStmt->get_result();
                    $stockStmt->close();

                    if ($stockResult->num_rows === 0) {
                        throw new RuntimeException("No valid stock available for " . $row['medicine_name'] . ".");
                    }

                    $stock = $stockResult->fetch_assoc();
                    $availableQuantity = (int)$stock['quantity'];
                    if ($row['quantity'] > $availableQuantity) {
                        throw new RuntimeException(
                            $row['medicine_name'] . " has only " . $availableQuantity . " item(s) available in the next saleable batch."
                        );
                    }

                    $sellingPrice = (float)$stock['selling_price'];
                    $lineTotal = $row['quantity'] * $sellingPrice;
                    $total_amount += $lineTotal;

                    $resolvedItems[] = [
                        'medicine_id' => $row['medicine_id'],
                        'medicine_name' => $row['medicine_name'],
                        'quantity' => $row['quantity'],
                        'selling_price' => $sellingPrice,
                        'line_total' => $lineTotal,
                        'stock_id' => (string)$stock['stock_id'],
                    ];
                }

                $bill_id = generateNextBillId($conn);

                $billStmt = $conn->prepare("
                    INSERT INTO bill (bill_id, emp_id, bill_date, total_amount, payment_method, customer_name, customer_contact)
                    VALUES (?, ?, NOW(), ?, ?, ?, ?)
                ");
                $billStmt->bind_param("ssdsss", $bill_id, $emp_id, $total_amount, $payment_method, $customer_name, $customer_contact);
                $billStmt->execute();
                $billStmt->close();

                $detailStmt = $conn->prepare("
                    INSERT INTO bill_details (bill_detail_id, bill_id, medicine_id, quantity, selling_price)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $updateStmt = $conn->prepare("UPDATE stock SET quantity = quantity - ? WHERE stock_id = ?");

                foreach ($resolvedItems as $index => $item) {
                    $billDetailId = "BD" . date("YmdHis") . str_pad((string)$index, 2, "0", STR_PAD_LEFT) . random_int(100, 999);
                    $detailStmt->bind_param(
                        "sssid",
                        $billDetailId,
                        $bill_id,
                        $item['medicine_id'],
                        $item['quantity'],
                        $item['selling_price']
                    );
                    $detailStmt->execute();

                    $updateStmt->bind_param("is", $item['quantity'], $item['stock_id']);
                    $updateStmt->execute();
                }

                $detailStmt->close();
                $updateStmt->close();

                $conn->commit();

                $bill_generated = true;
                $bill_date = date("d-m-Y");
                $cartRows = [
                    ['medicine_id' => '', 'quantity' => '1']
                ];

                $itemsStmt = $conn->prepare("
                    SELECT p.medicine_name, bd.quantity, bd.selling_price
                    FROM bill_details bd
                    JOIN product p ON bd.medicine_id = p.medicine_id
                    WHERE bd.bill_id = ?
                ");
                $itemsStmt->bind_param("s", $bill_id);
                $itemsStmt->execute();
                $items = $itemsStmt->get_result();
                $itemsStmt->close();
            } catch (Throwable $e) {
                $conn->rollback();
                $error = $e->getMessage();
                $bill_generated = false;
                $total_amount = 0.0;
                $bill_id = generateNextBillId($conn);
            }
        }
    }
}

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar.php";
?>
<style>
.billing-main .topbar{
    background: #f8fbff;
    border: 1px solid #e5edf5;
    border-radius: 10px;
    padding: 14px 16px;
    margin-bottom: 20px;
}

.billing-main .topbar-text h2{
    margin: 0;
    font-size: 28px;
    letter-spacing: 0.01em;
    color:#1e3a8a;
}

.bill-container.billing-page{
    width:min(1040px, 100%);
    margin: 0 auto 24px;
    background: #ffffff;
    padding: 26px;
    border-radius: 12px;
    border: 1px solid #e5edf5;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}

.billing-page .bill-title{
    margin:0 0 16px;
    font-size: 24px;
    letter-spacing: 0.02em;
    color:#0f172a;
}

.billing-page .bill-form-grid{
    display:grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.billing-page .field{
    display:flex;
    flex-direction:column;
    gap:8px;
}

.billing-page label{
    font-size:13px;
    font-weight:600;
    color:#0f172a;
    letter-spacing:0.01em;
}

.billing-page input,
.billing-page select{
    width:100%;
    min-height:42px;
    padding:10px 12px;
    border:1px solid #cbd5e1;
    border-radius:10px;
    font-size:14px;
    background: #fff;
    color:#0f172a;
}

.billing-page input:focus,
.billing-page select:focus{
    outline:none;
    border-color:#3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,.15);
}

.billing-page .bill-submit{
    margin-top: 20px;
    width:100%;
    padding:12px 16px;
    border: none;
    border-radius: 8px;
    background: #2563eb;
    color:#fff;
    font-weight:600;
    font-size:14px;
    cursor:pointer;
    box-shadow: 0 2px 8px rgba(37, 99, 235, 0.15);
    transition: background 0.2s ease, box-shadow 0.2s ease;
}

.billing-page .bill-submit:hover{
    background: #1d4ed8;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
}

.billing-page .error{
    color:#b91c1c;
    background:#fee2e2;
    border:1px solid #fecaca;
    border-radius:8px;
    padding:10px 12px;
    margin-bottom:14px;
    font-size:14px;
    font-weight:600;
}

.billing-page .cart-panel{
    border: 1px solid #e5edf5;
    border-radius: 10px;
    background: #ffffff;
    padding: 20px;
    margin-top: 24px;
}

.billing-page .cart-panel-head{
    display:flex;
    justify-content:space-between;
    gap:12px;
    align-items:flex-start;
    margin-bottom: 16px;
}

.billing-page .cart-panel-head h4{
    margin:0 0 4px;
    color:#334155;
    font-size:14px;
    text-transform:uppercase;
    letter-spacing:0.04em;
}

.billing-page .cart-panel-head p{
    margin:0;
    color:#64748b;
    font-size:13px;
}

.billing-page .bill-cart-table{
    width:100%;
    border-collapse:separate;
    border-spacing:0;
    background:#fff;
    border:1px solid #e5edf5;
    border-radius:8px;
    overflow:hidden;
}

.billing-page .bill-cart-table th{
    background:#f8fafc;
    color:#475569;
    padding:12px 14px;
    text-align:left;
    font-size:12px;
    text-transform:uppercase;
    letter-spacing:0.04em;
    border-bottom:1px solid #e5edf5;
    font-weight:600;
}

.billing-page .bill-cart-table td{
    padding:12px 14px;
    border-bottom:1px solid #eef3f8;
    vertical-align:middle;
    font-size:14px;
    color:#0f172a;
}

.billing-page .bill-cart-table tbody tr:nth-child(even){
    background:#f8fbff;
}

.billing-page .bill-cart-table tbody tr:hover{
    background:#f3f7fc;
}

.billing-page .cart-meta{
    min-width: 110px;
    font-size:13px;
    color:#475569;
}

.billing-page .cart-meta strong{
    display:block;
    color:#0f172a;
    margin-bottom:2px;
    font-size:14px;
}

.billing-page .cart-remove-row{
    white-space:nowrap;
}

.billing-page .bill-cart-actions{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    margin-top:14px;
    flex-wrap:wrap;
}

.billing-page .bill-cart-summary{
    margin-left:auto;
    padding:10px 14px;
    border-radius:8px;
    background:#f3f7fc;
    color:#1e3a8a;
    font-weight:600;
    font-size:13px;
    border:1px solid #cbd5e1;
}

.billing-page .invoice-head,
.billing-page .invoice-subhead,
.billing-page .bill-actions{
    display:flex;
    gap:14px;
}

.billing-page .invoice-head{
    justify-content:space-between;
    align-items:flex-start;
    margin-bottom: 8px;
}

.billing-page .invoice-head h2{
    margin:0 0 4px;
    color:#1e3a8a;
    font-size:24px;
}

.billing-page .invoice-head p{
    margin:0 0 6px;
    color:#475569;
    font-size:14px;
}

.billing-page .invoice-subhead{
    display:grid;
    grid-template-columns: 1fr 1fr;
    margin: 14px 0 10px;
}

.billing-page .invoice-card{
    border: 1px solid #e5edf5;
    border-radius: 8px;
    background: #ffffff;
    padding: 14px 16px;
}

.billing-page .invoice-card p{
    margin: 0 0 8px;
    color:#334155;
}

.billing-page .invoice-card p:last-child{
    margin-bottom:0;
}

.billing-page .invoice-table{
    width:100%;
    border-collapse:separate;
    border-spacing:0;
    margin-top:20px;
    font-size:14px;
    background:#fff;
    border:1px solid #e5edf5;
    border-radius:8px;
    overflow:hidden;
}

.billing-page .invoice-table th{
    background:#f8fafc;
    color:#475569;
    padding:12px 14px;
    text-align:center;
    text-transform:uppercase;
    letter-spacing:0.04em;
    font-size:12px;
    font-weight:600;
    border-bottom:1px solid #e5edf5;
}

.billing-page .invoice-table td{
    padding:12px 14px;
    text-align:center;
    border-bottom:1px solid #eef3f8;
    color:#0f172a;
}

.billing-page .invoice-table tbody tr:nth-child(even){
    background:#f8fbff;
}

.billing-page .invoice-table tfoot td{
    font-weight:600;
    background:#f3f7fc;
    border-bottom: none;
}

.billing-page .btn{
    padding:8px 12px;
    border-radius:8px;
    font-weight:600;
    font-size:13px;
    border:1px solid transparent;
    transition: background 0.2s ease, border-color 0.2s ease;
}

.billing-page .btn-primary{
    background:#2563eb;
    color:#fff;
}

.billing-page .btn-primary:hover{
    background:#1d4ed8;
}

.billing-page .btn-secondary{
    background:#f3f7fc;
    border-color:#cbd5e1;
    color:#334155;
}

.billing-page .btn-secondary:hover{
    background:#eef3f8;
    border-color:#cbd5e1;
}

.billing-page .cart-remove-row:disabled{
    opacity:0.55;
    cursor:not-allowed;
    transform:none;
}

.billing-page .pay-badge{
    display:inline-flex;
    align-items:center;
    padding:4px 10px;
    border-radius:999px;
    background:#eff6ff;
    border:1px solid #bfdbfe;
    color:#1d4ed8;
    font-size:12px;
    font-weight:700;
}

.billing-page hr{
    border:0;
    border-top:1px solid #e2e8f0;
    margin:14px 0;
}

@media (max-width: 900px){
    .billing-page .bill-form-grid{
        grid-template-columns: 1fr;
    }

    .billing-page .cart-panel-head,
    .billing-page .bill-cart-actions{
        flex-direction:column;
        align-items:stretch;
    }

    .billing-page .bill-cart-summary{
        margin-left:0;
    }
}

@media (max-width: 768px){
    .bill-container.billing-page{
        padding:18px;
    }

    .billing-page .invoice-subhead{
        grid-template-columns: 1fr;
    }

    .billing-page .bill-cart-table,
    .billing-page .bill-cart-table thead,
    .billing-page .bill-cart-table tbody,
    .billing-page .bill-cart-table tr,
    .billing-page .bill-cart-table td{
        display:block;
        width:100%;
    }

    .billing-page .bill-cart-table thead{
        display:none;
    }

    .billing-page .bill-cart-table tr{
        border:1px solid #dbe7f5;
        border-radius:12px;
        margin-bottom:12px;
        overflow:hidden;
        background:#fff;
    }

    .billing-page .bill-cart-table td{
        border-bottom:1px solid #edf2f7;
    }

    .billing-page .bill-cart-table td:last-child{
        border-bottom:0;
    }
}

@media print{
    .sidebar,
    .topbar,
    .bill-actions {
        display: none !important;
    }

    .main {
        padding: 0 !important;
    }

    .bill-container.billing-page{
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        border: 0 !important;
        box-shadow: none !important;
        background: #fff !important;
    }
}
</style>

<div class="main billing-main">
    <div class="topbar">
        <div class="topbar-text">
            <h2>Billing</h2>
        </div>
        <div class="top-actions">
            <a href="../auth/logout.php" class="logout-btn">Logout</a>
        </div>
    </div>

    <div class="bill-container billing-page">

        <?php if (!$bill_generated) { ?>

            <h3 class="bill-title">Create Invoice</h3>

            <?php if ($error !== "") { ?>
                <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php } ?>

            <form method="POST" novalidate>
                <div class="bill-form-grid">
                    <div class="field">
                        <label for="customer_name">Customer Name</label>
                        <input type="text" id="customer_name" name="customer_name" maxlength="100" value="<?php echo htmlspecialchars($customer_name, ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>

                    <div class="field">
                        <label for="customer_contact">Customer Contact</label>
                        <input type="text" id="customer_contact" name="customer_contact" maxlength="10" pattern="\d{10}" title="Enter exactly 10 digits" value="<?php echo htmlspecialchars($customer_contact, ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>

                    <div class="field">
                        <label for="employee_name">Employee</label>
                        <input type="text" id="employee_name" value="<?php echo htmlspecialchars($employeeName . ' | ' . $emp_id, ENT_QUOTES, 'UTF-8'); ?>" readonly>
                    </div>

                    <div class="field">
                        <label for="payment_method">Payment Method</label>
                        <select id="payment_method" name="payment_method">
                            <option value="Cash" <?php echo $payment_method === 'Cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="UPI" <?php echo $payment_method === 'UPI' ? 'selected' : ''; ?>>UPI</option>
                            <option value="Card" <?php echo $payment_method === 'Card' ? 'selected' : ''; ?>>Card</option>
                        </select>
                    </div>
                </div>

                <div class="cart-panel">
                    <div class="cart-panel-head">
                        <div>
                            <h4>Medicine Cart</h4>
                        </div>
                    </div>

                    <div class="purchase-table-wrap">
                        <table class="bill-cart-table">
                            <thead>
                                <tr>
                                    <th>Medicine</th>
                                    <th>Available</th>
                                    <th>Unit Price</th>
                                    <th>Quantity</th>
                                    <th>Estimated</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="cart_rows">
                                <?php foreach ($cartRows as $row) { ?>
                                    <tr class="cart-row">
                                        <td>
                                            <select name="medicine_id[]" class="cart-medicine-select" required>
                                                <option value="">Select medicine</option>
                                                <?php foreach ($medicines as $medicine) { ?>
                                                    <option
                                                        value="<?php echo htmlspecialchars($medicine['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        <?php echo $row['medicine_id'] === $medicine['id'] ? 'selected' : ''; ?>
                                                    >
                                                        <?php echo htmlspecialchars($medicine['name'] . ' | ' . $medicine['id'] . ' | Stock: ' . $medicine['available_quantity'], ENT_QUOTES, 'UTF-8'); ?>
                                                    </option>
                                                <?php } ?>
                                            </select>
                                        </td>
                                        <td class="cart-meta">
                                            <strong class="cart-stock-text">-</strong>
                                            <span>saleable units</span>
                                        </td>
                                        <td class="cart-meta">
                                            <strong class="cart-price-text">Rs 0.00</strong>
                                            <span>per unit</span>
                                        </td>
                                        <td>
                                            <input type="number" name="quantity[]" min="1" max="10000" value="<?php echo htmlspecialchars((string)$row['quantity'], ENT_QUOTES, 'UTF-8'); ?>" class="cart-qty-input" required>
                                        </td>
                                        <td class="cart-meta">
                                            <strong class="cart-line-total">Rs 0.00</strong>
                                            <span>line total</span>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-secondary cart-remove-row">Remove</button>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="bill-cart-actions">
                        <button type="button" class="btn btn-primary" id="add_cart_row">Add Medicine</button>
                        <div class="bill-cart-summary">Estimated Total: <span id="cart_estimate">Rs 0.00</span></div>
                    </div>
                </div>

                <button type="submit" class="bill-submit">Generate Bill</button>
            </form>

        <?php } ?>

        <?php if ($bill_generated) { ?>

            <div class="invoice-head">
                <div>
                    <h2>MANNATH MEDICALS PHARMACY</h2>
                    <p>Billing Invoice</p>
                </div>
                <div>
                    <p><strong>Bill No:</strong> <?php echo htmlspecialchars($bill_id, ENT_QUOTES, 'UTF-8'); ?></p>
                    <p><strong>Date:</strong> <?php echo htmlspecialchars($bill_date, ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
            </div>
            <hr>

            <div class="invoice-subhead">
                <div class="invoice-card">
                    <p><b>Employee:</b> <?php echo htmlspecialchars($employeeName . ' (' . $emp_id . ')', ENT_QUOTES, 'UTF-8'); ?></p>
                    <p><b>Payment:</b> <span class="pay-badge"><?php echo htmlspecialchars($payment_method, ENT_QUOTES, 'UTF-8'); ?></span></p>
                </div>
                <div class="invoice-card">
                    <p><b>Customer:</b> <?php echo htmlspecialchars($customer_name, ENT_QUOTES, 'UTF-8'); ?></p>
                    <p><b>Contact:</b> <?php echo htmlspecialchars($customer_contact, ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
            </div>
            <hr>

            <table class="invoice-table">
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Medicine</th>
                        <th>Qty</th>
                        <th>Price</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $no = 1;
                    if ($items) {
                        while ($row = $items->fetch_assoc()) {
                            $sub = (float)$row['quantity'] * (float)$row['selling_price'];
                    ?>
                        <tr>
                            <td><?php echo $no++; ?></td>
                            <td><?php echo htmlspecialchars($row['medicine_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo (int)$row['quantity']; ?></td>
                            <td><?php echo number_format((float)$row['selling_price'], 2); ?></td>
                            <td><?php echo number_format((float)$sub, 2); ?></td>
                        </tr>
                    <?php
                        }
                    }
                    ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4">Grand Total</td>
                        <td>&#8377; <?php echo number_format((float)$total_amount, 2); ?></td>
                    </tr>
                </tfoot>
            </table>

            <div class="bill-actions">
                <button type="button" class="btn btn-primary" onclick="window.print()">Save / Print Invoice</button>
                <a class="btn btn-secondary" href="billing.php" target="_self">New Bill</a>
            </div>

        <?php } ?>

    </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>

<script>
(function () {
    var cartBody = document.getElementById("cart_rows");
    var addRowButton = document.getElementById("add_cart_row");
    var estimateNode = document.getElementById("cart_estimate");

    if (!cartBody || !addRowButton || !estimateNode) {
        return;
    }

    var medicineMeta = <?php echo json_encode($medicineMeta, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

    function formatMoney(value) {
        return "Rs " + Number(value || 0).toLocaleString("en-IN", {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function getMedicine(select) {
        return medicineMeta[select.value] || null;
    }

    function updateRow(row) {
        var select = row.querySelector(".cart-medicine-select");
        var quantityInput = row.querySelector(".cart-qty-input");
        var stockText = row.querySelector(".cart-stock-text");
        var priceText = row.querySelector(".cart-price-text");
        var totalText = row.querySelector(".cart-line-total");
        var medicine = getMedicine(select);
        var quantity = parseInt(quantityInput.value, 10);

        if (!Number.isFinite(quantity) || quantity < 1) {
            quantity = 0;
        }

        if (medicine) {
            stockText.textContent = String(medicine.available);
            priceText.textContent = formatMoney(medicine.price);
            totalText.textContent = formatMoney(quantity * Number(medicine.price || 0));
        } else {
            stockText.textContent = "-";
            priceText.textContent = formatMoney(0);
            totalText.textContent = formatMoney(0);
        }
    }

    function updateRemoveState() {
        var buttons = cartBody.querySelectorAll(".cart-remove-row");
        var singleRow = buttons.length === 1;
        buttons.forEach(function (button) {
            button.disabled = singleRow;
        });
    }

    function updateEstimate() {
        var total = 0;
        var rows = cartBody.querySelectorAll(".cart-row");

        rows.forEach(function (row) {
            updateRow(row);
            var select = row.querySelector(".cart-medicine-select");
            var quantityInput = row.querySelector(".cart-qty-input");
            var medicine = getMedicine(select);
            var quantity = parseInt(quantityInput.value, 10);

            if (medicine && Number.isFinite(quantity) && quantity > 0) {
                total += quantity * Number(medicine.price || 0);
            }
        });

        estimateNode.textContent = formatMoney(total);
        updateRemoveState();
    }

    function bindRow(row) {
        var select = row.querySelector(".cart-medicine-select");
        var quantityInput = row.querySelector(".cart-qty-input");
        var removeButton = row.querySelector(".cart-remove-row");

        select.addEventListener("change", updateEstimate);
        quantityInput.addEventListener("input", updateEstimate);
        removeButton.addEventListener("click", function () {
            var rows = cartBody.querySelectorAll(".cart-row");
            if (rows.length === 1) {
                select.value = "";
                quantityInput.value = "1";
            } else {
                row.remove();
            }
            updateEstimate();
        });
    }

    function createRow() {
        var sourceRow = cartBody.querySelector(".cart-row");
        if (!sourceRow) {
            return;
        }

        var newRow = sourceRow.cloneNode(true);
        var select = newRow.querySelector(".cart-medicine-select");
        var quantityInput = newRow.querySelector(".cart-qty-input");

        select.value = "";
        quantityInput.value = "1";

        cartBody.appendChild(newRow);
        bindRow(newRow);
        updateEstimate();
    }

    cartBody.querySelectorAll(".cart-row").forEach(function (row) {
        bindRow(row);
    });

    addRowButton.addEventListener("click", createRow);
    updateEstimate();
})();
</script>





