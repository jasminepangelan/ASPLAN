<?php
$activeAdminPage = isset($activeAdminPage) ? (string)$activeAdminPage : '';
$adminSidebarCollapsed = isset($adminSidebarCollapsed) ? (bool)$adminSidebarCollapsed : true;

$scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$adminBasePath = '/admin';
if (preg_match('#^(.*?/admin)(?:/.*)?$#', $scriptName, $matches)) {
    $adminBasePath = $matches[1];
} elseif ($scriptName === '/admin') {
    $adminBasePath = '/admin';
}

$adminUrl = static function (string $path) use ($adminBasePath): string {
    $path = ltrim($path, '/');
    return htmlspecialchars(rtrim($adminBasePath, '/') . '/' . $path, ENT_QUOTES, 'UTF-8');
};

$activeClass = static function (array $pages) use ($activeAdminPage): string {
    return in_array($activeAdminPage, $pages, true) ? ' class="active"' : '';
};
?>
<!-- Sidebar Navigation -->
<div class="sidebar<?php echo $adminSidebarCollapsed ? ' collapsed' : ''; ?>" id="sidebar">
    <div class="sidebar-header">
        <h3>Admin Panel</h3>
    </div>
    <ul class="sidebar-menu">
        <div class="menu-group">
            <div class="menu-group-title">Dashboard</div>
            <li><a href="<?php echo $adminUrl('index.php'); ?>"<?php echo $activeClass(['index']); ?>><img src="../pix/home1.png" alt="Dashboard" style="filter: brightness(0) invert(1);"> Dashboard</a></li>
        </div>

        <div class="menu-group">
            <div class="menu-group-title">User Management</div>
            <li><a href="<?php echo $adminUrl('account_module.php'); ?>"<?php echo $activeClass(['account_module', 'accounts_view', 'account_management', 'input_form']); ?>><img src="../pix/account.png" alt="User Management"> User Management</a></li>
            <li><a href="<?php echo $adminUrl('adviser_management.php'); ?>"<?php echo $activeClass(['adviser_management']); ?>><img src="../pix/account.png" alt="Adviser Management"> Adviser Management</a></li>
        </div>

        <div class="menu-group">
            <div class="menu-group-title">Student Management</div>
            <li><a href="<?php echo $adminUrl('pending_accounts.php'); ?>"<?php echo $activeClass(['pending_accounts']); ?>><img src="../pix/pending.png" alt="Pending"> Pending Accounts</a></li>
            <li><a href="<?php echo $adminUrl('list_of_students.php'); ?>"<?php echo $activeClass(['list_of_students']); ?>><img src="../pix/checklist.png" alt="Students"> Registered Students</a></li>
        </div>

        <div class="menu-group">
            <div class="menu-group-title">System</div>
            <li><a href="<?php echo $adminUrl('programs.php'); ?>"<?php echo $activeClass(['programs']); ?>><img src="../pix/update.png" alt="Programs"> Programs</a></li>
            <li><a href="<?php echo $adminUrl('curriculum_management.php'); ?>"<?php echo $activeClass(['curriculum_management']); ?>><img src="../pix/curr.png" alt="Curriculum"> Curriculum Management</a></li>
        </div>

        <div class="menu-group">
            <div class="menu-group-title">Approval</div>
            <li><a href="<?php echo $adminUrl('account_approval_settings.php'); ?>"<?php echo $activeClass(['account_approval_settings']); ?>><img src="../pix/set.png" alt="Settings"> Settings</a></li>
        </div>

        <div class="menu-group">
            <div class="menu-group-title">Account</div>
            <li><a href="<?php echo $adminUrl('logout.php'); ?>"><img src="../pix/singout.png" alt="Sign Out"> Sign Out</a></li>
        </div>
    </ul>
</div>
