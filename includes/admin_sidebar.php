<?php
$activeAdminPage = isset($activeAdminPage) ? (string)$activeAdminPage : '';
$adminSidebarCollapsed = isset($adminSidebarCollapsed) ? (bool)$adminSidebarCollapsed : true;

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
            <li><a href="index.php"<?php echo $activeClass(['index']); ?>><img src="../pix/home1.png" alt="Dashboard" style="filter: brightness(0) invert(1);"> Dashboard</a></li>
        </div>

        <div class="menu-group">
            <div class="menu-group-title">User Management</div>
            <li><a href="account_module.php"<?php echo $activeClass(['account_module', 'accounts_view', 'account_management', 'input_form']); ?>><img src="../pix/account.png" alt="User Management"> User Management</a></li>
            <li><a href="adviser_management.php"<?php echo $activeClass(['adviser_management']); ?>><img src="../pix/account.png" alt="Adviser Management"> Adviser Management</a></li>
        </div>

        <div class="menu-group">
            <div class="menu-group-title">Student Management</div>
            <li><a href="pending_accounts.php"<?php echo $activeClass(['pending_accounts']); ?>><img src="../pix/pending.png" alt="Pending"> Pending Accounts</a></li>
            <li><a href="list_of_students.php"<?php echo $activeClass(['list_of_students']); ?>><img src="../pix/checklist.png" alt="Students"> List of Students</a></li>
        </div>

        <div class="menu-group">
            <div class="menu-group-title">System</div>
            <li><a href="programs.php"<?php echo $activeClass(['programs']); ?>><img src="../pix/update.png" alt="Programs"> Programs</a></li>
            <li><a href="../program_coordinator/curriculum_management.php"<?php echo $activeClass(['curriculum_management']); ?>><img src="../pix/curr.png" alt="Curriculum"> Curriculum Management</a></li>
        </div>

        <div class="menu-group">
            <div class="menu-group-title">Approval</div>
            <li><a href="account_approval_settings.php"<?php echo $activeClass(['account_approval_settings']); ?>><img src="../pix/set.png" alt="Settings"> Settings</a></li>
        </div>

        <div class="menu-group">
            <div class="menu-group-title">Account</div>
            <li><a href="logout.php"><img src="../pix/singout.png" alt="Sign Out"> Sign Out</a></li>
        </div>
    </ul>
</div>
