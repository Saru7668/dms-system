<?php
session_start();
require_once('db.php');
require_once('header.php');

// Only admin
if (!isset($_SESSION['UserName']) || $_SESSION['UserRole'] !== 'admin') {
    header("Location: index.php");
    exit;
}

$userName = $_SESSION['UserName'];
$userRole = $_SESSION['UserRole'];

$department_list = ["ICT", "HR & Admin", "Accounts & Finance", "Sales & Marketing", "Supply Chain", "Production", "Civil Engineering", "Electrical", "Mechanical", "Glazeline", "Laboratory & Quality Control", "Power & Generation", "Press", "Sorting & Packing", "Squaring & Polishing", "VAT", "Kiln", "Inventory", "Audit", "Brand"];

// Delete user
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if (mysqli_query($conn, "DELETE FROM users WHERE id = $id")) {
        $_SESSION['msg'] = "User deleted successfully!";
        $_SESSION['msg_type'] = "success";
    } else {
        $_SESSION['msg'] = "Delete failed: ".mysqli_error($conn);
        $_SESSION['msg_type'] = "danger";
    }
    header("Location: admin_users.php");
    exit;
}

// Add user with Validation & Mail Notification (Same logic as Register page)
if (isset($_POST['add_user'])) {
    $u_name = trim($_POST['username']);
    $u_email = trim($_POST['email']);
    $u_dept = mysqli_real_escape_string($conn, $_POST['department']);
    $u_role = mysqli_real_escape_string($conn, $_POST['role']);
    $u_pass_plain = $_POST['password'];

    // Validation Regex
    $userRegex = '/^(?=(?:.*[a-zA-Z]){2})(?=.*[0-9])[a-zA-Z0-9]+$/';
    $emailRegex = '/^[^\\\\s@]+@[^\\\\s@]+\\\\.[^\\\\s@]+$/';
    $passComplexRegex = '/^(?=.*[a-zA-Z])(?=.*[0-9])(?=.*[\\\\W_]).+$/';

    // 1. Check Empty
    if (empty($u_name) || empty($u_email) || empty($u_pass_plain) || empty($u_dept)) {
        $_SESSION['msg'] = "All fields are required!";
        $_SESSION['msg_type'] = "danger";
    } 
    // 2. Check Username Format
    elseif (!preg_match($userRegex, $u_name)) {
        $_SESSION['msg'] = "Invalid Username! Need 2 letters & 1 number (No spaces).";
        $_SESSION['msg_type'] = "warning";
    }
    // 3. Check Email Format & Domain
    elseif (!preg_match($emailRegex, $u_email)) {
        $_SESSION['msg'] = "Invalid Email Format!";
        $_SESSION['msg_type'] = "warning";
    }
    elseif (!preg_match('/@(sheltech-bd|sheltechceramics)\\\\./i', $u_email)) {
        $_SESSION['msg'] = "Email domain must be @sheltech-bd or @sheltechceramics!";
        $_SESSION['msg_type'] = "warning";
    }
    // 4. Check Password Complexity
    elseif (!preg_match($passComplexRegex, $u_pass_plain)) {
        $_SESSION['msg'] = "Weak Password! Must contain Letter, Number & Symbol (@, # etc).";
        $_SESSION['msg_type'] = "warning";
    }
    else {
        // 5. Check Duplicate
        $check_sql = "SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1";
        $stmt_check = mysqli_prepare($conn, $check_sql);
        mysqli_stmt_bind_param($stmt_check, "ss", $u_name, $u_email);
        mysqli_stmt_execute($stmt_check);
        $res_check = mysqli_stmt_get_result($stmt_check);

        if (mysqli_num_rows($res_check) > 0) {
            $_SESSION['msg'] = "Username or Email already exists!";
            $_SESSION['msg_type'] = "danger";
        } else {
            // All Good - Insert
            $u_pass_hash = password_hash($u_pass_plain, PASSWORD_DEFAULT);
            
            $sql_insert = "INSERT INTO users (username, password, email, department, user_role, created_at, confirm_token) 
                           VALUES (?, ?, ?, ?, ?, NOW(), 'verified')";
            $stmt_ins = mysqli_prepare($conn, $sql_insert);
            mysqli_stmt_bind_param($stmt_ins, "sssss", $u_name, $u_pass_hash, $u_email, $u_dept, $u_role);

            if (mysqli_stmt_execute($stmt_ins)) {
                // ? Send Mail Notification
                $headers  = "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $headers .= "From: SCL DMS Admin <no-reply@scl-dormitory.com>\r\n";
                
                $subject = "Account Created - SCL Dormitory System";
                $msg  = "Dear $u_name,\r\n\r\n";
                $msg .= "Your account has been successfully created by the Administrator.\r\n\r\n";
                $msg .= "----------------------------------------\r\n";
                $msg .= "Username : $u_name\r\n";
                $msg .= "Password : $u_pass_plain\r\n";
                $msg .= "Role     : " . ucfirst($u_role) . "\r\n";
                $msg .= "----------------------------------------\r\n\r\n";
                $msg .= "Please login at:\r\n";
                $msg .= "http://" . $_SERVER['HTTP_HOST'] . "/dormitory/login.php\r\n\r\n";
                $msg .= "We recommend changing your password after first login.\r\n\r\n";
                $msg .= "Best regards,\r\n";
                $msg .= "SCL Admin Team";
                
                @mail($u_email, $subject, $msg, $headers);
                
                $_SESSION['msg'] = "New user added & mail sent!";
                $_SESSION['msg_type'] = "success";
            } else {
                $_SESSION['msg'] = "Database Error: " . mysqli_error($conn);
                $_SESSION['msg_type'] = "danger";
            }
        }
    }
    header("Location: admin_users.php");
    exit;
}

