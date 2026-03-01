<?php
session_start();
require_once('db.php');
require_once('header.php');

// Only admin
if (!isset($_SESSION['UserName']) || $_SESSION['UserRole'] !== 'admin') {
    header("Location: index.php");
    exit;
}

$user_id = "";

// Get user data
if (isset($_GET['id'])) {
    $user_id = (int)$_GET['id'];
    $query = mysqli_query($conn, "SELECT * FROM users WHERE id = '$user_id'");
    $user_data = mysqli_fetch_assoc($query);

    if (!$user_data) {
        die("User not found!");
    }
} else {
    header("Location: admin_users.php");
    exit;
}

$departments = ["ICT", "HR & Admin", "Accounts & Finance", "Sales & Marketing", "Supply Chain", "Production", "Civil Engineering", "Electrical", "Mechanical", "Glazeline", "Laboratory & Quality Control", "Power & Generation", "Press", "Sorting & Packing", "Squaring & Polishing", "VAT", "Kiln", "Inventory", "Audit", "Brand"];

// Update logic
if (isset($_POST['update_user'])) {
    $new_username = mysqli_real_escape_string($conn, $_POST['username']);
    $new_email    = mysqli_real_escape_string($conn, $_POST['email']);
    $new_dept     = mysqli_real_escape_string($conn, $_POST['department']);
    $new_role     = mysqli_real_escape_string($conn, $_POST['role']);
    $new_title    = mysqli_real_escape_string($conn, $_POST['title'] ?? '');
    
    if (!empty($_POST['password'])) {
        $new_pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET username=?, title=?, email=?, password=?, department=?, user_role=? WHERE id=?");
        $stmt->bind_param("ssssssi", $new_username, $new_title, $new_email, $new_pass, $new_dept, $new_role, $user_id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET username=?, title=?, email=?, department=?, user_role=? WHERE id=?");
        $stmt->bind_param("sssssi", $new_username, $new_title, $new_email, $new_dept, $new_role, $user_id);
    }

    if ($stmt->execute()) {
        $_SESSION['msg'] = "User updated successfully!";
        $_SESSION['msg_type'] = "success";
        header("Location: admin_users.php");
        exit;
    } else {
        $_SESSION['msg'] = "Update failed: " . $conn->error;
        $_SESSION['msg_type'] = "danger";
        header("Location: admin_edit_user.php?id=" . $user_id);
        exit;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit User - SCL DMS</title>
    <!-- Essential Meta Tag for Mobile Responsiveness -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body { background: #f8f9fa; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; margin: 0; padding: 0; }
        
        /* Sidebar Styling for Desktop */
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
        .role-badge { background-color: #ffc107; color: #000; font-weight: 700; border-radius: 12px; padding: 3px 10px; font-size: 0.75rem; letter-spacing: 0.5px; }
        
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

        /* Mobile Navbar (Hidden on Desktop) */
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
        .edit-card { background: white; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); padding: 30px; }
        .select2-container--default .select2-selection--single { height: 38px; border: 1px solid #dee2e6; display: flex; align-items: center; }
        .breadcrumb-item a { text-decoration: none; color: #2c3e50; }

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
            
            .edit-card { padding: 20px; }
            .d-flex.gap-2 { flex-direction: column; }
            .d-flex.gap-2 button, .d-flex.gap-2 a { width: 100%; margin-bottom: 10px; text-align: center; }
            
            /* Show overlay when active */
            .sidebar-overlay.active { 
                display: block; 
                opacity: 1;
            }
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
        <strong><?php echo htmlspecialchars($_SESSION['UserName']); ?></strong>
        <span class="badge role-badge">ADMIN</span>
    </div>
    
    <a href="index.php" class="btn btn-outline-light w-100">Dashboard</a>
    <a href="admin_rooms.php" class="btn btn-outline-light w-100">Manage Rooms</a>
    <a href="admin_users.php" class="btn btn-primary w-100">Manage Users</a>
    <a href="checkout_list.php" class="btn btn-outline-light w-100">Active Checkouts</a>
    
    <a href="logout.php" class="btn btn-danger w-100">Logout</a>
</div>

<!-- Main Content -->
<div class="main-content">
    <nav aria-label="breadcrumb" class="d-none d-md-block">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="admin_users.php">User Management</a></li>
            <li class="breadcrumb-item active">Edit User Info</li>
        </ol>
    </nav>

    <?php if(isset($_SESSION['msg'])): ?>
        <?php 
            $toast_msg  = $_SESSION['msg'];
            $toast_type = isset($_SESSION['msg_type']) ? $_SESSION['msg_type'] : 'info';
            unset($_SESSION['msg']);
            unset($_SESSION['msg_type']);
        ?>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                var toastEl   = document.getElementById('liveToast');
                var toastBody = document.getElementById('toastMessage');
                var closeBtn  = document.getElementById('toastCloseBtn');

                toastEl.classList.remove('bg-success','bg-danger','bg-warning','bg-info','text-white','text-dark');
                closeBtn.classList.remove('btn-close-white');

                if ('<?php echo $toast_type; ?>' === 'warning') {
                    toastEl.classList.add('bg-warning', 'text-dark');
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

    <div class="row justify-content-center mt-2">
        <div class="col-md-8 col-lg-7">
            <div class="edit-card">
                <h4 class="mb-4 text-primary text-center text-md-start">
                    <i class="fas fa-user-edit me-2"></i> Edit User Information
                </h4>

                <form method="POST">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Title</label>
                            <select name="title" class="form-select" required>
                                <option value="">Select Title</option>
                                <option value="Mr"  <?php echo (($user_data['title'] ?? '') == 'Mr')  ? 'selected' : ''; ?>>Mr</option>
                                <option value="Mrs" <?php echo (($user_data['title'] ?? '') == 'Mrs') ? 'selected' : ''; ?>>Mrs</option>
                                <option value="Ms"  <?php echo (($user_data['title'] ?? '') == 'Ms')  ? 'selected' : ''; ?>>Ms</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control"
                                   value="<?php echo htmlspecialchars($user_data['username'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-control"
                               value="<?php echo htmlspecialchars($user_data['email'] ?? ''); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Department (Searchable)</label>
                        <select name="department" class="form-select select2-dept" required>
                            <option value="">Choose Department...</option>
                            <?php foreach($departments as $dept): ?>
                                <option value="<?php echo $dept; ?>"
                                    <?php echo (($user_data['department'] ?? '') == $dept) ? 'selected' : ''; ?>>
                                    <?php echo $dept; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">User Role</label>
                        <select name="role" class="form-select">
                            <option value="user"     <?php echo ($user_data['user_role'] == 'user')     ? 'selected' : ''; ?>>Normal User</option>
                            <option value="staff"    <?php echo ($user_data['user_role'] == 'staff')    ? 'selected' : ''; ?>>Staff (Semi-Admin)</option>
                            <option value="approver" <?php echo ($user_data['user_role'] == 'approver') ? 'selected' : ''; ?>>Approver</option>
                            <option value="admin"    <?php echo ($user_data['user_role'] == 'admin')    ? 'selected' : ''; ?>>Super Admin</option>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label text-danger fw-bold">Change Password (Optional)</label>
                        <input type="password" name="password" class="form-control" placeholder="Leave blank to keep current password">
                        <small class="text-muted fst-italic">Only fill this if you want to reset the user's password.</small>
                    </div>

                    <div class="d-flex gap-2 border-top pt-3">
                        <button type="submit" name="update_user" class="btn btn-primary px-4 py-2">
                            <i class="fas fa-save me-2"></i> Save Changes
                        </button>
                        <a href="admin_users.php" class="btn btn-secondary px-4 py-2">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Toggle sidebar logic using plain JavaScript to avoid conflicts
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

        // Initialize Select2
        $('.select2-dept').select2({
            placeholder: "Type to search department...",
            allowClear: true,
            width: '100%'
        });
    });
</script>

</body>
</html>
