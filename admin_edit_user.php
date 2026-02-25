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
        // ? Save ???? admin_users.php ?? redirect ????
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body { background: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .sidebar { background: #2c3e50; color: white; min-height: 100vh; padding: 20px; }
        .sidebar h4 { font-size: 1.2rem; font-weight: bold; margin-bottom: 20px; }
        .edit-card { background: white; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); padding: 30px; }
        .select2-container--default .select2-selection--single { height: 38px; border: 1px solid #dee2e6; display: flex; align-items: center; }
        .breadcrumb-item a { text-decoration: none; color: #2c3e50; }

        /* ? ???-?? ?????????? */
        .fade-in {
            animation: fadeIn 0.6s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to   { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

<!-- ? ??????? ????? ????????? ????????? -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1055;">
    <div id="liveToast" class="toast align-items-center border-0 shadow" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body fw-bold" id="toastMessage"></div>
            <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close" id="toastCloseBtn"></button>
        </div>
    </div>
</div>

<div class="container-fluid fade-in">
    <div class="row">
        <div class="col-md-2 sidebar shadow">
            <h4>SCL DMS</h4>
            <div class="mb-4">
                <small class="text-light opacity-75">Logged in as:</small><br>
                <strong><?php echo htmlspecialchars($_SESSION['UserName']); ?></strong>
                <span class="badge bg-warning text-dark d-block mt-1" style="font-size: 10px; width: fit-content;">ADMIN</span>
            </div>
            <hr>
            <a href="index.php" class="btn btn-outline-light w-100 mb-2 text-start">
                <i class="fas fa-tachometer-alt me-2"></i> Dashboard
            </a>
            <a href="admin_rooms.php" class="btn btn-outline-light w-100 mb-2 text-start">
                <i class="fas fa-door-open me-2"></i> Manage Rooms
            </a>
            <a href="admin_users.php" class="btn btn-primary w-100 mb-2 text-start">
                <i class="fas fa-users me-2"></i> Manage Users
            </a>
            <a href="logout.php" class="btn btn-danger w-100 mt-4">
                <i class="fas fa-sign-out-alt me-2"></i> Logout
            </a>
        </div>

        <div class="col-md-10 p-4">
            <nav aria-label="breadcrumb">
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

            <div class="row justify-content-center mt-3">
                <div class="col-md-7">
                    <div class="edit-card">
                        <h4 class="mb-4 text-primary">
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
                                <button type="submit" name="update_user" class="btn btn-primary px-4">
                                    <i class="fas fa-save me-2"></i> Save Changes
                                </button>
                                <a href="admin_users.php" class="btn btn-secondary px-4">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    $(document).ready(function() {
        $('.select2-dept').select2({
            placeholder: "Type to search department...",
            allowClear: true,
            width: '100%'
        });
    });
</script>

</body>
</html>
