<?php
session_start();
require_once('db.php');
require_once('header.php');

// Login Check
if (!isset($_SESSION['UserName'])) {
    header("Location: login.php");
    exit;
}

$user = $_SESSION['UserName'];
$role = $_SESSION['UserRole'] ?? 'user';
$message = "";

// Department List
$dept_list = ["ICT", "HR & Admin", "Accounts & Finance", "Sales & Marketing", "Supply Chain", "Production", "Civil Engineering", "Electrical", "Mechanical", "Glazeline", "Laboratory & Quality Control", "Power & Generation", "Press", "Sorting & Packing", "Squaring & Polishing", "VAT", "Kiln", "Inventory", "Audit", "Brand", "Other"];

// ========================================
// ������️ HANDLE DELETE LOGIC (Only if Pending)
// ========================================
if (isset($_GET['delete_id'])) {
    $del_id = (int)$_GET['delete_id'];
    
    // Fetch info before delete for email
    $check_sql = "
        SELECT r.*, u.email as user_email 
        FROM visit_requests r
        JOIN users u ON r.requested_by = u.username
        WHERE r.id = $del_id AND r.requested_by = '$user' AND r.status = 'Pending'
    ";
    $check_res = mysqli_query($conn, $check_sql);
    
    if ($check_res && mysqli_num_rows($check_res) > 0) {
        $del_data = mysqli_fetch_assoc($check_res);
        
        $guest_name   = $del_data['guest_name'];
        $check_in     = $del_data['check_in_date'];
        $user_email   = $del_data['user_email'];

        // Delete Query
        if (mysqli_query($conn, "DELETE FROM visit_requests WHERE id = $del_id")) {
            
            // Mail Headers
            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $headers .= "From: SCL Dormitory <no-reply@scl-dormitory.com>\r\n";
            
            // 1. Mail to Requester
            if (!empty($user_email)) {
                $subj_user = "������️ Request Withdrawn Successfully - Ref #$del_id";
                $msg_user  = "Dear $user,\r\n\r\n";
                $msg_user .= "This email confirms that you have successfully WITHDRAWN/DELETED your visit request.\r\n\r\n";
                $msg_user .= "DETAILS OF DELETED REQUEST\r\n";
                $msg_user .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\r\n";
                $msg_user .= "Ref ID           : #$del_id\r\n";
                $msg_user .= "Guest Name       : $guest_name\r\n";
                $msg_user .= "Check-in Date    : $check_in\r\n";
                $msg_user .= "Status           : ❌ Deleted by You\r\n";
                $msg_user .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\r\n\r\n";
                $msg_user .= "No further action is required.\r\n\r\n";
                $msg_user .= "Best Regards\n";
                $msg_user .= "SCL Dormitory Management System";
                @mail($user_email, $subj_user, $msg_user, $headers);
            }

            // 2. Mail to Admin
            $admin_sql = "SELECT email FROM users WHERE user_role IN ('approver', 'admin', 'superadmin') AND email IS NOT NULL AND email != ''";
            $admin_res = mysqli_query($conn, $admin_sql);
            
            if ($admin_res) {
                $subj_admin = "������️ Request Withdrawn: Ref #$del_id by $user";
                $msg_admin  = "SCL DORMITORY SYSTEM - WITHDRAWAL NOTIFICATION\r\n";
                $msg_admin .= "==================================================\r\n\r\n";
                $msg_admin .= "Dear Authorization Team,\r\n\r\n";
                $msg_admin .= "A pending visit request has been DELETED/WITHDRAWN by the requester ($user).\r\n";
                $msg_admin .= "No further action is required for this request.\r\n\r\n";
                $msg_admin .= "❌ DELETED REQUEST DETAILS\r\n";
                $msg_admin .= "--------------------------------------------------\r\n";
                $msg_admin .= "Ref ID           : #$del_id\r\n";
                $msg_admin .= "Guest Name       : $guest_name\r\n";
                $msg_admin .= "Check-in Date    : $check_in\r\n";
                $msg_admin .= "Deleted By       : $user\r\n";
                $msg_admin .= "--------------------------------------------------\r\n\r\n";
                $msg_admin .= "This is an automated notification for your records.\r\n\r\n";
                $msg_admin .= "Best Regards\n";
                $msg_admin .= "SCL Dormitory Management System";
    
                while ($admin_row = mysqli_fetch_assoc($admin_res)) {
                    if (!empty($admin_row['email'])) {
                        @mail($admin_row['email'], $subj_admin, $msg_admin, $headers);
                    }
                }
            }
            $message = "<div class='alert alert-danger'>������️ Request deleted successfully. Confirmation email sent.</div>";
        } else {
            $message = "<div class='alert alert-danger'>❌ Delete failed: " . mysqli_error($conn) . "</div>";
        }
    } else {
        $message = "<div class='alert alert-warning'>⚠️ Delete Failed: Request not found or already processed.</div>";
    }
}

