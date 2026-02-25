<?php
session_start();
require_once('db.php');
require_once('header.php');

if (!isset($_SESSION['UserName'])) {
    header("Location: login.php");
    exit;
}

$user = $_SESSION['UserName'];
$role = $_SESSION['UserRole'] ?? 'user';
$message = "";

$dept_list = ["ICT","HR & Admin","Accounts & Finance","Sales & Marketing","Supply Chain","Production","Civil Engineering","Electrical","Mechanical","Glazeline","Laboratory & Quality Control","Power & Generation","Press","Sorting & Packing","Squaring & Polishing","VAT","Kiln","Inventory","Audit","Brand","Other"];

// ========================================
// Ì†ΩÌ¥ô MOVE TO DRAFT LOGIC
// ========================================
if (isset($_GET['move_to_draft_id'])) {
    $draft_id = (int)$_GET['move_to_draft_id'];

    $check_sql = "SELECT r.*, u.email as user_email FROM visit_requests r JOIN users u ON r.requested_by = u.username WHERE r.id = $draft_id AND r.requested_by = '".mysqli_real_escape_string($conn, $user)."' AND r.status = 'Pending'";
    $check_res = mysqli_query($conn, $check_sql);

    if ($check_res && mysqli_num_rows($check_res) > 0) {
        $data = mysqli_fetch_assoc($check_res);
        $guest_name = $data['guest_name'];

        if (mysqli_query($conn, "UPDATE visit_requests SET status = 'Draft' WHERE id = $draft_id")) {
            
            // Send Email to Admins/Approvers
            $admin_sql = "SELECT email FROM users WHERE user_role IN ('approver','admin','superadmin') AND email IS NOT NULL AND email != ''";
            $admin_res = mysqli_query($conn, $admin_sql);
            if ($admin_res && mysqli_num_rows($admin_res) > 0) {
                $headers  = "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $headers .= "From: SCL Dormitory <no-reply@scl-dormitory.com>\r\n";
                
                $subj_a = "‚ö†Ô∏è Request Moved to Draft: Ref #$draft_id by $user";
                $body_a = "Dear Authorization Team,\r\n\r\n";
                $body_a .= "The following pending request has been MOVED BACK TO DRAFT by the user ($user) for updates.\r\n";
                $body_a .= "Please do not process it until it is resubmitted.\r\n\r\n";
                $body_a .= "Ref ID       : #$draft_id\r\nGuest Name   : $guest_name\r\n";
                $body_a .= "Action By    : $user\r\n\r\n";
                $body_a .= "SCL Dormitory Management System";

                while ($ar = mysqli_fetch_assoc($admin_res)) {
                    if (!empty($ar['email'])) @mail($ar['email'], $subj_a, $body_a, $headers);
                }
            }
            $message = "<div class='alert alert-warning'>Ì†ΩÌ¥ô Request moved to Draft. You can now edit and resubmit it. Admin has been notified.</div>";
        } else {
            $message = "<div class='alert alert-danger'>‚ùå Failed to move to Draft.</div>";
        }
    }
}

