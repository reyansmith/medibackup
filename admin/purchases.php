<?php

session_start();
require_once __DIR__ . "/../config/database.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] !== "admin") {
    header("Location: ../auth/login.php");
    exit();
}

$sql = "
SELECT p.purchase_id, p.vendor_id, p.purchase_date, 
       IFNULL(SUM(pd.quantity * pd.cost_price),0) AS total_amount
FROM purchase p
LEFT JOIN purchase_details pd ON pd.purchase_id = p.purchase_id
GROUP BY p.purchase_id, p.vendor_id, p.purchase_date
ORDER BY p.purchase_date DESC, p.purchase_id DESC
";

$result = $conn->query($sql);
$purchases = [];
if($result){
    while($row = $result->fetch_assoc()){
        $purchases[] = $row;
    }
}

$vendor_result = $conn->query("SELECT vendor_id, name FROM vendor");
$vendors = [];
if($vendor_result){
    while($v = $vendor_result->fetch_assoc()){
        $vendors[$v['vendor_id']] = $v['name'];
    }
}


include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar.php";
?>

<div class="main purchases-main">
    <div class="topbar">
        <h2>Purchases</h2>
        <div class="top-actions">
            <a href="create_purchase.php" class="btn">Add Purchase</a>
            <a href="../auth/logout.php" class="logout-btn">Logout</a>
        </div>
    </div>

    <div class="box purchases-box">
        <div class="table-wrap">
        <table class="leaderboard-table purchase-summary-table">
            <thead>
                <tr>
                    <th>Purchase ID</th>
                    <th>Vendor</th>
                    <th>Purchase Date</th>
                    <th>Total Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php
// easy note: keep logic same, only code reading made simple.
                if(empty($purchases)){
                    echo "<tr><td colspan='4' class='inv-empty-row'>No purchases found.</td></tr>";
                } else {
                    foreach($purchases as $p){
                        echo "<tr>";
                        echo "<td>" . $p['purchase_id'] . "</td>";
                        echo "<td>" . ($vendors[$p['vendor_id']] ?? '-') . "</td>";
                        echo "<td>" . $p['purchase_date'] . "</td>";
                        echo "<td>Rs " . number_format($p['total_amount'],2) . "</td>";
                        echo "</tr>";
                    }
                }
                ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>