// ? Handle Role Update (Quick Update)
if (isset($_POST['update_role_btn'])) {
    $uid = (int)$_POST['user_id'];
    $new_role = mysqli_real_escape_string($conn, $_POST['new_role']);
    $u_email = mysqli_real_escape_string($conn, $_POST['user_email']);
    $u_name = mysqli_real_escape_string($conn, $_POST['user_name']);

    if(mysqli_query($conn, "UPDATE users SET user_role='$new_role' WHERE id=$uid")) {
        // ? Send Role Change Mail
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "From: SCL DMS Admin <no-reply@scl-dormitory.com>\r\n";
        
        $subject = "Role Updated - SCL DMS";
        $msg = "Dear $u_name,\r\n\r\nYour account role has been updated to: ".ucfirst($new_role).".\r\nYou now have updated privileges in the system.\r\n\r\nRegards,\r\nAdmin Team";
        
        @mail($u_email, $subject, $msg, $headers);
        $_SESSION['msg'] = "Role updated & notification sent!";
        $_SESSION['msg_type'] = "success";
    } else {
        $_SESSION['msg'] = "Failed to update role!";
        $_SESSION['msg_type'] = "danger";
    }
    header("Location: admin_users.php");
    exit;
}

$users = mysqli_query($conn, "SELECT * FROM users ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Manage Users - SCL DMS</title>
<!-- Essential Meta Tag for Mobile Responsiveness -->
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    body { background: #f8f9fa; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; margin: 0; padding: 0; }
    
    /* ?? Sidebar Styling for Desktop */
    .sidebar { 
        background-color: #1a2332; 
        color: white; 
        min-height: 100vh; 
        padding: 20px; 
        position: fixed; 
        top: 0;
        left: 0;
        width: 280px; 
        z-index: 1000; 
        transition: transform 0.3s ease-in-out;
        box-shadow: 2px 0 10px rgba(0,0,0,0.2);
        overflow-y: auto;
    }
    
    .sidebar-brand { font-size: 1.5rem; font-weight: 500; letter-spacing: 1px; margin-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 15px; }
    
    /* Logged in info box */
    .user-info-box { background-color: #2a3441; border-radius: 8px; padding: 15px; text-align: center; margin-bottom: 25px; }
    .user-info-box small { color: #a0aec0; }
    .user-info-box strong { display: block; font-size: 1.1rem; margin: 5px 0; }
    .badge-role { background-color: #ffc107; color: #000; font-weight: 700; border-radius: 12px; padding: 3px 10px; font-size: 0.75rem; letter-spacing: 0.5px; }
    
    /* Sidebar Buttons */
    .sidebar .btn { 
        text-align: center; 
        border-radius: 6px; 
        margin-bottom: 10px; 
        padding: 10px; 
        font-weight: 500;
        border: 1px solid rgba(255,255,255,0.2);
        display: block;
        width: 100%;
    }
    .sidebar .btn-outline-light { background: transparent; color: white; text-decoration: none; }
    .sidebar .btn-outline-light:hover { background: rgba(255,255,255,0.1); }
    .sidebar .btn-primary { background-color: #0d6efd; border-color: #0d6efd; color: white; text-decoration: none; }
    .sidebar .btn-danger { background-color: #dc3545; border-color: #dc3545; margin-top: 15px; text-decoration: none; }

    /* ?? Mobile Navbar (Hidden on Desktop) */
    .mobile-navbar { 
        display: none; 
        background-color: #1a2332; 
        color: white; 
        padding: 15px 20px; 
        align-items: center; 
        justify-content: space-between; 
        position: sticky; 
        top: 0; 
        z-index: 999; 
        box-shadow: 0 2px 5px rgba(0,0,0,0.2); 
        width: 100%;
    }
    .mobile-navbar h5 { margin: 0; font-size: 1.3rem; letter-spacing: 0.5px; }
    .menu-toggle-btn { background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer; padding: 0; }

    /* Content Area */
    .main-content { margin-left: 280px; padding: 30px; transition: margin-left 0.3s ease-in-out; }
    .main-card { background: white; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); padding: 25px; }
    
    /* Role Badges */
    .role-badge { padding: 5px 10px; border-radius: 5px; font-size: 0.85rem; font-weight: 600; text-transform: capitalize; display: inline-block; }
    .role-admin { background-color: #fde8e8; color: #c81e1e; }
    .role-staff { background-color: #e1effe; color: #1e429f; }
    .role-approver { background-color: #def7ec; color: #03543f; } 
    .role-user { background-color: #f3f4f6; color: #374151; }

    /* Sidebar Overlay for Mobile */
    .sidebar-overlay { 
        display: none; 
        position: fixed; 
        top: 0; 
        left: 0; 
        width: 100vw; 
        height: 100vh; 
        background: rgba(0,0,0,0.6); 
        z-index: 998; 
        opacity: 0;
        transition: opacity 0.3s ease-in-out;
    }

    /* Fade-in Animation */
    .fade-in { animation: fadeIn 0.6s ease-in-out; }
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(15px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* ?? STRICT RESPONSIVE STYLES FOR MOBILE ?? */
    @media screen and (max-width: 768px) {
        .mobile-navbar { display: flex !important; }
        
        /* Hide Sidebar completely off-screen initially */
        .sidebar { 
            transform: translateX(-100%); 
            width: 260px;
        }
        
        /* When active class is added, slide sidebar in */
        .sidebar.active { 
            transform: translateX(0); 
        }
        
        /* Expand main content to full width */
        .main-content { 
            margin-left: 0 !important; 
            padding: 15px !important; 
            width: 100%;
        }
        
        /* Show overlay when active */
        .sidebar-overlay.active { 
            display: block; 
            opacity: 1;
        }

        /* Adjust Table & Card margins for mobile */
        .main-card { padding: 15px !important; }
        .table-responsive { overflow-x: auto; }
        h3 { font-size: 1.5rem; margin-top: 10px; }
    }
</style>
</head>
<body>

<!-- Toast Notification Container -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1055;">
    <div id="liveToast" class="toast align-items-center border-0 shadow" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body fw-bold" id="toastMessage"></div>
            <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close" id="toastCloseBtn"></button>
        </div>
    </div>
</div>

<!-- ?? Mobile Navbar -->
<div class="mobile-navbar">
    <h5>SCL DMS</h5>
    <button class="menu-toggle-btn" id="menuToggleBtn"><i class="fas fa-bars"></i></button>
</div>

<!-- Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ?? Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="d-flex justify-content-between align-items-center d-md-none mb-3">
        <div class="sidebar-brand mb-0 border-0 pb-0">SCL DMS</div>
        <button class="btn btn-sm text-light border-0 p-0 m-0" id="closeSidebarBtn" style="width:auto; border:none !important;"><i class="fas fa-times fa-lg"></i></button>
    </div>
    
    <div class="sidebar-brand d-none d-md-block">SCL DMS</div>
    
    <div class="user-info-box">
        <small>Logged in as:</small>
        <strong><?php echo htmlspecialchars($userName); ?></strong>
        <span class="badge badge-role">ADMIN</span>
    </div>
    
    <a href="index.php" class="btn btn-outline-light w-100">Dashboard</a>
    <a href="admin_rooms.php" class="btn btn-outline-light w-100">Manage Rooms</a>
    <a href="admin_users.php" class="btn btn-primary w-100">Manage Users</a>
    <a href="checkout_list.php" class="btn btn-outline-light w-100">Active Checkouts</a>
    
    <a href="logout.php" class="btn btn-danger w-100">Logout</a>
</div>

<!-- Main Content Area -->
<div class="main-content fade-in">
        
    <?php if(isset($_SESSION['msg'])): ?>
        <?php 
            $toast_msg = $_SESSION['msg'];
            $toast_type = isset($_SESSION['msg_type']) ? $_SESSION['msg_type'] : 'info';
            
            unset($_SESSION['msg']);
            unset($_SESSION['msg_type']);
        ?>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                var toastEl = document.getElementById('liveToast');
                var toastBody = document.getElementById('toastMessage');
                var closeBtn = document.getElementById('toastCloseBtn');
                
                toastEl.classList.remove('bg-success', 'bg-danger', 'bg-warning', 'bg-info', 'text-white', 'text-dark');
                closeBtn.classList.remove('btn-close-white');
                
                if ('<?php echo $toast_type; ?>' === 'warning' || '<?php echo $toast_type; ?>' === 'info') {
                    toastEl.classList.add('bg-<?php echo $toast_type; ?>', 'text-dark');
                } else {
                    toastEl.classList.add('bg-<?php echo $toast_type; ?>', 'text-white');
                    closeBtn.classList.add('btn-close-white');
                }
                
                toastBody.innerHTML = "<?php echo addslashes($toast_msg); ?>";
                var toast = new bootstrap.Toast(toastEl, { delay: 4000 });
                toast.show();
            });
        </script>
    <?php endif; ?>

    <h3 class="fw-bold text-dark mb-0">User Management</h3>
    <hr class="mt-2 mb-4 text-muted">

    <div class="row g-4">
        <!-- Add New User Form -->
        <div class="col-md-4 col-lg-3">
            <div class="main-card h-100">
                <h5 class="text-primary mb-3"><i class="fas fa-user-plus me-2"></i>Add New User</h5>
                <hr class="mt-0 mb-3 text-muted">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Username</label>
                        <input type="text" name="username" class="form-control form-control-sm" placeholder="Min 2 chars & 1 number" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Email</label>
                        <input type="email" name="email" class="form-control form-control-sm" placeholder="@sheltech-bd or @sheltechceramics" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Password</label>
                        <input type="password" name="password" class="form-control form-control-sm" placeholder="Strong password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Department</label>
                        <select name="department" class="form-select form-select-sm" required>
                            <option value="" disabled selected>Select Department</option>
                            <?php foreach ($department_list as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="form-label small fw-bold">Role</label>
                        <select name="role" class="form-select form-select-sm">
                            <option value="user">Normal User (Request Only)</option>
                            <option value="staff">Staff (Booking & Checkout)</option>
                            <option value="approver">Approver (Only Approve)</option> 
                            <option value="admin">Super Admin (Full Access)</option>
                        </select>
                    </div>
                    <button type="submit" name="add_user" class="btn btn-success btn-sm w-100 fw-bold py-2">
                        <i class="fas fa-paper-plane me-1"></i> Add User
                    </button>
                </form>
            </div>
        </div>

        <!-- Existing Users Table -->
        <div class="col-md-8 col-lg-9">
            <div class="main-card h-100">
                <h5 class="mb-3"><i class="fas fa-users me-2"></i>Existing Users <span class="badge bg-secondary ms-2"><?php echo mysqli_num_rows($users); ?></span></h5>
                <div class="table-responsive">
                    <table class="table table-hover mt-2 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Dept</th>
                                <th>Role</th>
                                <th>Quick Role</th> 
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php mysqli_data_seek($users, 0); // Reset pointer if needed ?>
                            <?php while($row = mysqli_fetch_assoc($users)): ?>
                            <tr>
                                <td><span class="fw-bold"><?php echo htmlspecialchars($row['username']); ?></span></td>
                                <td><small class="text-muted"><?php echo htmlspecialchars($row['email']); ?></small></td>
                                <td><small><?php echo htmlspecialchars($row['department']); ?></small></td>
                                <td>
                                    <?php
                                        $roleClass = "role-user";
                                        if($row['user_role'] == 'admin') $roleClass = "role-admin";
                                        elseif($row['user_role'] == 'staff') $roleClass = "role-staff";
                                        elseif($row['user_role'] == 'approver') $roleClass = "role-approver"; 
                                    ?>
                                    <span class="role-badge <?php echo $roleClass; ?>">
                                        <?php echo ucfirst($row['user_role']); ?>
                                    </span>
                                </td>
                                
                                <!-- ? QUICK ROLE CHANGER -->
                                <td>
                                    <?php if($row['username'] !== $userName): ?>
                                    <form method="POST" class="d-flex gap-1 m-0 p-0">
                                        <input type="hidden" name="user_id" value="<?php echo $row['id']; ?>">
                                        <input type="hidden" name="user_email" value="<?php echo $row['email']; ?>">
                                        <input type="hidden" name="user_name" value="<?php echo $row['username']; ?>">
                                        
                                        <select name="new_role" class="form-select form-select-sm" style="width: 105px; display:inline-block;">
                                            <option value="user" <?php if($row['user_role']=='user') echo 'selected'; ?>>User</option>
                                            <option value="staff" <?php if($row['user_role']=='staff') echo 'selected'; ?>>Staff</option>
                                            <option value="approver" <?php if($row['user_role']=='approver') echo 'selected'; ?>>Approver</option>
                                            <option value="admin" <?php if($row['user_role']=='admin') echo 'selected'; ?>>Admin</option>
                                        </select>
                                        <button type="submit" name="update_role_btn" class="btn btn-sm btn-outline-secondary" title="Update Role">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </td>

                                <td class="text-end">
                                    <div class="btn-group" role="group">
                                        <a href="admin_edit_user.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if($row['username'] !== $userName): ?>
                                        <a href="admin_users.php?delete=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this user?')" title="Delete">
                                            <i class="fas fa-trash-alt"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Toggle sidebar logic using plain JavaScript
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const menuBtn = document.getElementById('menuToggleBtn');
        const closeBtn = document.getElementById('closeSidebarBtn');

        function toggleMenu() {
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
            // Prevent scrolling on body when menu is open
            if(sidebar.classList.contains('active')) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = 'auto';
            }
        }

        // Event Listeners
        if(menuBtn) menuBtn.addEventListener('click', toggleMenu);
        if(closeBtn) closeBtn.addEventListener('click', toggleMenu);
        if(overlay) overlay.addEventListener('click', toggleMenu);
    });
</script>
</body>
</html>