// ========================================
// Ì†ΩÌ∑ëÔ∏è DELETE ‚Äî Pending ‡¶Ö‡¶•‡¶¨‡¶æ Draft ‡¶¶‡ßÅ‡¶ü‡ßã‡¶§‡ßá‡¶á ‡¶ï‡¶æ‡¶ú ‡¶ï‡¶∞‡¶¨‡ßá
// ========================================
if (isset($_GET['delete_id'])) {
    $del_id = (int)$_GET['delete_id'];

    $check_sql = "
        SELECT r.*, u.email as user_email 
        FROM visit_requests r
        JOIN users u ON r.requested_by = u.username
        WHERE r.id = $del_id 
          AND r.requested_by = '".mysqli_real_escape_string($conn,$user)."'
          AND r.status IN ('Pending','Draft')
    ";
    $check_res = mysqli_query($conn, $check_sql);

    if ($check_res && mysqli_num_rows($check_res) > 0) {
        $del_data   = mysqli_fetch_assoc($check_res);
        $del_status = $del_data['status'];
        $guest_name = $del_data['guest_name'];
        $check_in   = $del_data['check_in_date'];
        $user_email = $del_data['user_email'];

        mysqli_query($conn, "DELETE FROM visit_guests WHERE request_id = $del_id");

        if (mysqli_query($conn, "DELETE FROM visit_requests WHERE id = $del_id")) {

            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $headers .= "From: SCL Dormitory <no-reply@scl-dormitory.com>\r\n";

            if ($del_status === 'Draft') {
                if (!empty($user_email)) {
                    $subj = "Ì†ΩÌ∑ëÔ∏è Draft Deleted - Ref #$del_id";
                    $body = "Dear $user,\r\n\r\nYour draft visit request (Ref #$del_id) has been deleted.\r\n\r\n";
                    $body .= "Ref ID       : #$del_id\r\nGuest Name   : $guest_name\r\n";
                    $body .= "Check-in Date: $check_in\r\nStatus       : Draft (Deleted)\r\n\r\n";
                    $body .= "Best Regards\r\nSCL Dormitory Management System";
                    @mail($user_email, $subj, $body, $headers);
                }
                $message = "<div class='alert alert-info'>Ì†ΩÌ∑ëÔ∏è Draft deleted successfully.</div>";
            } else {
                if (!empty($user_email)) {
                    $subj_u = "Ì†ΩÌ∑ëÔ∏è Request Withdrawn - Ref #$del_id";
                    $body_u = "Dear $user,\r\n\r\nYour visit request (Ref #$del_id) has been withdrawn.\r\n\r\n";
                    $body_u .= "Ref ID       : #$del_id\r\nGuest Name   : $guest_name\r\n";
                    $body_u .= "Check-in Date: $check_in\r\nStatus       : ‚ùå Deleted by You\r\n\r\n";
                    $body_u .= "Best Regards\r\nSCL Dormitory Management System";
                    @mail($user_email, $subj_u, $body_u, $headers);
                }

                $admin_sql = "SELECT email FROM users WHERE user_role IN ('approver','admin','superadmin') AND email IS NOT NULL AND email != ''";
                $admin_res = mysqli_query($conn, $admin_sql);
                if ($admin_res) {
                    $subj_a = "Ì†ΩÌ∑ëÔ∏è Request Withdrawn: Ref #$del_id by $user";
                    $body_a = "Dear Authorization Team,\r\n\r\n";
                    $body_a .= "A pending request has been WITHDRAWN by $user.\r\n\r\n";
                    $body_a .= "Ref ID       : #$del_id\r\nGuest Name   : $guest_name\r\n";
                    $body_a .= "Check-in Date: $check_in\r\nDeleted By   : $user\r\n\r\n";
                    $body_a .= "SCL Dormitory Management System";
                    while ($ar = mysqli_fetch_assoc($admin_res)) {
                        if (!empty($ar['email'])) @mail($ar['email'], $subj_a, $body_a, $headers);
                    }
                }
                $message = "<div class='alert alert-danger'>Ì†ΩÌ∑ëÔ∏è Request withdrawn. Confirmation email sent.</div>";
            }
        } else {
            $message = "<div class='alert alert-danger'>‚ùå Delete failed: ".mysqli_error($conn)."</div>";
        }
    } else {
        $message = "<div class='alert alert-warning'>‚ö†Ô∏è Request not found or already processed.</div>";
    }
}

// ========================================
// Ì†ΩÌ≥ä FETCH ALL REQUESTS WITH APPROVER NAME
// ========================================
$my_req_sql = "
    SELECT r.*, u.full_name AS approver_name
    FROM visit_requests r
    LEFT JOIN users u ON r.approved_by = u.username
    WHERE r.requested_by = '".mysqli_real_escape_string($conn, $user)."'
    ORDER BY r.id DESC
