<?php


// start session
session_start();
// connect to db
require_once __DIR__ . "/../config/database.php";

// check admin login
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

$msg = "";
$msgType = "success";

// handle form actions
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = isset($_POST['action']) ? $_POST['action'] : "";

    // update product
    if ($action === "save_product") {
        $id = isset($_POST['medicine_id']) ? trim($_POST['medicine_id']) : "";
        $name = isset($_POST['medicine_name']) ? trim($_POST['medicine_name']) : "";
        $desc = isset($_POST['description']) ? trim($_POST['description']) : "";

        if ($id === "" || $name === "") {
            $msg = "Medicine ID and name are required.";
            $msgType = "error";
        } else {
            $check = $conn->prepare("SELECT 1 FROM product WHERE medicine_id=?");
            $check->bind_param("s", $id);
            $check->execute();
            $exists = $check->get_result()->num_rows > 0;
            $check->close();

            if (!$exists) {
                $msg = "Medicine not found. Add new medicines from Purchases only.";
                $msgType = "error";
            } else {
                $stmt = $conn->prepare("UPDATE product SET medicine_name=?, description=? WHERE medicine_id=?");
                $stmt->bind_param("sss", $name, $desc, $id);
                $stmt->execute();
                $stmt->close();
                $msg = "Medicine updated.";
            }
        }
    }

    // delete product (only if no stock)
    if ($action === "delete_product") {
        $id = isset($_POST['medicine_id']) ? trim($_POST['medicine_id']) : "";

        if ($id === "") {
            $msg = "Invalid medicine ID.";
            $msgType = "error";
        } else {
            $check = $conn->prepare("SELECT COUNT(*) AS cnt FROM stock WHERE medicine_id=?");
            $check->bind_param("s", $id);
            $check->execute();
            $result = $check->get_result()->fetch_assoc();
            $check->close();

            if ((int)$result['cnt'] > 0) {
                $msg = "Cannot delete medicine with stock. Remove stock first.";
                $msgType = "error";
            } else {
                $del = $conn->prepare("DELETE FROM product WHERE medicine_id=?");
                $del->bind_param("s", $id);
                $del->execute();
                $deleted = $del->affected_rows;
                $del->close();

                if ($deleted > 0) {
                    $msg = "Medicine deleted.";
                } else {
                    $msg = "Medicine not found.";
                    $msgType = "error";
                }
            }
        }
    }

    // update stock
    if ($action === "edit_stock") {
        $stockId = isset($_POST['stock_id']) ? trim($_POST['stock_id']) : "";
        $batch = isset($_POST['batch_no']) ? trim($_POST['batch_no']) : "";
        $expiry = isset($_POST['expiry_date']) ? $_POST['expiry_date'] : "";
        $qty = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 0;
        $price = isset($_POST['selling_price']) ? (float)$_POST['selling_price'] : 0;

        if ($stockId === "") {
            $msg = "Invalid stock entry.";
            $msgType = "error";
        } else {
            $stmt = $conn->prepare("UPDATE stock SET batch_no=?, expiry_date=?, quantity=?, selling_price=? WHERE stock_id=?");
            $stmt->bind_param("ssids", $batch, $expiry, $qty, $price, $stockId);
            $stmt->execute();
            $stmt->close();
            $msg = "Stock updated.";
        }
    }

    // delete stock (only if expired or out of stock)
    if ($action === "delete_stock") {
        $id = isset($_POST['stock_id']) ? trim($_POST['stock_id']) : "";

        $checkStmt = $conn->prepare("SELECT expiry_date, quantity FROM stock WHERE stock_id=?");
        $checkStmt->bind_param("s", $id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        $stockRow = $checkResult ? $checkResult->fetch_assoc() : null;
        $checkStmt->close();

        if (!$stockRow) {
            $msg = "Stock not found.";
            $msgType = "error";
        } else {
            $isExpired = strtotime($stockRow['expiry_date']) < strtotime(date('Y-m-d'));
            $isOutOfStock = (int)$stockRow['quantity'] <= 0;

            if (!$isExpired && !$isOutOfStock) {
                $msg = "Only expired or out-of-stock stock can be deleted.";
                $msgType = "error";
            } else {
                $delStmt = $conn->prepare("DELETE FROM stock WHERE stock_id=?");
                $delStmt->bind_param("s", $id);
                $delStmt->execute();
                $delStmt->close();
                $msg = $isExpired ? "Expired stock deleted." : "Out-of-stock stock deleted.";
            }
        }
    }
}

