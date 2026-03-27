<?php

session_start();
require_once __DIR__ . "/../config/database.php";

// Only admin can access this page.
if (!isset($_SESSION['role']) || $_SESSION['role'] !== "admin") {
    header("Location: ../auth/login.php");
    exit();
}

// These variables store the page message and edit state.
$message = "";
$is_error = false;
$edit_vendor = null;

// This block handles add, update, and remove actions.
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_vendor'])) {
        // Read form values.
        $vendor_id = trim((string)($_POST['vendor_id'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));

        // Phone number must be 10 digits.
        if (!preg_match('/^\d{10}$/', $phone)) {
            $message = "Please enter a valid 10-digit phone number.";
            $is_error = true;
        } else {
            // Save the new vendor.
            $stmt = $conn->prepare("INSERT INTO vendor (vendor_id, name, phone, address) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $vendor_id, $name, $phone, $address);
            if ($stmt->execute()) {
                $message = "Vendor added successfully!";
            } else {
                $message = "Could not add the vendor. Please check the details and try again.";
                $is_error = true;
            }
            $stmt->close();
        }
    }

    // Update an existing vendor.
    if (isset($_POST['update_vendor'])) {
        $vendor_id = trim((string)($_POST['vendor_id'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));

        if (!preg_match('/^\d{10}$/', $phone)) {
            $message = "Please enter a valid 10-digit phone number.";
            $is_error = true;
        } else {
            $stmt = $conn->prepare("UPDATE vendor SET name = ?, phone = ?, address = ? WHERE vendor_id = ?");
            $stmt->bind_param("ssss", $name, $phone, $address, $vendor_id);
            if ($stmt->execute()) {
                $message = "Vendor updated successfully!";
            } else {
                $message = "Could not update the vendor. Please try again.";
                $is_error = true;
            }
            $stmt->close();
        }
    }

    // Remove a vendor only if no purchase is linked to it.
    if (isset($_POST['remove_vendor'])) {
        $vendor_id = $_POST['remove_vendor_id'];
        try {
            $ref_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM purchase WHERE vendor_id = ?");
            $ref_stmt->bind_param("s", $vendor_id);
            $ref_stmt->execute();
            $ref_res = $ref_stmt->get_result();
            $ref_row = $ref_res ? $ref_res->fetch_assoc() : null;
            $ref_stmt->close();

            $linked_count = (int)($ref_row['cnt'] ?? 0);
            if ($linked_count > 0) {
                $message = "This vendor cannot be removed because it is linked to " . $linked_count . " purchase record.";
                $is_error = true;
            } else {
                $stmt = $conn->prepare("DELETE FROM vendor WHERE vendor_id = ?");
                $stmt->bind_param("s", $vendor_id);
                if ($stmt->execute()) {
                    $message = "Vendor removed successfully!";
                } else {
                    $message = "Could not remove the vendor right now. Please try again.";
                    $is_error = true;
                }
                $stmt->close();
            }
        } catch (mysqli_sql_exception $e) {
            $message = "This vendor cannot be removed because related records still exist.";
            $is_error = true;
        }
    }
}

// Load vendor details when the edit button is clicked.
if (isset($_GET['edit']) && $_GET['edit'] !== "") {
    $edit_id = $_GET['edit'];
    $stmt = $conn->prepare("SELECT vendor_id, name, phone, address FROM vendor WHERE vendor_id = ? LIMIT 1");
    $stmt->bind_param("s", $edit_id);
    $stmt->execute();
    $result_edit = $stmt->get_result();
    if ($result_edit && $result_edit->num_rows > 0) {
        $edit_vendor = $result_edit->fetch_assoc();
    }
    $stmt->close();
}

// Get all vendors for the table.
$vendors = [];
$result = $conn->query("SELECT vendor_id, name, phone, address FROM vendor ORDER BY name ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $vendors[] = $row;
    }
}
?>

<?php include __DIR__ . "/../includes/header.php"; ?>
<?php include __DIR__ . "/../includes/sidebar.php"; ?>