";
$my_req_result = mysqli_query($conn, $my_req_sql);

$pending_count = 0;
if (in_array($role, ['admin','superadmin','approver'])) {
    $pq = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM visit_requests WHERE status='Pending'");
    if ($pq) $pending_count = mysqli_fetch_assoc($pq)['cnt'];
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
        
        /* Ì†ºÌºü Fade-in Animation */
        .page-fade-in { animation: fadeIn 0.6s ease-in-out forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        /* Ì†ºÌºü Floating Toast Notifications */
        .toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; }
        .custom-toast { min-width: 320px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); border: none; border-radius: 8px; overflow: hidden; }

        .sidebar { background: #1a2a3a; color: white; height: 100vh; position: fixed; width: 250px; padding: 20px; overflow-y: auto; z-index: 1000; }
        .content { margin-left: 250px; padding: 30px; }
        .guest-item { border-left: 3px solid #0d6efd; padding-left: 10px; margin-bottom: 10px; }
        tbody tr { transition: background-color 0.2s ease; }
        tbody tr:hover { background-color: #f1f8ff !important; }
        @media (max-width: 768px) { .sidebar { width:100%; height:auto; position:relative; } .content { margin-left:0; } }
    </style>
</head>
<body class="page-fade-in">

<!-- Ì†ºÌºü Floating Toast Notifications -->
<div class="toast-container" id="toastBox">
    <?php 
        // Catch session messages (from guest_request.php redirect) or local messages
        $final_msg = "";
        if (!empty($message)) {
            $final_msg = $message;
        } elseif (isset($_SESSION['msg']) && !empty($_SESSION['msg'])) {
            $final_msg = $_SESSION['msg'];
            unset($_SESSION['msg']);
        }

        if (!empty($final_msg)): 
            $msg_text = strip_tags($final_msg);
            $is_success = (strpos($final_msg, 'alert-success') !== false || strpos($final_msg, 'alert-info') !== false || strpos($msg_text, '‚úÖ') !== false);
            $is_warning = (strpos($final_msg, 'alert-warning') !== false || strpos($msg_text, '‚ö†Ô∏è') !== false || strpos($msg_text, 'Ì†ΩÌ¥ô') !== false);
            
            if ($is_success) { $bg_class = 'bg-success'; $icon = 'fa-check-circle'; $title = 'Success'; }
            elseif ($is_warning) { $bg_class = 'bg-warning text-dark'; $icon = 'fa-exclamation-triangle'; $title = 'Notice'; }
            else { $bg_class = 'bg-danger'; $icon = 'fa-times-circle'; $title = 'Action'; }
    ?>
        <div class="toast custom-toast show" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="4000">
            <div class="toast-header <?php echo $bg_class; ?> text-white border-0">
                <i class="fas <?php echo $icon; ?> me-2"></i><strong class="me-auto"><?php echo $title; ?></strong>
                <button type="button" class="btn-close <?php echo $is_warning ? '' : 'btn-close-white'; ?>" data-bs-dismiss="toast"></button>
            </div>
            <div class="toast-body bg-white text-dark fw-semibold">
                <?php echo trim(str_replace(['‚úÖ', '‚ùå', '‚ö†Ô∏è', 'Ì†ΩÌ¥ô', 'Ì†ΩÌ∑ëÔ∏è'], '', $msg_text)); ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="sidebar">
    <h4 class="mb-4 text-center"><i class="fas fa-hotel me-2"></i>SCL DMS</h4>
    <div class="p-3 mb-4 bg-white bg-opacity-10 rounded text-center">
        <small>Welcome,</small><br>
        <strong><?php echo htmlspecialchars($user); ?></strong><br>
        <span class="badge bg-warning text-dark mt-1"><?php echo strtoupper($role); ?></span>
    </div>
    <a href="index.php" class="btn btn-outline-light w-100 mb-2"><i class="fas fa-home me-2"></i>Dashboard</a>
    <a href="guest_request.php" class="btn btn-outline-info w-100 mb-2 text-white"><i class="fas fa-paper-plane me-2"></i>Submit Request</a>
    <a href="my_requests.php" class="btn btn-info w-100 mb-2 text-dark fw-bold"><i class="fas fa-list-alt me-2"></i>My Sent Requests</a>
    <a href="profile.php" class="btn btn-outline-light w-100 mb-2"><i class="fas fa-user-edit me-2"></i>My Profile</a>
    
    <?php if(in_array($role,['admin','superadmin','approver'])): ?>
    <a href="manage_requests.php" class="btn btn-outline-warning w-100 mb-2 position-relative text-white">
        <i class="fas fa-tasks me-2"></i>Manage All
        <?php if($pending_count>0): ?>
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?php echo $pending_count; ?></span>
        <?php endif; ?>
    </a>
    <?php endif; ?>
    <?php if(in_array($role,['admin','superadmin'])): ?>
    <hr class="border-light">
    <a href="admin_dashboard.php" class="btn btn-warning w-100 mb-2"><i class="fas fa-crown me-2"></i>Admin Panel</a>
    <?php endif; ?>
    <a href="logout.php" class="btn btn-danger w-100 mt-4"><i class="fas fa-sign-out-alt me-2"></i>Logout</a>
</div>

<div class="content">
    <div class="card shadow border-0">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-user-clock me-2"></i>My Visit Requests</h5>
            <span class="badge bg-light text-dark"><?php echo mysqli_num_rows($my_req_result); ?> Records</span>
        </div>
        <div class="card-body">
            <?php if(mysqli_num_rows($my_req_result) > 0): ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Ref ID</th>
                            <th>Guest Details</th>
                            <th>Dept & Purpose</th>
                            <th>Check-in / Check-out</th>
                            <th>Status</th>
                            <th>Approved By</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php while($row = mysqli_fetch_assoc($my_req_result)): ?>
                    <?php
                        $cinDate  = !empty($row['check_in_date'])  ? date('d M Y', strtotime($row['check_in_date']))  : '-';
                        $cinTime  = (!empty($row['check_in_time'])  && $row['check_in_time']  != '00:00:00') ? date('h:i A', strtotime($row['check_in_time']))  : '';
                        $coutDate = !empty($row['check_out_date']) ? date('d M Y', strtotime($row['check_out_date'])) : '-';
                        $coutTime = (!empty($row['check_out_time']) && $row['check_out_time'] != '00:00:00') ? date('h:i A', strtotime($row['check_out_time'])) : '';
                        $s = $row['status'];
                    ?>
                    <tr>
                        <td><strong>#<?php echo $row['id']; ?></strong></td>
                        <td>
                            <!-- ‚úÖ MULTIPLE GUESTS DISPLAY -->
                            <?php 
                                $g_sql = mysqli_query($conn, "SELECT * FROM visit_guests WHERE request_id = ".$row['id']);
                                if(mysqli_num_rows($g_sql) > 0):
                                    while($g = mysqli_fetch_assoc($g_sql)):
                            ?>
                                    <div class="guest-item">
                                        <strong><?php echo htmlspecialchars($g['guest_title'].' '.$g['guest_name']); ?></strong><br>
                                        <small class="text-muted">
                                            <i class="fas fa-phone"></i> <?php echo htmlspecialchars($g['phone']); ?> | 
                                            <i class="fas fa-envelope"></i> <?php echo !empty($g['email']) ? htmlspecialchars($g['email']) : 'N/A'; ?><br>
                                            <i class="fas fa-map-marker-alt"></i> <?php echo !empty($g['address']) ? htmlspecialchars($g['address']) : 'N/A'; ?>
                                        </small>
                                    </div>
                            <?php 
                                    endwhile;
                                else: // Fallback for old records
                            ?>
                                <strong><?php echo htmlspecialchars($row['guest_name']); ?></strong><br>
                                <small class="text-muted"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($row['phone']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-secondary mb-1"><?php echo htmlspecialchars($row['department']??''); ?></span><br>
                            <small class="text-muted fw-semibold"><?php echo htmlspecialchars($row['purpose']??''); ?></small>
                        </td>
                        <td>
                            <div><strong class="text-success"><i class="fas fa-sign-in-alt me-1"></i>In:</strong> <?php echo $cinDate; ?> <?php if($cinTime): ?><span class="badge bg-success bg-opacity-25 text-dark ms-1"><?php echo $cinTime; ?></span><?php endif; ?></div>
                            <div class="mt-2"><strong class="text-danger"><i class="fas fa-sign-out-alt me-1"></i>Out:</strong> <?php echo $coutDate; ?> <?php if($coutTime): ?><span class="badge bg-danger bg-opacity-25 text-dark ms-1"><?php echo $coutTime; ?></span><?php endif; ?></div>
                        </td>
                        <td>
                            <?php
                            if ($s == 'Draft') {
                                echo "<span class='badge bg-secondary'><i class='fas fa-pencil-alt me-1'></i>Draft</span>";
                            } elseif ($s == 'Pending') {
                                echo "<span class='badge bg-warning text-dark'><i class='fas fa-hourglass-half me-1'></i>Pending</span>";
                            } elseif ($s == 'Rejected') {
                                echo "<span class='badge bg-danger'><i class='fas fa-times me-1'></i>Rejected</span>";
                            } else {
                                echo "<span class='badge bg-success'><i class='fas fa-check me-1'></i>Approved</span>";
                            }
                            ?>
                        </td>
                        <td>
                            <?php
                            if ($s == 'Pending' || $s == 'Draft' || $s == 'Rejected') {
                                echo "<span class='text-muted small'>-</span>";
                            } else {
                                $name = $row['approver_name'] ?: $row['approved_by'];
                                echo "<span class='fw-bold text-dark'>".htmlspecialchars($name ?: '-')."</span>";
                            }
                            ?>
                        </td>
                        <td>
                            <?php if ($s == 'Draft'): ?>
                                <a href="guest_request.php?edit_id=<?php echo $row['id']; ?>" class="btn btn-sm btn-primary mb-1 me-1 shadow-sm" title="Edit Draft"><i class="fas fa-edit"></i> Edit</a>
                                <a href="my_requests.php?delete_id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-danger mb-1 shadow-sm" onclick="return confirm('Delete this draft?')" title="Delete Draft"><i class="fas fa-trash-alt"></i> Delete</a>
                            
                            <?php elseif ($s == 'Pending'): ?>
                                <a href="my_requests.php?move_to_draft_id=<?php echo $row['id']; ?>" class="btn btn-sm btn-warning mb-1 me-1 shadow-sm" onclick="return confirm('Move this request back to Draft? Admin will be notified and you can edit it.')" title="Move back to Draft to Edit">
                                    <i class="fas fa-undo-alt"></i> To Draft
                                </a>
                                <a href="my_requests.php?delete_id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-danger mb-1 shadow-sm" onclick="return confirm('Withdraw this request?\nAdmin team will be notified.')" title="Withdraw Request">
                                    <i class="fas fa-times-circle"></i> Withdraw
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
                    <i class="fas fa-inbox fa-3x text-muted mb-3 opacity-50"></i>
                    <h5 class="text-muted">No requests found.</h5>
                    <a href="guest_request.php" class="btn btn-primary mt-3"><i class="fas fa-plus-circle me-2"></i>Submit New Request</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Ì†ºÌºü Initialize Toasts automatically
    var toastElList = [].slice.call(document.querySelectorAll('.toast'));
    var toastList = toastElList.map(function (toastEl) {
        return new bootstrap.Toast(toastEl, { autohide: true });
    });
});
</script>
</body>
</html>
