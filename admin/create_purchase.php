<?php


// start session
session_start();
// connect to db
require_once __DIR__ . "/../config/database.php";

// check admin login
if (!isset($_SESSION['role']) || $_SESSION['role'] !== "admin") {
    header("Location: ../auth/login.php");
    exit();
}

$message = "";
$error = "";
$adminId = (string)($_SESSION['id'] ?? '');
$adminName = (string)($_SESSION['username'] ?? 'Admin');

// get new purchase id
$q = $conn->query("SELECT MAX(CAST(SUBSTRING(purchase_id,4) AS UNSIGNED)) AS max_id FROM purchase");
$row = $q->fetch_assoc();
$number = ($row['max_id'] !== NULL) ? ((int)$row['max_id'] + 1) : 1;
$purchase_id = "PUR" . str_pad((string)$number, 3, "0", STR_PAD_LEFT);

// handle form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // get form values
    $vendor_id = trim((string)($_POST['vendor_id'] ?? ''));
    $purchase_date = trim((string)($_POST['purchase_date'] ?? ''));

    if ($vendor_id === '' || $purchase_date === '') {
        $error = "Vendor and Date required.";
    } else {
        $total_amount = 0;

        // insert purchase
        $stmt = $conn->prepare("INSERT INTO purchase (purchase_id, vendor_id, purchase_date, total_amount) VALUES (?,?,?,0)");
        $stmt->bind_param("sss", $purchase_id, $vendor_id, $purchase_date);
        $stmt->execute();
        $stmt->close();

        $q2 = $conn->query("SELECT MAX(CAST(SUBSTRING(purchase_detail_id,3) AS UNSIGNED)) AS max_id FROM purchase_details");
        $row2 = $q2->fetch_assoc();
        $detail_number = ($row2['max_id'] !== NULL) ? ((int)$row2['max_id'] + 1) : 1;

        foreach ($_POST['medicine_name'] as $i => $med_name) {
            // get each row
            $med_name = trim((string)$med_name);
            $description = trim((string)($_POST['description'][$i] ?? ''));
            $quantity = (int)($_POST['quantity'][$i] ?? 0);
            $cost_price = (float)($_POST['cost_price'][$i] ?? 0);
            $batch_no = trim((string)($_POST['batch_no'][$i] ?? ''));
            $expiry_date = trim((string)($_POST['expiry_date'][$i] ?? ''));
            $selling_price = (float)($_POST['selling_price'][$i] ?? 0);

            if ($med_name === '' || $description === '' || $batch_no === '' || $expiry_date === '' || $adminId === '') {
                $error = "All fields required.";
                break;
            }
            if ($quantity <= 0 || $cost_price <= 0 || $selling_price <= 0) {
                $error = "Invalid quantity or price.";
                break;
            }

            // check if medicine exists
            $check_product = $conn->prepare("SELECT medicine_id FROM product WHERE medicine_name=?");
            $check_product->bind_param("s", $med_name);
            $check_product->execute();
            $product_result = $check_product->get_result();

            if ($product_result->num_rows > 0) {
                $prod_row = $product_result->fetch_assoc();
                $medicine_id = $prod_row['medicine_id'];
            } else {
                // new medicine
                $qP = $conn->query("SELECT MAX(CAST(SUBSTRING(medicine_id,2) AS UNSIGNED)) AS max_id FROM product");
                $rowP = $qP->fetch_assoc();
                $numP = ($rowP['max_id'] !== NULL) ? ((int)$rowP['max_id'] + 1) : 1;
                $medicine_id = "P" . str_pad((string)$numP, 3, "0", STR_PAD_LEFT);

                $insert_product = $conn->prepare("INSERT INTO product (medicine_id, medicine_name, description) VALUES (?,?,?)");
                $insert_product->bind_param("sss", $medicine_id, $med_name, $description);
                $insert_product->execute();
                $insert_product->close();
            }
            $check_product->close();

            $detail_id = "PD" . str_pad((string)$detail_number++, 3, "0", STR_PAD_LEFT);
            $subtotal = $quantity * $cost_price;
            $total_amount += $subtotal;

            // insert purchase detail
            $stmt2 = $conn->prepare("INSERT INTO purchase_details (purchase_detail_id, purchase_id, medicine_id, admin_id, quantity, cost_price) VALUES (?,?,?,?,?,?)");
            $stmt2->bind_param("sssidd", $detail_id, $purchase_id, $medicine_id, $adminId, $quantity, $cost_price);
            $stmt2->execute();
            $stmt2->close();

            // check stock
            $check_stock = $conn->prepare("SELECT stock_id, quantity FROM stock WHERE medicine_id=? AND batch_no=?");
            $check_stock->bind_param("ss", $medicine_id, $batch_no);
            $check_stock->execute();
            $stock_result = $check_stock->get_result();

            if ($stock_result->num_rows > 0) {
                $row_stock = $stock_result->fetch_assoc();
                $new_qty = (int)$row_stock['quantity'] + $quantity;

                $update_stock = $conn->prepare("UPDATE stock SET quantity=?, expiry_date=?, selling_price=? WHERE stock_id=?");
                $update_stock->bind_param("isds", $new_qty, $expiry_date, $selling_price, $row_stock['stock_id']);
                $update_stock->execute();
                $update_stock->close();
            } else {
                // new stock
                $qS = $conn->query("SELECT MAX(CAST(SUBSTRING(stock_id,2) AS UNSIGNED)) AS max_id FROM stock");
                $rowS = $qS->fetch_assoc();
                $numS = ($rowS['max_id'] !== NULL) ? ((int)$rowS['max_id'] + 1) : 1;
                $stock_id = "S" . str_pad((string)$numS, 3, "0", STR_PAD_LEFT);

                $insert_stock = $conn->prepare("INSERT INTO stock (stock_id, medicine_id, batch_no, expiry_date, quantity, selling_price) VALUES (?,?,?,?,?,?)");
                $insert_stock->bind_param("ssssid", $stock_id, $medicine_id, $batch_no, $expiry_date, $quantity, $selling_price);
                $insert_stock->execute();
                $insert_stock->close();
            }
            $check_stock->close();
        }

        // update purchase total
        $stmt3 = $conn->prepare("UPDATE purchase SET total_amount=? WHERE purchase_id=?");
        $stmt3->bind_param("ds", $total_amount, $purchase_id);
        $stmt3->execute();
        $stmt3->close();

        if (empty($error)) {
            $message = "Purchase added. ID: $purchase_id | Total Rs " . number_format($total_amount, 2);
        }
    }
}

