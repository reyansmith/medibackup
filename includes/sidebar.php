<?php

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$currentDir = basename(dirname($_SERVER['PHP_SELF'] ?? ''));
$appArea = in_array($currentDir, ['admin', 'employee'], true) ? $currentDir : 'root';
$loggedName = isset($_SESSION['username']) && $_SESSION['username'] !== ''
    ? (string)$_SESSION['username']
    : 'Unknown';
$loggedRoleRaw = isset($_SESSION['role']) && $_SESSION['role'] !== ''
    ? (string)$_SESSION['role']
    : 'user';
$loggedRole = ucfirst($loggedRoleRaw);

function navLinkPath($fileName, $role, $appArea) {
    if ($role === 'employee') {
        return $appArea === 'employee' ? $fileName : 'employee/' . $fileName;
    }

    if ($role === 'admin') {
        return $appArea === 'admin' ? $fileName : 'admin/' . $fileName;
    }

    return $fileName;
}

$navItems = [];
if ($loggedRoleRaw === 'employee') {
    $navItems = [
        ['file' => 'dashboard.php', 'icon' => 'fas fa-chart-line', 'label' => 'Dashboard'],
        ['file' => 'billing.php', 'icon' => 'fas fa-file-invoice-dollar', 'label' => 'Billing'],
        ['file' => 'reports.php', 'icon' => 'fas fa-chart-bar', 'label' => 'Reports'],
    ];
} else {
    $navItems = [
        ['file' => 'dashboard.php', 'icon' => 'fas fa-chart-line', 'label' => 'Dashboard'],
        ['file' => 'inventory.php', 'icon' => 'fas fa-pills', 'label' => 'Inventory'],
        ['file' => 'purchases.php', 'icon' => 'fas fa-truck', 'label' => 'Purchases'],
        ['file' => 'vendors.php', 'icon' => 'fas fa-user-md', 'label' => 'Vendors'],
        ['file' => 'reports.php', 'icon' => 'fas fa-chart-bar', 'label' => 'Reports'],
        ['file' => 'employees.php', 'icon' => 'fas fa-users', 'label' => 'Employees'],
    ];
}
?>

<div class="sidebar">
    <div class="sidebar-brand">
        <h2 class="logo">Mannath Medicals</h2>
        <p class="sidebar-subtitle">Kaikamba, Mangaluru</p>
    </div>

    <ul class="nav-links">
        <?php foreach ($navItems as $item) { ?>
            <li class="<?php echo $currentPage === $item['file'] ? 'active' : ''; ?>">
                <a href="<?php echo navLinkPath($item['file'], $loggedRoleRaw, $appArea); ?>">
                    <i class="<?php echo $item['icon']; ?>"></i>
                    <span><?php echo $item['label']; ?></span>
                </a>
            </li>
        <?php } ?>
    </ul>

    <div class="sidebar-user">
        <p class="sidebar-user-label">Logged In</p>
        <p class="sidebar-user-name"><?php echo htmlspecialchars($loggedName, ENT_QUOTES, 'UTF-8'); ?></p>
        <p class="sidebar-user-role"><?php echo htmlspecialchars($loggedRole, ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
</div>