$section = isset($_GET['section']) ? $_GET['section'] : "products";

$search = "";
$sortBy = isset($_GET['sort_by']) ? $_GET['sort_by'] : "id";

$allowedSortBy = array("id", "name");

if (!in_array($sortBy, $allowedSortBy, true)) {
    $sortBy = "id";
}

$orderColumn = $sortBy === "name" ? "p.medicine_name" : "p.medicine_id";
$orderDirection = "ASC";

if (isset($_GET['search']) && $_GET['search'] !== "") {
    $search = $conn->real_escape_string($_GET['search']);
    $products = $conn->query("
        SELECT p.*, IFNULL(SUM(s.quantity),0) AS total_stock
        FROM product p
        LEFT JOIN stock s ON s.medicine_id=p.medicine_id
        WHERE p.medicine_name LIKE '%$search%' 
           OR p.medicine_id LIKE '%$search%'
        GROUP BY p.medicine_id
        ORDER BY $orderColumn $orderDirection
    ");
} else {
    $products = $conn->query("
        SELECT p.*, IFNULL(SUM(s.quantity),0) AS total_stock
        FROM product p
        LEFT JOIN stock s ON s.medicine_id=p.medicine_id
        GROUP BY p.medicine_id
        ORDER BY $orderColumn $orderDirection
    ");
}

$productStateQuery = "";
if ($search !== "") {
    $productStateQuery .= "&search=" . urlencode($search);
}
if ($sortBy !== "id") {
    $productStateQuery .= "&sort_by=" . urlencode($sortBy);
}

$hasProductFilter = ($search !== "" || $sortBy !== "id");

$stockStatusFilter = isset($_GET['stock_status']) ? $_GET['stock_status'] : "all";
$allowedStockFilters = array("all", "in_stock", "low_stock", "out_of_stock", "expired");
if (!in_array($stockStatusFilter, $allowedStockFilters, true)) {
    $stockStatusFilter = "all";
}

$stockSortBy = isset($_GET['stock_sort_by']) ? $_GET['stock_sort_by'] : "expiry";
$allowedStockSortBy = array("stock_id", "medicine", "batch", "expiry", "qty", "price");
if (!in_array($stockSortBy, $allowedStockSortBy, true)) {
    $stockSortBy = "expiry";
}

$stockSearch = "";
if (isset($_GET['stock_search']) && $_GET['stock_search'] !== "") {
    $stockSearch = $conn->real_escape_string(trim($_GET['stock_search']));
}

$stockOrderColumnMap = array(
    "stock_id" => "s.stock_id",
    "medicine" => "p.medicine_name",
    "batch" => "s.batch_no",
    "expiry" => "s.expiry_date",
    "qty" => "s.quantity",
    "price" => "s.selling_price"
);
$stockOrderColumn = $stockOrderColumnMap[$stockSortBy];
$stockOrderDirection = "ASC";
//for expiry and quantity 
$stockConditions = array();
if ($stockStatusFilter === "expired") {
    // Expired stock means expiry date is before today.
    $stockConditions[] = "s.expiry_date < CURDATE()";
} elseif ($stockStatusFilter === "out_of_stock") {
    // Out of stock list excludes expired items.
    $stockConditions[] = "s.expiry_date >= CURDATE()";
    $stockConditions[] = "s.quantity <= 0";
} elseif ($stockStatusFilter === "low_stock") {
    // Low stock list excludes expired items.
    $stockConditions[] = "s.expiry_date >= CURDATE()";
    $stockConditions[] = "s.quantity > 0";
    $stockConditions[] = "s.quantity < 10";
} elseif ($stockStatusFilter === "in_stock") {
    $stockConditions[] = "s.expiry_date >= CURDATE()";
    $stockConditions[] = "s.quantity > 10";
}

if ($stockSearch !== "") {
    $stockConditions[] = "(s.stock_id LIKE '%$stockSearch%' OR p.medicine_name LIKE '%$stockSearch%' OR s.batch_no LIKE '%$stockSearch%')";
}

$stockWhere = count($stockConditions) > 0 ? "WHERE " . implode(" AND ", $stockConditions) : "";

$stocks = $conn->query("
    SELECT s.*, p.medicine_name
    FROM stock s
    JOIN product p ON p.medicine_id = s.medicine_id
    $stockWhere
    ORDER BY $stockOrderColumn $stockOrderDirection
");

$stockFilterQuery = $stockStatusFilter !== "all" ? "&stock_status=" . urlencode($stockStatusFilter) : "";
$stockFilterQuery .= $stockSortBy !== "expiry" ? "&stock_sort_by=" . urlencode($stockSortBy) : "";
$stockFilterQuery .= $stockSearch !== "" ? "&stock_search=" . urlencode($stockSearch) : "";
$hasStockFilter = ($stockStatusFilter !== "all" || $stockSortBy !== "expiry" || $stockSearch !== "");
$productCount = ($products instanceof mysqli_result) ? $products->num_rows : 0;
$stockCount = ($stocks instanceof mysqli_result) ? $stocks->num_rows : 0;

$editProduct = null;
if ($section === "products" && isset($_GET['edit_product']) && $_GET['edit_product'] !== "") {
    $editProductId = trim($_GET['edit_product']);
    $editProductStmt = $conn->prepare("SELECT * FROM product WHERE medicine_id=? LIMIT 1");
    $editProductStmt->bind_param("s", $editProductId);
    $editProductStmt->execute();
    $editProductResult = $editProductStmt->get_result();
    if ($editProductResult && $editProductResult->num_rows > 0) {
        $editProduct = $editProductResult->fetch_assoc();
    }
    $editProductStmt->close();
}

$editStock = null;
if ($section === "stock" && isset($_GET['edit_stock']) && $_GET['edit_stock'] !== "") {
    $editStockId = trim($_GET['edit_stock']);
    $editStockStmt = $conn->prepare("
        SELECT s.*, p.medicine_name 
        FROM stock s
        JOIN product p ON p.medicine_id = s.medicine_id
        WHERE s.stock_id=?
        LIMIT 1
    ");
    $editStockStmt->bind_param("s", $editStockId);
    $editStockStmt->execute();
    $editStockResult = $editStockStmt->get_result();
    if ($editStockResult && $editStockResult->num_rows > 0) {
        $editStock = $editStockResult->fetch_assoc();
    }
    $editStockStmt->close();
}

function stock_status($expiryDate, $quantity) {
    // Status priority: Expired -> Out of Stock -> Low Stock -> In Stock.
    $today = date('Y-m-d');
    if ($expiryDate < $today) {
        return "Expired";
    }
    if ((int)$quantity <= 0) {
        return "Out of Stock";
    }
    if ((int)$quantity < 10) {
        return "Low Stock";
    }
    return "In Stock";
}
?>

<?php include __DIR__ . "/../includes/header.php"; ?>
<?php include __DIR__ . "/../includes/sidebar.php"; ?>

<div class="main inventory-main">

    <div class="topbar">
        <h2>Inventory</h2>
        <a href="../auth/logout.php" class="logout-btn">Logout</a>
    </div>

    <?php if ($msg !== "") { ?>
        <div class="inv-alert <?php echo $msgType === "error" ? "inv-alert-error" : ""; ?>">
            <?php echo $msg; ?>
        </div>
    <?php } ?>

    <div class="inv-nav">
        <a href="?section=products" class="<?php echo ($section === 'products') ? 'on' : ''; ?>">Products</a>
        <a href="?section=stock" class="<?php echo ($section === 'stock') ? 'on' : ''; ?>">Stock</a>
    </div>

    <!-- PRODUCTS -->
    <?php if ($section === "products") { ?>
        <div class="inv-grid <?php echo $editProduct ? '' : 'single'; ?>">

            <?php if ($editProduct) { ?>
                <div class="box">
                <h3>Edit Medicine</h3>
                <form method="post">
                    <input type="hidden" name="action" value="save_product">

                    <div class="form-group">
                        <label>Medicine ID</label>
                        <input type="text" name="medicine_id" required
                               value="<?php echo $editProduct['medicine_id']; ?>"
                               readonly>
                    </div>

                    <div class="form-group">
                        <label>Medicine Name</label>
                        <input type="text" name="medicine_name" required
                               value="<?php echo $editProduct['medicine_name']; ?>">
                    </div>

                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description"><?php echo $editProduct['description']; ?></textarea>
                    </div>

                    <button class="inv-btn primary">Update</button>
                    <a href="inventory.php?section=products<?php echo $productStateQuery; ?>" class="btn btn-secondary btn-sm">Cancel</a>
                </form>
                </div>
            <?php } ?>

            <div class="box">
                <h3>Medicine List</h3>
                <form method="GET" class="inv-search-form">
                    <input type="hidden" name="section" value="products">
                    <label class="inv-field-label" for="product-search">Search</label>
                    <input
                        id="product-search"
                        type="text"
                        name="search"
                        class="inv-search-input"
                        placeholder="Search Medicine..."
                        value="<?php echo isset($_GET['search']) ? $_GET['search'] : ''; ?>"
                    >
                    <button class="inv-btn primary" type="submit">Search</button>
                    <label class="inv-field-label" for="product-sort-by">Sort By</label>
                    <select id="product-sort-by" name="sort_by" class="inv-search-input inv-select">
                        <option value="id" <?php echo $sortBy === "id" ? "selected" : ""; ?>>ID</option>
                        <option value="name" <?php echo $sortBy === "name" ? "selected" : ""; ?>>Name</option>
                    </select>
                    <?php if ($hasProductFilter) { ?>
                        <a href="inventory.php?section=products" class="btn btn-secondary btn-sm">Clear</a>
                    <?php } ?>
                </form>
                <script>
                    (function () {
                        var form = document.querySelector('.inv-search-form');
                        if (!form) return;
                        var sortSelect = form.querySelector('select[name="sort_by"]');
                        if (!sortSelect) return;
                        sortSelect.addEventListener('change', function () {
                            form.submit();
                        });
                    })();
                </script>
                <table class="leaderboard-table">
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Total Stock</th>
                        <th>Action</th>
                    </tr>
                    <?php if ($productCount > 0) { ?>
                        <?php while ($row = $products->fetch_assoc()) { ?>
                            <tr>
                                <td><?php echo $row['medicine_id']; ?></td>
                                <td><?php echo $row['medicine_name']; ?></td>
                                <td><?php echo $row['description']; ?></td>
                                <td><?php echo (int)$row['total_stock']; ?></td>
                                <td class="inv-action-cell">
                                    <div class="action-wrap inv-action-wrap">
                                        <a class="btn btn-sm inv-edit-btn" href="inventory.php?section=products&edit_product=<?php echo urlencode($row['medicine_id']); ?><?php echo $productStateQuery; ?>">Edit</a>
                                        <form method="post" onsubmit="return confirm('Delete this medicine? This works only when there are no stock entries.');">
                                            <input type="hidden" name="action" value="delete_product">
                                            <input type="hidden" name="medicine_id" value="<?php echo $row['medicine_id']; ?>">
                                            <button class="btn btn-sm inv-delete-btn">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php } ?>
                    <?php } else { ?>
                        <tr>
                            <td colspan="5" class="inv-empty-row">No medicines found for your search.</td>
                        </tr>
                    <?php } ?>
                </table>
            </div>

        </div>
    <?php } ?>

    <!-- STOCK -->
    <?php if ($section === "stock") { ?>
        <div class="inv-grid <?php echo $editStock ? '' : 'single'; ?>">

            <?php if ($editStock) { ?>
                <div class="box">
                    <h3>Edit Stock</h3>
                    <form method="post">
                        <input type="hidden" name="action" value="edit_stock">
                        <input type="hidden" name="stock_id" value="<?php echo $editStock['stock_id']; ?>">

                <div class="form-group">
                    <label>Stock ID</label>
                    <input type="text" value="<?php echo $editStock['stock_id']; ?>" readonly>
                </div>

                <div class="form-group">
                    <label>Medicine</label>
                    <input type="text" value="<?php echo $editStock['medicine_name']; ?>" readonly>
                </div>

                <div class="form-group">
                    <label>Batch No</label>
                    <input type="text" name="batch_no" value="<?php echo $editStock['batch_no']; ?>" required>
                </div>

                <div class="form-group">
                    <label>Expiry Date</label>
                    <input type="date" name="expiry_date" value="<?php echo $editStock['expiry_date']; ?>" required>
                </div>

                <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="quantity" min="0" value="<?php echo (int)$editStock['quantity']; ?>" required>
                </div>

                <div class="form-group">
                    <label>Selling Price</label>
                    <input type="number" step="0.01" name="selling_price" min="0" value="<?php echo $editStock['selling_price']; ?>" required>
                </div>

                        <button class="inv-btn primary">Update</button>
                        <a href="inventory.php?section=stock<?php echo $stockFilterQuery; ?>" class="btn btn-secondary btn-sm">Cancel</a>
                    </form>
                </div>
            <?php } ?>

            <div class="box">
                <h3>Stock List</h3>
                <form method="GET" class="inv-search-form">
                    <input type="hidden" name="section" value="stock">
                    <label class="inv-field-label" for="stock-search">Search</label>
                    <input
                        id="stock-search"
                        type="text"
                        name="stock_search"
                        class="inv-search-input"
                        placeholder="Search Stock..."
                        value="<?php echo isset($_GET['stock_search']) ? $_GET['stock_search'] : ''; ?>"
                    >
                    <button class="inv-btn primary" type="submit">Search</button>
                    <select name="stock_status" class="inv-search-input inv-select">
                        <option value="all" <?php echo $stockStatusFilter === "all" ? "selected" : ""; ?>>All Status</option>
                        <option value="in_stock" <?php echo $stockStatusFilter === "in_stock" ? "selected" : ""; ?>>In Stock</option>
                        <option value="low_stock" <?php echo $stockStatusFilter === "low_stock" ? "selected" : ""; ?>>Low Stock</option>
                        <option value="out_of_stock" <?php echo $stockStatusFilter === "out_of_stock" ? "selected" : ""; ?>>Out of Stock</option>
                        <option value="expired" <?php echo $stockStatusFilter === "expired" ? "selected" : ""; ?>>Expired</option>
                    </select>
                    <button class="inv-btn primary" type="submit">Filter</button>
                    <select name="stock_sort_by" class="inv-search-input inv-select">
                        <option value="stock_id" <?php echo $stockSortBy === "stock_id" ? "selected" : ""; ?>>Sort: Stock ID</option>
                        <option value="expiry" <?php echo $stockSortBy === "expiry" ? "selected" : ""; ?>>Sort: Expiry</option>
                        <option value="medicine" <?php echo $stockSortBy === "medicine" ? "selected" : ""; ?>>Sort: Medicine</option>
                        <option value="batch" <?php echo $stockSortBy === "batch" ? "selected" : ""; ?>>Sort: Batch</option>
                        <option value="qty" <?php echo $stockSortBy === "qty" ? "selected" : ""; ?>>Sort: Quantity</option>
                        <option value="price" <?php echo $stockSortBy === "price" ? "selected" : ""; ?>>Sort: Price</option>
                    </select>
                    <button class="inv-btn primary" type="submit">Sort</button>
                    <?php if ($hasStockFilter) { ?>
                        <a href="inventory.php?section=stock" class="btn btn-secondary btn-sm">Clear</a>
                    <?php } ?>
                </form>
                <table class="leaderboard-table">
                    <tr>
                        <th>Stock ID</th>
                        <th>Medicine</th>
                        <th>Batch</th>
                        <th>Expiry</th>
                        <th>Qty</th>
                        <th>Price</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                    <?php if ($stockCount > 0) { ?>
                        <?php while ($s = $stocks->fetch_assoc()) { ?>
                            <?php
                                $status = stock_status($s['expiry_date'], $s['quantity']);
                                $isExpired = $status === "Expired";
                                $isOutOfStock = $status === "Out of Stock";
                            ?>
                            <tr>
                                <td><?php echo $s['stock_id']; ?></td>
                                <td><?php echo $s['medicine_name']; ?></td>
                                <td><?php echo $s['batch_no']; ?></td>
                                <td><?php echo $s['expiry_date']; ?></td>
                                <td><?php echo (int)$s['quantity']; ?></td>
                                <td><?php echo $s['selling_price']; ?></td>
                                <td><span class="stock-status status-<?php echo strtolower(str_replace(' ', '-', $status)); ?>"><?php echo $status; ?></span></td>
                                <td class="inv-action-cell">
                                    <div class="action-wrap inv-action-wrap">
                                        <a class="btn btn-sm inv-edit-btn" href="inventory.php?section=stock&edit_stock=<?php echo urlencode($s['stock_id']); ?><?php echo $stockFilterQuery; ?>">Edit</a>
                                        <?php if ($isExpired || $isOutOfStock) { ?>
                                            <form method="post" onsubmit="return confirm('<?php echo $isExpired ? 'Delete this expired stock?' : 'Delete this out-of-stock entry?'; ?>');">
                                                <input type="hidden" name="action" value="delete_stock">
                                                <input type="hidden" name="stock_id" value="<?php echo $s['stock_id']; ?>">
                                                <button class="btn btn-sm inv-delete-btn">Delete</button>
                                            </form>
                                        <?php } else { ?>
                                            <span class="status-note">Expired or Out of Stock only</span>
                                        <?php } ?>
                                    </div>
                                </td>
                            </tr>
                        <?php } ?>
                    <?php } else { ?>
                        <tr>
                            <td colspan="8" class="inv-empty-row">No stock records found for this filter.</td>
                        </tr>
                    <?php } ?>
                </table>
            </div>

        </div>
    <?php } ?>

</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>