<div class="main vendor-main">
    <div class="topbar">
        <div class="topbar-text">
            <h2>Vendors</h2>
        </div>
        <div class="top-actions">
            <a href="../auth/logout.php" class="logout-btn">Logout</a>
        </div>
    </div>

    <div class="box vendor-box">
        <?php 
        // Show success or error message at the top.
        if ($message) {
            $msg_class = $is_error ? "vendor-message vendor-message-error" : "vendor-message";
            echo "<p class='" . $msg_class . "'>" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "</p>";
        }
        ?>

        <!-- Add Vendor Button -->
        <button id="addVendorBtn" class="btn btn-primary vendor-add-btn">Add Vendor</button>

        <!-- Add Vendor Form (hidden initially) -->
        <form id="addVendorForm" method="POST" class="vendor-form-hidden">
            <div class="vendor-form-row">
                <input type="text" name="vendor_id" placeholder="Vendor ID" required class="vendor-input">
                <input type="text" name="name" placeholder="Vendor Name" required class="vendor-input">
                <input type="text" name="phone" placeholder="Phone" maxlength="10" pattern="\d{10}" title="Enter exactly 10 digits" class="vendor-input" required>
                <input type="text" name="address" placeholder="Address" class="vendor-input">
                <button type="submit" name="add_vendor" class="btn btn-primary">Save Vendor</button>
            </div>
        </form>

        <?php if ($edit_vendor) { ?>
            <form method="POST" class="vendor-edit-form">
                <h3 class="vendor-edit-title">Edit Vendor</h3>
                <div class="vendor-form-row">
                    <input type="text" name="vendor_id" value="<?php echo htmlspecialchars($edit_vendor['vendor_id'], ENT_QUOTES, 'UTF-8'); ?>" readonly class="vendor-input">
                    <input type="text" name="name" value="<?php echo htmlspecialchars($edit_vendor['name'], ENT_QUOTES, 'UTF-8'); ?>" required class="vendor-input">
                    <input type="text" name="phone" value="<?php echo htmlspecialchars($edit_vendor['phone'], ENT_QUOTES, 'UTF-8'); ?>" maxlength="10" pattern="\d{10}" title="Enter exactly 10 digits" class="vendor-input" required>
                    <input type="text" name="address" value="<?php echo htmlspecialchars($edit_vendor['address'], ENT_QUOTES, 'UTF-8'); ?>" class="vendor-input">
                    <button type="submit" name="update_vendor" class="btn btn-primary">Update Vendor</button>
                    <a href="vendors.php" class="btn btn-secondary vendor-cancel-btn">Cancel</a>
                </div>
            </form>
        <?php } ?>

        <div class="table-wrap">
            <table class="leaderboard-table transactions-table vendor-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Address</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Show table rows if vendors exist.
                    if (empty($vendors)) {
                        echo "<tr><td colspan='5' class='inv-empty-row'>No vendors found.</td></tr>";
                    } else {
                        foreach ($vendors as $vendor) {
                            // Escape output before printing it.
                            $vendorId = htmlspecialchars($vendor['vendor_id'], ENT_QUOTES, 'UTF-8');
                            $vendorName = htmlspecialchars($vendor['name'], ENT_QUOTES, 'UTF-8');
                            $vendorPhone = htmlspecialchars($vendor['phone'], ENT_QUOTES, 'UTF-8');
                            $vendorAddress = htmlspecialchars($vendor['address'], ENT_QUOTES, 'UTF-8');
                            echo "<tr>";
                            echo "<td>" . $vendorId . "</td>";
                            echo "<td>" . $vendorName . "</td>";
                            echo "<td>" . $vendorPhone . "</td>";
                            echo "<td>" . $vendorAddress . "</td>";
                            echo "<td><div class='action-wrap'>";
                            echo "<a class='btn btn-primary btn-sm' href='vendors.php?edit=" . urlencode($vendor['vendor_id']) . "'>Edit</a>";
                            // Small remove form for each row.
                            echo "<form method='POST' class='vendor-inline-form' onsubmit=\"return confirm('Are you sure you want to remove this vendor?');\">";
                            echo "<input type='hidden' name='remove_vendor_id' value='" . $vendorId . "'>";
                            echo "<button type='submit' name='remove_vendor' class='btn btn-danger btn-sm'>Remove</button>";
                            echo "</form>";
                            echo "</div></td>";
                            echo "</tr>";
                        }
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Show or hide the add vendor form.
document.getElementById('addVendorBtn').addEventListener('click', function() {
    var form = document.getElementById('addVendorForm');
    if (form.style.display === 'none' || window.getComputedStyle(form).display === 'none') {
        form.style.display = 'block';
        this.textContent = 'Cancel';
    } else {
        form.style.display = 'none';
        this.textContent = 'Add Vendor';
    }
});
</script>

<?php include __DIR__ . "/../includes/footer.php"; ?>











