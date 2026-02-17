<?php
// sidebar.php - Reusable admin sidebar
if (!isset($_SESSION['UserName'])) {
    header("Location: login.php");
    exit;
}

$user_role_badge = [
    'admin' => 'bg-danger text-white',
    'staff' => 'bg-warning text-dark', 
    'user' => 'bg-secondary'
][$row['user_role'] ?? 'user'];
?>

<div class="sidebar shadow" style="background: linear-gradient(180deg, #1a2a3a 0%, #2c3e50 100%);">
    <div class="p-3">
        <h4 class="text-white mb-4">
            <i class="fas fa-bed me-2"></i>SCL Dormitory
        </h4>
        
        <!-- User Info Box -->
        <div class="user-info-card bg-white bg-opacity-10 rounded-3 p-3 mb-4">
            <div class="d-flex align-items-center mb-2">
                <div class="flex-grow-1">
                    <small class="text-light opacity-75 d-block mb-1">Logged in as:</small>
                    <strong class="text-white d-block"><?php echo htmlspecialchars($_SESSION['UserName']); ?></strong>
                </div>
                <div>
                    <span class="badge <?php echo $user_role_badge ?? 'bg-secondary'; ?> fs-6 px-2 py-1">
                        <?php echo strtoupper($_SESSION['UserRole'] ?? 'USER'); ?>
                    </span>
                </div>
            </div>
            <?php if(isset($_SESSION['Department'])): ?>
                <small class="text-light opacity-75">
                    <i class="fas fa-building me-1"></i><?php echo htmlspecialchars($_SESSION['Department']); ?>
                </small>
            <?php endif; ?>
        </div>

        <hr class="text-white opacity-25">

        <!-- Navigation -->
        <a href="index.php" class="btn btn-outline-light w-100 mb-2 text-start <?php echo (basename($_SERVER['PHP_SELF'])=='index.php' ? 'active bg-primary bg-opacity-25 border-primary' : ''); ?>">
            <i class="fas fa-tachometer-alt me-2"></i>Dashboard
        </a>
        
        <?php if($_SESSION['UserRole'] == 'admin'): ?>
        <a href="admin_rooms.php" class="btn btn-outline-light w-100 mb-2 text-start <?php echo (strpos($_SERVER['PHP_SELF'], 'admin_rooms')!==false ? 'active bg-primary bg-opacity-25 border-primary' : ''); ?>">
            <i class="fas fa-door-open me-2"></i>Manage Rooms
        </a>
        <a href="admin_users.php" class="btn btn-outline-light w-100 mb-2 text-start <?php echo (strpos($_SERVER['PHP_SELF'], 'admin_users')!==false ? 'active bg-primary bg-opacity-25 border-primary' : ''); ?>">
            <i class="fas fa-users me-2"></i>Manage Users
        </a>
        <?php endif; ?>
        
        <a href="checkout_list.php" class="btn btn-outline-light w-100 mb-2 text-start <?php echo (basename($_SERVER['PHP_SELF'])=='checkout_list.php' ? 'active bg-warning bg-opacity-25 border-warning text-warning' : ''); ?>">
            <i class="fas fa-sign-out-alt me-2"></i>Active Checkouts
        </a>
        
        <div class="mt-auto">
            <hr class="text-white opacity-25 mt-4">
            <a href="logout.php" class="btn btn-danger w-100">
                <i class="fas fa-power-off me-2"></i>Logout
            </a>
        </div>
    </div>
</div>

<style>
.sidebar .btn.active {
    box-shadow: inset 0 0 0 9999px rgba(255,255,255,0.1);
}
.sidebar .user-info-card {
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,0.1);
}
</style>
