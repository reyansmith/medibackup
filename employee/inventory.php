<?php


// Start session and check employee login
session_start();
require_once __DIR__ . "/../config/database.php";
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'employee') {
    // Redirect to login if not employee
    header("Location: ../auth/login.php");
    exit();
}


// Section: products or stock
$section = isset($_GET['section']) ? $_GET['section'] : "products";

// Product search and sort
$search = "";
$sortBy = isset($_GET['sort_by']) ? $_GET['sort_by'] : "id";
$allowedSortBy = array("id", "name");
if (!in_array($sortBy, $allowedSortBy, true)) {
    $sortBy = "id";
}
$orderColumn = $sortBy === "name" ? "p.medicine_name" : "p.medicine_id";
$orderDirection = "ASC";


// Fetch products with optional search
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


// Count products
$productCount = ($products instanceof mysqli_result) ? $products->num_rows : 0;


// Stock filters and sorting
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


// Map for stock sorting columns
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


// Build stock filter conditions
$stockConditions = array();
if ($stockStatusFilter === "expired") {
    $stockConditions[] = "s.expiry_date < CURDATE()";
} elseif ($stockStatusFilter === "out_of_stock") {
    $stockConditions[] = "s.expiry_date >= CURDATE()";
    $stockConditions[] = "s.quantity <= 0";
} elseif ($stockStatusFilter === "low_stock") {
    $stockConditions[] = "s.expiry_date >= CURDATE()";
    $stockConditions[] = "s.quantity > 0";
    $stockConditions[] = "s.quantity <= 10";
} elseif ($stockStatusFilter === "in_stock") {
    $stockConditions[] = "s.expiry_date >= CURDATE()";
    $stockConditions[] = "s.quantity > 10";
}

if ($stockSearch !== "") {
    $stockConditions[] = "(s.stock_id LIKE '%$stockSearch%' OR p.medicine_name LIKE '%$stockSearch%' OR s.batch_no LIKE '%$stockSearch%')";
}

$stockWhere = count($stockConditions) > 0 ? "WHERE " . implode(" AND ", $stockConditions) : "";

// Fetch stocks with filters
$stocks = $conn->query("
    SELECT s.*, p.medicine_name
    FROM stock s
    JOIN product p ON p.medicine_id = s.medicine_id
    $stockWhere
    ORDER BY $stockOrderColumn $stockOrderDirection
");

// Count stocks
$stockCount = ($stocks instanceof mysqli_result) ? $stocks->num_rows : 0;

// Helper function for stock status
function stock_status($expiryDate, $quantity) {
    $today = date('Y-m-d');
    if ($expiryDate < $today) {
        return "Expired";
    }
    if ((int)$quantity <= 0) {
        return "Out of Stock";
    }
    if ((int)$quantity <= 10) {
        return "Low Stock";
    }
    return "In Stock";
}
?>


// Include header and sidebar
<?php include __DIR__ . "/../includes/header.php"; ?>
<?php include __DIR__ . "/../includes/sidebar.php"; ?>

<div class="main inventory-main">
    <div class="topbar">
        <h2>Inventory</h2>
        <a href="../auth/logout.php" class="logout-btn">Logout</a>
    </div>
    <div class="inv-nav">
        <a href="?section=products" class="<?php echo ($section === 'products') ? 'on' : ''; ?>">Products</a>
        <a href="?section=stock" class="<?php echo ($section === 'stock') ? 'on' : ''; ?>">Stock</a>
    </div>
    <!-- PRODUCTS -->
    <?php if ($section === "products") { ?>
        <div class="inv-grid single">
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
                    </tr>
                    <?php if ($productCount > 0) { ?>
                        <?php while ($row = $products->fetch_assoc()) { ?>
                            <tr>
                                <td><?php echo $row['medicine_id']; ?></td>
                                <td><?php echo $row['medicine_name']; ?></td>
                                <td><?php echo $row['description']; ?></td>
                                <td><?php echo (int)$row['total_stock']; ?></td>
                            </tr>
                        <?php } ?>
                    <?php } else { ?>
                        <tr>
                            <td colspan="4" class="inv-empty-row">No medicines found for your search.</td>
                        </tr>
                    <?php } ?>
                </table>
            </div>
        </div>
    <?php } ?>
    <!-- STOCK -->
    <?php if ($section === "stock") { ?>
        <div class="inv-grid single">
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
                    </tr>
                    <?php if ($stockCount > 0) { ?>
                        <?php while ($s = $stocks->fetch_assoc()) { ?>
                            <?php
                                $status = stock_status($s['expiry_date'], $s['quantity']);
                            ?>
                            <tr>
                                <td><?php echo $s['stock_id']; ?></td>
                                <td><?php echo $s['medicine_name']; ?></td>
                                <td><?php echo $s['batch_no']; ?></td>
                                <td><?php echo $s['expiry_date']; ?></td>
                                <td><?php echo (int)$s['quantity']; ?></td>
                                <td><?php echo $s['selling_price']; ?></td>
                                <td><span class="stock-status status-<?php echo strtolower(str_replace(' ', '-', $status)); ?>"><?php echo $status; ?></span></td>
                            </tr>
                        <?php } ?>
                    <?php } else { ?>
                        <tr>
                            <td colspan="7" class="inv-empty-row">No stock records found for this filter.</td>
                        </tr>
                    <?php } ?>
                </table>
            </div>
        </div>
    <?php } ?>
</div>


// Include footer
<?php include __DIR__ . "/../includes/footer.php"; ?>
