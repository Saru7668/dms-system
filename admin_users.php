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

$message = "";
$department_list = ["ICT", "HR & Admin", "Accounts & Finance", "Sales & Marketing", "Supply Chain", "Production", "Civil Engineering", "Electrical", "Mechanical", "Glazeline", "Laboratory & Quality Control", "Power & Generation", "Press", "Sorting & Packing", "Squaring & Polishing", "VAT", "Kiln", "Inventory", "Audit", "Brand"];

// Delete user
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if (mysqli_query($conn, "DELETE FROM users WHERE id = $id")) {
        $message = "<div class='alert alert-success'><i class='fas fa-check-circle'></i> User deleted successfully!</div>";
    } else {
        $message = "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle'></i> Delete failed: ".mysqli_error($conn)."</div>";
    }
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
    $emailRegex = '/^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$/';
    $passComplexRegex = '/^(?=.*[a-zA-Z])(?=.*[0-9])(?=.*[\\W_]).+$/';

    // 1. Check Empty
    if (empty($u_name) || empty($u_email) || empty($u_pass_plain) || empty($u_dept)) {
        $message = "<div class='alert alert-danger'>?? All fields are required!</div>";
    } 
    // 2. Check Username Format
    elseif (!preg_match($userRegex, $u_name)) {
        $message = "<div class='alert alert-danger'>? Invalid Username! Need 2 letters & 1 number (No spaces).</div>";
    }
    // 3. Check Email Format & Domain
    elseif (!preg_match($emailRegex, $u_email)) {
        $message = "<div class='alert alert-danger'>? Invalid Email Format!</div>";
    }
    elseif (!preg_match('/@(sheltech-bd|sheltechceramics)\\./i', $u_email)) {
        $message = "<div class='alert alert-danger'>? Email domain must be @sheltech-bd or @sheltechceramics!</div>";
    }
    // 4. Check Password Complexity
    elseif (!preg_match($passComplexRegex, $u_pass_plain)) {
        $message = "<div class='alert alert-danger'>? Weak Password! Must contain Letter, Number & Symbol (@, # etc).</div>";
    }
    else {
        // 5. Check Duplicate
        $check_sql = "SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1";
        $stmt_check = mysqli_prepare($conn, $check_sql);
        mysqli_stmt_bind_param($stmt_check, "ss", $u_name, $u_email);
        mysqli_stmt_execute($stmt_check);
        $res_check = mysqli_stmt_get_result($stmt_check);

        if (mysqli_num_rows($res_check) > 0) {
            $message = "<div class='alert alert-danger'>? Username or Email already exists!</div>";
        } else {
            // All Good - Insert
            $u_pass_hash = password_hash($u_pass_plain, PASSWORD_DEFAULT);
            // Admin created users are auto-verified (confirm_token = NULL or 'verified')
            // Using 'admin_created' as token to signify auto-verification if needed
            
            $sql_insert = "INSERT INTO users (username, password, email, department, user_role, created_at, confirm_token) 
                           VALUES (?, ?, ?, ?, ?, NOW(), 'verified')";
            $stmt_ins = mysqli_prepare($conn, $sql_insert);
            mysqli_stmt_bind_param($stmt_ins, "sssss", $u_name, $u_pass_hash, $u_email, $u_dept, $u_role);

            if (mysqli_stmt_execute($stmt_ins)) {
                // ? Send Mail Notification
                $headers  = "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $headers .= "From: SCL DMS Admin <no-reply@scl-dormitory.com>\r\n";
                
                $subject = "? Account Created - SCL Dormitory System";
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
                
                $message = "<div class='alert alert-success'><i class='fas fa-check-circle'></i> New user added & mail sent!</div>";
            } else {
                $message = "<div class='alert alert-danger'>? Database Error: " . mysqli_error($conn) . "</div>";
            }
        }
    }
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
        
        $subject = "?? Role Updated - SCL DMS";
        $msg = "Dear $u_name,\r\n\r\nYour account role has been updated to: ".ucfirst($new_role).".\r\nYou now have updated privileges in the system.\r\n\r\nRegards,\r\nAdmin Team";
        
        @mail($u_email, $subject, $msg, $headers);
        $message = "<div class='alert alert-success'><i class='fas fa-info-circle'></i> Role updated & notification sent!</div>";
    }
}