// get vendors
$vendors = $conn->query("SELECT vendor_id, name FROM vendor ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar.php";
?>

<div class="main purchases-main create-purchase-page">
    <div class="topbar">
        <div class="topbar-text">
            <h2>Add Purchase</h2>
        </div>
        <div class="top-actions">
            <a href="purchases.php" class="btn btn-secondary">Back to Purchases</a>
            <a href="../auth/logout.php" class="logout-btn">Logout</a>
        </div>
    </div>

    <div class="box purchases-box create-purchase-box">
        <?php if($error){ ?>
            <p class="status-error"><?php echo $error; ?></p>
        <?php } ?>

        <?php if($message){ ?>
            <p class="status-success"><?php echo $message; ?></p>
        <?php } ?>

        <form method="POST" class="purchase-entry-form">
            <div class="purchase-form-section">
                <h3>Purchase Info</h3>

                <div class="purchase-info-row">
                    <input type="text" value="<?php echo $purchase_id; ?>" readonly>

                    <select name="vendor_id" required>
                        <option value="">Select Vendor</option>
                        <?php foreach($vendors as $v){ ?>
                            <option value="<?php echo $v['vendor_id']; ?>">
                                <?php echo $v['name']; ?>
                            </option>
                        <?php } ?>
                    </select>

                    <input type="date" name="purchase_date" required>
                </div>
            </div>

            <div class="purchase-form-section">
                <h3>Purchase Details</h3>

                <div class="purchase-table-wrap">
                    <table class="purchase-form-table purchase-form-table-wide">
                        <thead>
                            <tr>
                                <th>Medicine Name</th>
                                <th>Description</th>
                                <th>Admin</th>
                                <th>Batch</th>
                                <th>Expiry</th>
                                <th>Qty</th>
                                <th>Cost Price</th>
                                <th>Selling Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><input type="text" name="medicine_name[]" required></td>
                                <td><input type="text" name="description[]" required></td>
                                <td><input type="text" value="<?php echo $loggedAdminName; ?>" readonly></td>
                                <td><input type="text" name="batch_no[]" required></td>
                                <td><input type="date" name="expiry_date[]" required></td>
                                <td><input type="number" name="quantity[]" min="1" required></td>
                                <td><input type="number" name="cost_price[]" min="0.01" step="0.01" required></td>
                                <td><input type="number" name="selling_price[]" min="0.01" step="0.01" required></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="purchase-form-actions">
                <button type="submit" class="btn btn-primary">Add Purchase</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>