// ========================================
// ������ HANDLE EDIT/RESUBMIT LOGIC
// ========================================
if (isset($_POST['update_request'])) {
    $req_id = (int)$_POST['req_id'];
    
    // Sanitize Inputs
    $new_guest_name = mysqli_real_escape_string($conn, $_POST['guest_name']);
    $new_phone      = mysqli_real_escape_string($conn, $_POST['phone']);
    $new_email      = mysqli_real_escape_string($conn, $_POST['email']);
    $new_desig      = mysqli_real_escape_string($conn, $_POST['designation']);
    $new_address    = mysqli_real_escape_string($conn, $_POST['address']);
    $new_id_proof   = mysqli_real_escape_string($conn, $_POST['id_proof']);
    $new_emergency  = mysqli_real_escape_string($conn, $_POST['emergency_contact']);
    $new_dept       = mysqli_real_escape_string($conn, $_POST['department']);
    $new_check_in   = mysqli_real_escape_string($conn, $_POST['check_in_date']);
    $new_purpose    = mysqli_real_escape_string($conn, $_POST['purpose']);

    $old_sql = "SELECT * FROM visit_requests WHERE id = $req_id AND requested_by = '$user' AND status = 'Pending'";
    $old_res = mysqli_query($conn, $old_sql);
    
    if ($old_res && mysqli_num_rows($old_res) > 0) {
        $old_data = mysqli_fetch_assoc($old_res);
        
        $update_sql = "UPDATE visit_requests SET 
            guest_name = '$new_guest_name',
            phone = '$new_phone',
            email = '$new_email',
            designation = '$new_desig',
            address = '$new_address',
            id_proof = '$new_id_proof',
            emergency_contact = '$new_emergency',
            department = '$new_dept',
            check_in_date = '$new_check_in',
            purpose = '$new_purpose'
            WHERE id = $req_id";
            
        if (mysqli_query($conn, $update_sql)) {

            // --- Mail headers (real CRLF) ---
            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $headers .= "From: SCL Dormitory <no-reply@scl-dormitory.com>\r\n";

            // 1) Guest mail (তোমার আগের ফরম্যাট রেখে শুধু \r\n করা)
            if (!empty($new_email)) {
                $subj_guest = "ℹ️ Request Updated - Ref #$req_id";
                $msg_guest  = "Dear $new_guest_name,\r\n\r\n";
                $msg_guest .= "Your visit request (Ref #$req_id) details have been updated by the requester ($user).\r\n";
                $msg_guest .= "Status: Still Pending Approval.\r\n\r\n";
                $msg_guest .= "Best Regards\r\n";
                $msg_guest .= "SCL Dormitory Team";
                @mail($new_email, $subj_guest, $msg_guest, $headers);
            }

            // 2) Admin / Approver mail + CHANGE LOG
            $admin_sql = "SELECT email FROM users WHERE user_role IN ('approver', 'admin', 'superadmin') AND email IS NOT NULL AND email != ''";
            $admin_res = mysqli_query($conn, $admin_sql);
            
            if ($admin_res) {
                $subj_admin = "✏️ Request #$req_id Edited by Requester";
                $msg_admin  = "SCL DORMITORY SYSTEM - UPDATE NOTIFICATION\r\n";
                $msg_admin .= "==================================================\r\n\r\n";
                $msg_admin .= "Dear Authorization Team,\r\n\r\n";
                $msg_admin .= "A pending visit request has been EDITED by the requester ($user).\r\n";
                $msg_admin .= "Please review the updated details.\r\n\r\n";
                $msg_admin .= "������ CHANGE LOG\r\n";
                $msg_admin .= "--------------------------------------------------\r\n";

                // ======= GENERIC CHANGE LOG (সব ফিল্ড) =======
                $field_labels = [
                    'guest_name'        => 'Name',
                    'phone'             => 'Phone',
                    'email'             => 'Email',
                    'designation'       => 'Designation',
                    'address'           => 'Address',
                    'id_proof'          => 'ID Proof',
                    'emergency_contact' => 'Emergency Contact',
                    'department'        => 'Dept',
                    'check_in_date'     => 'Date',
                    'purpose'           => 'Purpose',
                ];

                $new_values = [
                    'guest_name'        => $new_guest_name,
                    'phone'             => $new_phone,
                    'email'             => $new_email,
                    'designation'       => $new_desig,
                    'address'           => $new_address,
                    'id_proof'          => $new_id_proof,
                    'emergency_contact' => $new_emergency,
                    'department'        => $new_dept,
                    'check_in_date'     => $new_check_in,
                    'purpose'           => $new_purpose,
                ];

                $changes = false;

                foreach ($field_labels as $col => $label) {
                    $oldVal = isset($old_data[$col]) ? trim($old_data[$col]) : '';
                    $newVal = isset($new_values[$col]) ? trim($new_values[$col]) : '';

                    if ($oldVal === $newVal) {
                        continue;
                    }

                    $changes = true;

                    if ($col === 'check_in_date') {
                        $oldShow = $oldVal ? date('d M Y', strtotime($oldVal)) : 'Not set';
                        $newShow = $newVal ? date('d M Y', strtotime($newVal)) : 'Not set';
                    } else {
                        $oldShow = ($oldVal === '') ? '(empty)' : $oldVal;
                        $newShow = ($newVal === '') ? '(empty)' : $newVal;
                    }

                    // এক লাইনে old -> new, তোমার আগের স্টাইলেই
                    $msg_admin .= "$label: $oldShow -> $newShow\r\n";
                }

                if(!$changes) {
                    $msg_admin .= "(Minor details updated)\r\n";
                }
                
                $msg_admin .= "--------------------------------------------------\r\n\r\n";
                $msg_admin .= "������ Please review and take action from dashboard.\r\n";
                $msg_admin .= "http://" . $_SERVER['HTTP_HOST'] . "/manage_requests.php\r\n\r\n";
                $msg_admin .= "SCL Admin Notification";

                while ($admin_row = mysqli_fetch_assoc($admin_res)) {
                    if (!empty($admin_row['email'])) {
                        @mail($admin_row['email'], $subj_admin, $msg_admin, $headers);
                    }
                }
            }
            $message = "<div class='alert alert-success'>✅ Request updated successfully! Admin notified.</div>";
        } else {
            $message = "<div class='alert alert-danger'>❌ Error updating request: " . mysqli_error($conn) . "</div>";
        }
    } else {
        $message = "<div class='alert alert-warning'>⚠️ Cannot edit! Request might be already processed.</div>";
    }
}