$users = mysqli_query($conn, "SELECT * FROM users ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Manage Users - SCL MRBS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
body { background: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
.sidebar { background: #1a2a3a; color: white; min-height: 100vh; padding: 20px; }
.main-card { background: white; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); padding: 25px; }
.btn-sm { padding: 2px 8px; margin-right: 2px; }
.role-badge {
    padding: 5px 10px;
    border-radius: 5px;
    font-size: 0.85rem;
    font-weight: 600;
    text-transform: capitalize;
    display: inline-block;
}
.role-admin { background-color: #fde8e8; color: #c81e1e; }
.role-staff { background-color: #e1effe; color: #1e429f; }
.role-approver { background-color: #def7ec; color: #03543f; } 
.role-user { background-color: #f3f4f6; color: #374151; }
.badge-role { font-size: 0.75rem; padding: 4px 8px; border-radius: 10px; }
</style>
</head>
<body>

<div class="container-fluid">
<div class="row">
    <div class="col-md-2 sidebar">
        <h4>SCL DMS</h4>
        <hr>

        <!-- Logged in user info -->
        <div class="p-3 mb-4 bg-white bg-opacity-10 rounded text-center">
            <small class="test light">Logged in as:</small><br>
            <strong><?php echo htmlspecialchars($userName); ?></strong><br>
            <span class="badge badge-role bg-warning text-dark">
                <?php echo strtoupper($userRole); ?>
            </span>
        </div>

        <a href="index.php" class="btn btn-outline-light w-100 mb-2">Dashboard</a>
        <a href="admin_rooms.php" class="btn btn-outline-light w-100 mb-2">Manage Rooms</a>
        <a href="admin_users.php" class="btn btn-primary w-100 mb-2">Manage Users</a>
        <a href="checkout_list.php" class="btn btn-outline-light w-100 mb-2">Active Checkouts</a>
        <a href="logout.php" class="btn btn-danger w-100 mt-4">Logout</a>
    </div>

    <div class="col-md-10 p-4">
        <h3>User Management</h3>
        <hr>
        <?php echo $message; ?>

        <div class="row">
            <div class="col-md-4">
                <div class="main-card mb-4">
                    <h5 class="text-primary"><i class="fas fa-user-plus"></i> Add New User</h5>
                    <hr>
                    <form method="POST">
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Username</label>
                            <input type="text" name="username" class="form-control" placeholder="Min 2 chars & 1 number" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Email</label>
                            <input type="email" name="email" class="form-control" placeholder="@sheltech-bd or @sheltechceramics" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Password</label>
                            <input type="password" name="password" class="form-control" placeholder="Strong password" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Department</label>
                            <select name="department" class="form-select" required>
                                <option value="" disabled selected>Select Department</option>
                                <?php foreach ($department_list as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Role</label>
                            <select name="role" class="form-select">
                                <option value="user">Normal User (Request Only)</option>
                                <option value="staff">Staff (Booking & Checkout)</option>
                                <option value="approver">Approver (Only Approve)</option> 
                                <option value="admin">Super Admin (Full Access)</option>
                            </select>
                        </div>
                        <button type="submit" name="add_user" class="btn btn-success w-100 fw-bold">
                            <i class="fas fa-paper-plane me-1"></i> Add User & Notify
                        </button>
                    </form>
                </div>
            </div>

            <div class="col-md-8">
                <div class="main-card">
                    <h5><i class="fas fa-users"></i> Existing Users</h5>
                    <div class="table-responsive">
                    <table class="table table-striped mt-3 align-middle">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Dept</th>
                                <th>Role</th>
                                <th>Quick Role</th> 
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = mysqli_fetch_assoc($users)): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['username']); ?></td>
                                <td><small><?php echo htmlspecialchars($row['email']); ?></small></td>
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
                                    <form method="POST" class="d-flex gap-1">
                                        <input type="hidden" name="user_id" value="<?php echo $row['id']; ?>">
                                        <input type="hidden" name="user_email" value="<?php echo $row['email']; ?>">
                                        <input type="hidden" name="user_name" value="<?php echo $row['username']; ?>">
                                        
                                        <select name="new_role" class="form-select form-select-sm" style="width: 100px;">
                                            <option value="user" <?php if($row['user_role']=='user') echo 'selected'; ?>>User</option>
                                            <option value="staff" <?php if($row['user_role']=='staff') echo 'selected'; ?>>Staff</option>
                                            <option value="approver" <?php if($row['user_role']=='approver') echo 'selected'; ?>>Approver</option>
                                            <option value="admin" <?php if($row['user_role']=='admin') echo 'selected'; ?>>Admin</option>
                                        </select>
                                        <button type="submit" name="update_role_btn" class="btn btn-sm btn-outline-dark" title="Update & Notify">
                                            <i class="fas fa-save"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <a href="admin_edit_user.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php if($row['username'] !== $userName): ?>
                                    <a href="admin_users.php?delete=<?php echo $row['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                    <?php endif; ?>
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
</div>
</div>

</body>
</html>