// Fetch User Requests
$my_req_sql = "
    SELECT r.*, u.full_name AS approver_name
    FROM visit_requests r
    LEFT JOIN users u ON r.approved_by = u.username
    WHERE r.requested_by = '".mysqli_real_escape_string($conn, $user)."'
    ORDER BY r.id DESC
";
$my_req_result = mysqli_query($conn, $my_req_sql);

// Sidebar Badge
$pending_count = 0;
if(in_array($role, ['admin', 'superadmin', 'approver'])){
    $pending_query = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM visit_requests WHERE status='Pending'");
    if($pending_query) {
        $pending_count = mysqli_fetch_assoc($pending_query)['cnt'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Requests</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .sidebar { background: #1a2a3a; color: white; height: 100vh; position: fixed; width: 250px; padding: 20px; overflow-y: auto; z-index: 1000; }
        .content { margin-left: 250px; padding: 30px; }
        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; }
            .content { margin-left: 0; }
        }
    </style>
</head>
<body>

<div class="sidebar">
    <h4 class="mb-4 text-center"><i class="fas fa-hotel me-2"></i>SCL DMS</h4>
    <div class="p-3 mb-4 bg-white bg-opacity-10 rounded text-center">
        <small>Welcome,</small><br><strong><?php echo htmlspecialchars($user); ?></strong><br>
        <span class="badge bg-warning text-dark mt-1"><?php echo strtoupper($role); ?></span>
    </div>
    
    <a href="index.php" class="btn btn-outline-light w-100 mb-2"><i class="fas fa-home me-2"></i>Dashboard</a>
    <a href="guest_request.php" class="btn btn-outline-info w-100 mb-2 text-white"><i class="fas fa-paper-plane me-2"></i>Submit Request</a>
    <a href="my_requests.php" class="btn btn-info w-100 mb-2 text-dark fw-bold"><i class="fas fa-list-alt me-2"></i>My Sent Requests</a>

    <?php if(in_array($role, ['admin', 'superadmin', 'approver'])): ?>
        <a href="manage_requests.php" class="btn btn-outline-warning w-100 mb-2 position-relative text-white">
            <i class="fas fa-tasks me-2"></i>Manage All 
            <?php if($pending_count > 0): ?>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                    <?php echo $pending_count; ?>
                </span>
            <?php endif; ?>
        </a>
    <?php endif; ?>

    <?php if(in_array($role, ['staff', 'admin', 'superadmin'])): ?>
        <a href="checkout_list.php" class="btn btn-outline-light w-100 mb-2"><i class="fas fa-clipboard-check me-2"></i>Check-out</a>
        <a href="checkout_history.php" class="btn btn-outline-light w-100 mb-2"><i class="fas fa-history me-2"></i>History</a>
    <?php endif; ?>
    
    <?php if(in_array($role, ['admin', 'superadmin'])): ?>
        <hr class="border-light">
        <a href="admin_dashboard.php" class="btn btn-warning w-100 mb-2"><i class="fas fa-crown me-2"></i>Admin Panel</a>
    <?php endif; ?>
    <a href="logout.php" class="btn btn-danger w-100 mt-4"><i class="fas fa-sign-out-alt me-2"></i>Logout</a>
</div>

<div class="content">
    <?php echo $message; ?>

    <div class="card shadow border-0">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-user-clock me-2"></i>My Visit Requests History</h5>
            <span class="badge bg-light text-dark"><?php echo mysqli_num_rows($my_req_result); ?> Records</span>
        </div>

        <div class="card-body">
            <?php if(mysqli_num_rows($my_req_result) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                      <tr>
                          <th>Ref ID</th>
                          <th>Guest Info</th>
                          <th>Dept & Purpose</th>
                          <th>Check-in / Check-out</th>
                          <th>Status</th>
                          <th>Approved By</th>   <!-- নতুন -->
                          <th>Action</th>
                      </tr>
                   </thead>
                    <tbody>
                        <?php while($row = mysqli_fetch_assoc($my_req_result)): ?>
                        <tr>
                            <?php
                                // Title + Name prepare
                                $guest_title = htmlspecialchars($row['guest_title'] ?? '', ENT_QUOTES, 'UTF-8');
                                $guest_name  = htmlspecialchars($row['guest_name'] ?? '', ENT_QUOTES, 'UTF-8');
                        
                                // Final display name (Mr Shalah Uddin Ahamed)
                                $display_name = trim($guest_title . ' ' . $guest_name);
                        
                                // আগের মত date/time prepare নিচে যেভাবে ছিল, 그대로 থাকবে
                                $cinDate  = !empty($row['check_in_date'])
                                    ? date('d M Y', strtotime($row['check_in_date']))
                                    : '-';
                        
                                $cinTime  = (!empty($row['check_in_time']) && $row['check_in_time'] != '00:00:00')
                                    ? date('h:i A', strtotime($row['check_in_time']))
                                    : '';
                        
                                $coutDate = !empty($row['check_out_date'])
                                    ? date('d M Y', strtotime($row['check_out_date']))
                                    : '-';
                        
                                $coutTime = (!empty($row['check_out_time']) && $row['check_out_time'] != '00:00:00')
                                    ? date('h:i A', strtotime($row['check_out_time']))
                                    : '';
                            ?>
                            <td><strong>#<?php echo $row['id']; ?></strong></td>
                            <td>
                                <strong><?php echo $display_name; ?></strong><br>
                                <small class="text-muted"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($row['email']); ?></small>
                            <td>
                                <span class="badge bg-secondary"><?php echo $row['department']; ?></span><br>
                                <small><?php echo htmlspecialchars($row['purpose']); ?></small>
                            </td>
                            <td>
                                    <div>
                                        <strong>In:</strong>
                                        <?php echo $cinDate; ?>
                                        <?php if ($cinTime !== ''): ?>
                                            <span class="badge bg-info text-dark ms-1">
                                                <?php echo $cinTime; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                
                                    <div class="mt-1">
                                        <strong>Out:</strong>
                                        <?php echo $coutDate; ?>
                                        <?php if ($coutTime !== ''): ?>
                                            <span class="badge bg-secondary text-light ms-1">
                                                <?php echo $coutTime; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                <?php 
                                    // ������ FIX: Treat 'Booked' and 'Completed' as 'Approved'
                                    $s = $row['status'];
                                    
                                    if ($s == 'Pending') {
                                        echo "<span class='badge bg-warning'>Pending</span>";
                                    } elseif ($s == 'Rejected') {
                                        echo "<span class='badge bg-danger'>Rejected</span>";
                                    } else {
                                        // For Approved, Booked, Completed -> Show Approved (Green)
                                        echo "<span class='badge bg-success'>Approved</span>";
                                    }
                                ?>
                            </td>
                            <!-- ������ নতুন Approved By কলাম -->
                            <td>
                                <?php
                                    if ($row['status'] == 'Pending' || $row['status'] == 'Rejected') {
                                        echo "<span class='text-muted small'>-</span>";
                                    } else {
                                        // যদি full_name না থাকে, fallback হিসেবে approved_by দেখাই
                                        $name = $row['approver_name'] ?: $row['approved_by'];
                                        echo htmlspecialchars($name ?: '-');
                                    }
                                ?>
                            </td>
                            <td>
                                <?php if($row['status'] == 'Pending'): ?>
                                    <!-- EDIT Button -->
                                    <button class="btn btn-sm btn-primary me-1" 
                                        data-bs-toggle="modal" data-bs-target="#editModal"
                                        data-id="<?php echo $row['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($row['guest_name']); ?>"
                                        data-email="<?php echo htmlspecialchars($row['email']); ?>"
                                        data-phone="<?php echo htmlspecialchars($row['phone']); ?>"
                                        data-dept="<?php echo htmlspecialchars($row['department']); ?>"
                                        data-purpose="<?php echo htmlspecialchars($row['purpose']); ?>"
                                        data-date="<?php echo $row['check_in_date']; ?>"
                                        data-desig="<?php echo htmlspecialchars($row['designation']); ?>"
                                        data-addr="<?php echo htmlspecialchars($row['address']); ?>"
                                        data-idproof="<?php echo htmlspecialchars($row['id_proof']); ?>"
                                        data-emergency="<?php echo htmlspecialchars($row['emergency_contact']); ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>

                                    <!-- DELETE Button (Red) -->
                                    <a href="my_requests.php?delete_id=<?php echo $row['id']; ?>" 
                                       class="btn btn-sm btn-danger"
                                       onclick="return confirm('⚠️ Are you sure - you want to withdraw this request?\nThis will remove it permanently and notify the admin team.')">
                                       <i class="fas fa-trash-alt"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted small"><i class="fas fa-lock"></i> Locked</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <h5 class="text-muted">No requests found.</h5>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ������ FULL EDIT MODAL (No Change) -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Request Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <div class="modal-body">
            <input type="hidden" name="req_id" id="edit_id">
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold">Guest Name</label>
                    <input type="text" name="guest_name" id="edit_name" class="form-control" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold">Phone</label>
                    <input type="text" name="phone" id="edit_phone" class="form-control" required>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold">Email</label>
                    <input type="email" name="email" id="edit_email" class="form-control" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold">Designation</label>
                    <input type="text" name="designation" id="edit_desig" class="form-control" required>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold">Address</label>
                    <input type="text" name="address" id="edit_addr" class="form-control">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold">ID Proof</label>
                    <input type="text" name="id_proof" id="edit_idproof" class="form-control" required>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold">Department</label>
                    <select name="department" id="edit_dept" class="form-select">
                        <?php foreach($dept_list as $d) echo "<option value='$d'>$d</option>"; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold">Emergency Contact</label>
                    <input type="text" name="emergency_contact" id="edit_emergency" class="form-control">
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold">Check-in Date</label>
                    <input type="date" name="check_in_date" id="edit_date" class="form-control" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold">Purpose</label>
                    <textarea name="purpose" id="edit_purpose" class="form-control" rows="1"></textarea>
                </div>
            </div>

            <div class="alert alert-warning small">
                <i class="fas fa-exclamation-triangle"></i> Note: Editing will notify the admin team and the guest.
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" name="update_request" class="btn btn-success fw-bold">Update & Notify</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    var editModal = document.getElementById('editModal');
    editModal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        
        document.getElementById('edit_id').value = button.getAttribute('data-id');
        document.getElementById('edit_name').value = button.getAttribute('data-name');
        document.getElementById('edit_email').value = button.getAttribute('data-email');
        document.getElementById('edit_phone').value = button.getAttribute('data-phone');
        document.getElementById('edit_dept').value = button.getAttribute('data-dept');
        document.getElementById('edit_purpose').value = button.getAttribute('data-purpose');
        document.getElementById('edit_date').value = button.getAttribute('data-date');
        document.getElementById('edit_desig').value = button.getAttribute('data-desig');
        document.getElementById('edit_addr').value = button.getAttribute('data-addr');
        document.getElementById('edit_idproof').value = button.getAttribute('data-idproof');
        document.getElementById('edit_emergency').value = button.getAttribute('data-emergency');
    });
</script>

</body>
</html>
