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
// Ì†ΩÌ∫´ CANCELLATION REQUEST LOGIC (NEW UPDATE)
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_request_submit'])) {
    
    $cancel_req_id = isset($_POST['cancel_request_id']) ? (int)$_POST['cancel_request_id'] : 0;
    $cancel_reason = isset($_POST['cancel_reason']) ? mysqli_real_escape_string($conn, trim($_POST['cancel_reason'])) : '';

    if ($cancel_req_id > 0 && !empty($cancel_reason)) {
        
        $q_req = mysqli_query($conn, "SELECT * FROM visit_requests WHERE id = $cancel_req_id AND requested_by = '".mysqli_real_escape_string($conn, $user)."'");
        
        if ($q_req && mysqli_num_rows($q_req) > 0) {
            $req_data = mysqli_fetch_assoc($q_req);
            $guest_name = $req_data['guest_name'];
            $dept = $req_data['department'];
            $purp = $req_data['purpose'];
            $cinDate = date('d M Y h:i A', strtotime($req_data['check_in_date'] . ' ' . $req_data['check_in_time']));
            $coutDate = date('d M Y h:i A', strtotime($req_data['check_out_date'] . ' ' . $req_data['check_out_time']));

            // ‡¶°‡¶æ‡¶ü‡¶æ‡¶¨‡ßá‡¶ú‡ßá ‡¶ï‡¶æ‡¶∞‡¶£ ‡¶∏‡ßá‡¶≠ ‡¶ï‡¶∞‡¶æ
            mysqli_query($conn, "UPDATE visit_requests SET cancel_reason = '$cancel_reason' WHERE id = $cancel_req_id");

            // ‡¶®‡ßã‡¶ü‡¶ø‡¶´‡¶ø‡¶ï‡ßá‡¶∂‡¶® ‡¶á‡¶®‡¶∏‡¶æ‡¶∞‡ßç‡¶ü
            mysqli_query($conn, "DELETE FROM notifications WHERE request_id = $cancel_req_id AND type = 'cancellation_request'");
            mysqli_query($conn, "INSERT INTO notifications (request_id, type, is_read, created_at) VALUES ($cancel_req_id, 'cancellation_request', 0, NOW())");

            $admin_sql = "SELECT email FROM users WHERE user_role IN ('staff','admin','superadmin') AND email IS NOT NULL AND email != ''";
            $admin_res = mysqli_query($conn, $admin_sql);
            
            if ($admin_res && mysqli_num_rows($admin_res) > 0) {
                $headers  = "MIME-Version: 1.0\\r\\n";
                $headers .= "Content-type: text/html; charset=UTF-8\\r\\n";
                $headers .= "From: SCL Dormitory <no-reply@scl-dormitory.com>\\r\\n";
                
                $subj_c = "Ì†ΩÌ∫® Cancellation Request: Ref #$cancel_req_id";
                
                $body_c = "
                <html><body style='font-family:Arial,sans-serif; margin:0; padding:0; background-color:#f4f4f4;'>
                    <div style='max-width:600px; margin:20px auto; background-color:#ffffff; border:1px solid #dddddd;'>
                        <div style='background-color:#1a2a3a; color:#ffffff; padding:15px; text-align:center;'>
                            <h2 style='margin:0; font-size:22px;'>Visit Request Cancellation</h2>
                            <p style='margin:5px 0 0; font-size:14px; color:#d1d8e0;'>Reference ID: #$cancel_req_id</p>
                        </div>
                        <div style='padding:20px;'>
                            <p style='margin-top:0;'>Dear <strong>Authorization Team</strong>,</p>
                            <p>We would like to inform you that <strong>$user</strong> has requested to <strong style='color:#dc3545;'>CANCEL</strong> a booked visit request.</p>
                            
                            <div style='background-color:#f9f9f9; border-left:4px solid #1a2a3a; padding:15px; margin:20px 0;'>
                                <p style='margin:0 0 8px 0;'><strong>Guest Name:</strong> $guest_name</p>
                                <p style='margin:0 0 8px 0;'><strong>Check-in:</strong> $cinDate</p>
                                <p style='margin:0 0 8px 0;'><strong>Planned Check-out:</strong> $coutDate</p>
                                <p style='margin:0 0 8px 0;'><strong>Department:</strong> $dept</p>
                                <p style='margin:0;'><strong>Purpose:</strong> $purp</p>
                            </div>
                            
                            <div style='background-color:#fff3f3; border-left:4px solid #dc3545; padding:15px; margin:20px 0;'>
                                <p style='margin:0; color:#b02a37;'><strong>Cancellation Reason:</strong><br>$cancel_reason</p>
                            </div>
                            
                            <p>Please review this request and take the necessary action.</p>
                        </div>
                        <div style='background-color:#f1f1f1; color:#777777; text-align:center; padding:10px; font-size:12px; border-top:1px solid #eeeeee;'>
                            SCL Dormitory Management System
                        </div>
                    </div>
                </body></html>";

                while ($ar = mysqli_fetch_assoc($admin_res)) {
                    if (!empty($ar['email'])) {
                        @mail($ar['email'], $subj_c, $body_c, $headers);
                    }
                }
            }
            
            $_SESSION['msg'] = "‚úÖ Cancellation request for Ref #$cancel_req_id has been sent successfully.";
            header("Location: index.php");
            exit;
        }
    }
}

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
            $admin_sql = "SELECT email FROM users WHERE user_role IN ('approver','admin','superadmin') AND email IS NOT NULL AND email != ''";
            $admin_res = mysqli_query($conn, $admin_sql);
            if ($admin_res && mysqli_num_rows($admin_res) > 0) {
                $headers  = "MIME-Version: 1.0\\r\\n";
                $headers .= "Content-type: text/html; charset=UTF-8\\r\\n";
                $headers .= "From: SCL Dormitory <no-reply@scl-dormitory.com>\\r\\n";
                
                $subj_a = "‚ö†Ô∏è Request Moved to Draft: Ref #$draft_id by $user";
                
                $body_a = "
                <html><body style='font-family:Arial,sans-serif; margin:0; padding:0; background-color:#f4f4f4;'>
                    <div style='max-width:600px; margin:20px auto; background-color:#ffffff; border:1px solid #dddddd;'>
                        <div style='background-color:#ffc107; color:#000000; padding:15px; text-align:center;'>
                            <h2 style='margin:0; font-size:22px;'>Request Moved to Draft</h2>
                            <p style='margin:5px 0 0; font-size:14px;'>Reference ID: #$draft_id</p>
                        </div>
                        <div style='padding:20px;'>
                            <p style='margin-top:0;'>Dear <strong>Authorization Team</strong>,</p>
                            <p>The following pending request has been <strong>MOVED BACK TO DRAFT</strong> by the user (<strong>$user</strong>) for updates.</p>
                            <p>Please do not process it until it is resubmitted.</p>
                            
                            <div style='background-color:#fff9e6; border-left:4px solid #ffc107; padding:15px; margin:20px 0;'>
                                <p style='margin:0 0 8px 0;'><strong>Guest Name:</strong> $guest_name</p>
                                <p style='margin:0;'><strong>Action By:</strong> $user</p>
                            </div>
                        </div>
                        <div style='background-color:#f1f1f1; color:#777777; text-align:center; padding:10px; font-size:12px; border-top:1px solid #eeeeee;'>
                            SCL Dormitory Management System
                        </div>
                    </div>
                </body></html>";

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
// Ì†ΩÌ∑ëÔ∏è DELETE LOGIC
// ========================================
if (isset($_GET['delete_id'])) {
    $del_id = (int)$_GET['delete_id'];
    $check_sql = "SELECT r.*, u.email as user_email FROM visit_requests r JOIN users u ON r.requested_by = u.username WHERE r.id = $del_id AND r.requested_by = '".mysqli_real_escape_string($conn,$user)."' AND r.status IN ('Pending','Draft')";
    $check_res = mysqli_query($conn, $check_sql);

    if ($check_res && mysqli_num_rows($check_res) > 0) {
        $del_data   = mysqli_fetch_assoc($check_res);
        $del_status = $del_data['status'];
        $guest_name = $del_data['guest_name'];
        $check_in   = date('d M Y', strtotime($del_data['check_in_date']));
        $user_email = $del_data['user_email'];

        mysqli_query($conn, "DELETE FROM visit_guests WHERE request_id = $del_id");

        if (mysqli_query($conn, "DELETE FROM visit_requests WHERE id = $del_id")) {
            $headers  = "MIME-Version: 1.0\\r\\n";
            $headers .= "Content-type: text/html; charset=UTF-8\\r\\n";
            $headers .= "From: SCL Dormitory <no-reply@scl-dormitory.com>\\r\\n";
            
            if ($del_status === 'Draft') {
                if (!empty($user_email)) {
                    $subj = "Ì†ΩÌ∑ëÔ∏è Draft Deleted - Ref #$del_id";
                    $body = "
                    <html><body style='font-family:Arial,sans-serif; margin:0; padding:0; background-color:#f4f4f4;'>
                        <div style='max-width:600px; margin:20px auto; background-color:#ffffff; border:1px solid #dddddd;'>
                            <div style='background-color:#1a2a3a; color:#ffffff; padding:15px; text-align:center;'>
                                <h2 style='margin:0; font-size:22px;'>Draft Deleted</h2>
                                <p style='margin:5px 0 0; font-size:14px; color:#d1d8e0;'>Reference ID: #$del_id</p>
                            </div>
                            <div style='padding:20px;'>
                                <p style='margin-top:0;'>Dear <strong>$user</strong>,</p>
                                <p>Your draft visit request has been successfully deleted.</p>
                                
                                <div style='background-color:#f9f9f9; border-left:4px solid #6c757d; padding:15px; margin:20px 0;'>
                                    <p style='margin:0 0 8px 0;'><strong>Guest Name:</strong> $guest_name</p>
                                    <p style='margin:0 0 8px 0;'><strong>Check-in Date:</strong> $check_in</p>
                                    <p style='margin:0;'><strong>Status:</strong> Draft (Deleted)</p>
                                </div>
                            </div>
                            <div style='background-color:#f1f1f1; color:#777777; text-align:center; padding:10px; font-size:12px; border-top:1px solid #eeeeee;'>
                                SCL Dormitory Management System
                            </div>
                        </div>
                    </body></html>";
                    @mail($user_email, $subj, $body, $headers);
                }
                $message = "<div class='alert alert-info'>Ì†ΩÌ∑ëÔ∏è Draft deleted successfully.</div>";
            } else {
                if (!empty($user_email)) {
                    $subj_u = "Ì†ΩÌ∑ëÔ∏è Request Withdrawn - Ref #$del_id";
                    $body_u = "
                    <html><body style='font-family:Arial,sans-serif; margin:0; padding:0; background-color:#f4f4f4;'>
                        <div style='max-width:600px; margin:20px auto; background-color:#ffffff; border:1px solid #dddddd;'>
                            <div style='background-color:#1a2a3a; color:#ffffff; padding:15px; text-align:center;'>
                                <h2 style='margin:0; font-size:22px;'>Request Withdrawn</h2>
                                <p style='margin:5px 0 0; font-size:14px; color:#d1d8e0;'>Reference ID: #$del_id</p>
                            </div>
                            <div style='padding:20px;'>
                                <p style='margin-top:0;'>Dear <strong>$user</strong>,</p>
                                <p>Your pending visit request has been withdrawn successfully.</p>
                                
                                <div style='background-color:#f9f9f9; border-left:4px solid #dc3545; padding:15px; margin:20px 0;'>
                                    <p style='margin:0 0 8px 0;'><strong>Guest Name:</strong> $guest_name</p>
                                    <p style='margin:0 0 8px 0;'><strong>Check-in Date:</strong> $check_in</p>
                                    <p style='margin:0; color:#dc3545;'><strong>Status:</strong> Deleted by You</p>
                                </div>
                            </div>
                            <div style='background-color:#f1f1f1; color:#777777; text-align:center; padding:10px; font-size:12px; border-top:1px solid #eeeeee;'>
                                SCL Dormitory Management System
                            </div>
                        </div>
                    </body></html>";
                    @mail($user_email, $subj_u, $body_u, $headers);
                }
                $admin_sql = "SELECT email FROM users WHERE user_role IN ('approver','admin','superadmin') AND email IS NOT NULL AND email != ''";
                $admin_res = mysqli_query($conn, $admin_sql);
                if ($admin_res) {
                    $subj_a = "Ì†ΩÌ∑ëÔ∏è Request Withdrawn: Ref #$del_id by $user";
                    $body_a = "
                    <html><body style='font-family:Arial,sans-serif; margin:0; padding:0; background-color:#f4f4f4;'>
                        <div style='max-width:600px; margin:20px auto; background-color:#ffffff; border:1px solid #dddddd;'>
                            <div style='background-color:#dc3545; color:#ffffff; padding:15px; text-align:center;'>
                                <h2 style='margin:0; font-size:22px;'>Request Withdrawn</h2>
                                <p style='margin:5px 0 0; font-size:14px; color:#f8d7da;'>Reference ID: #$del_id</p>
                            </div>
                            <div style='padding:20px;'>
                                <p style='margin-top:0;'>Dear <strong>Authorization Team</strong>,</p>
                                <p>A pending request has been <strong style='color:#dc3545;'>WITHDRAWN</strong> by the user.</p>
                                
                                <div style='background-color:#f9f9f9; border-left:4px solid #dc3545; padding:15px; margin:20px 0;'>
                                    <p style='margin:0 0 8px 0;'><strong>Guest Name:</strong> $guest_name</p>
                                    <p style='margin:0 0 8px 0;'><strong>Check-in Date:</strong> $check_in</p>
                                    <p style='margin:0;'><strong>Deleted By:</strong> $user</p>
                                </div>
                            </div>
                            <div style='background-color:#f1f1f1; color:#777777; text-align:center; padding:10px; font-size:12px; border-top:1px solid #eeeeee;'>
                                SCL Dormitory Management System
                            </div>
                        </div>
                    </body></html>";
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> <!-- Mobile viewport meta -->
    <title>My Requests</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; }
        .page-fade-in { animation: fadeIn 0.6s ease-in-out forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; }
        .custom-toast { min-width: 320px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); border: none; border-radius: 8px; overflow: hidden; }
        
        /* Sidebar Styling */
        .sidebar { background: #1a2a3a; color: white; height: 100vh; position: fixed; width: 250px; padding: 20px; overflow-y: auto; z-index: 1000; transition: transform 0.3s ease; }
        .content { margin-left: 250px; padding: 30px; transition: margin-left 0.3s ease; }
        
        /* Mobile Top Navbar */
        .mobile-navbar { display: none; background: #1a2a3a; color: white; padding: 15px 20px; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 999; }
        .menu-toggle-btn { background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer; }

        .guest-item { border-left: 3px solid #0d6efd; padding-left: 10px; margin-bottom: 10px; }
        tbody tr { transition: background-color 0.2s ease; }
        tbody tr:hover { background-color: #f1f8ff !important; }

        /* RESPONSIVE STYLES */
        @media (max-width: 768px) { 
            .sidebar { transform: translateX(-100%); }
            .sidebar.active { transform: translateX(0); }
            .content { margin-left: 0; padding: 15px; } 
            .mobile-navbar { display: flex; }
            .content { padding-top: 20px; }
            .card-body { padding: 10px; }
            .table-responsive { border: 0; }
        }
        
        /* Overlay for mobile sidebar */
        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999; }
        .sidebar-overlay.active { display: block; }
    </style>
</head>
<body class="page-fade-in">

<div class="toast-container" id="toastBox">
    <?php 
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

<!-- Mobile Navbar & Overlay -->
<div class="mobile-navbar shadow-sm">
    <h5 class="mb-0"><i class="fas fa-hotel me-2"></i>SCL DMS</h5>
    <button class="menu-toggle-btn" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
</div>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="d-flex justify-content-between align-items-center mb-4 d-md-none">
        <h4 class="mb-0"><i class="fas fa-hotel me-2"></i>SCL DMS</h4>
        <button class="btn btn-sm btn-outline-light" onclick="toggleSidebar()"><i class="fas fa-times"></i></button>
    </div>
    <h4 class="mb-4 text-center d-none d-md-block"><i class="fas fa-hotel me-2"></i>SCL DMS</h4>
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
            <h5 class="mb-0" style="font-size: 1.1rem;"><i class="fas fa-user-clock me-2"></i>My Visit Requests</h5>
            <span class="badge bg-light text-dark"><?php echo mysqli_num_rows($my_req_result); ?> Records</span>
        </div>
        <div class="card-body">
            <?php if(mysqli_num_rows($my_req_result) > 0): ?>
            <div class="table-responsive">
                <table class="table align-middle table-hover" style="min-width: 800px;"> <!-- Ensures table doesn't squish on mobile -->
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
                        $is_checked_out = false;
                        if ($row['status'] == 'Booked') {
                            $chk_q = mysqli_query($conn, "SELECT status FROM bookings WHERE request_ref_id = ".$row['id']." AND status IN ('Checked-out', 'Checked Out') LIMIT 1");
                            if (mysqli_num_rows($chk_q) > 0) {
                                $is_checked_out = true;
                            }
                        }
                        
                        $cinDate  = !empty($row['check_in_date'])  ? date('d M Y', strtotime($row['check_in_date']))  : '-';
                        $cinTime  = (!empty($row['check_in_time'])  && $row['check_in_time']  != '00:00:00') ? date('h:i A', strtotime($row['check_in_time']))  : '';
                        $coutDate = !empty($row['check_out_date']) ? date('d M Y', strtotime($row['check_out_date'])) : '-';
                        $coutTime = (!empty($row['check_out_time']) && $row['check_out_time'] != '00:00:00') ? date('h:i A', strtotime($row['check_out_time'])) : '';
                        $s = $row['status'];
                    ?>
                    <tr>
                        <td><strong>#<?php echo $row['id']; ?></strong></td>
                        <td>
                            <?php 
                                $g_sql = mysqli_query($conn, "SELECT * FROM visit_guests WHERE request_id = ".$row['id']);
                                if(mysqli_num_rows($g_sql) > 0):
                                    while($g = mysqli_fetch_assoc($g_sql)):
                            ?>
                                    <div class="guest-item">
                                        <strong><?php echo htmlspecialchars($g['guest_title'].' '.$g['guest_name']); ?></strong><br>
                                        <small class="text-muted" style="font-size: 0.8rem;">
                                            <i class="fas fa-phone"></i> <?php echo htmlspecialchars($g['phone']); ?> | 
                                            <i class="fas fa-envelope"></i> <?php echo !empty($g['email']) ? htmlspecialchars($g['email']) : 'N/A'; ?><br>
                                            <i class="fas fa-map-marker-alt"></i> <?php echo !empty($g['address']) ? htmlspecialchars($g['address']) : 'N/A'; ?>
                                        </small>
                                    </div>
                            <?php 
                                    endwhile;
                                else: 
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
                            <div style="font-size: 0.9rem;"><strong class="text-success"><i class="fas fa-sign-in-alt me-1"></i>In:</strong> <?php echo $cinDate; ?> <?php if($cinTime): ?><span class="badge bg-success bg-opacity-25 text-dark ms-1"><?php echo $cinTime; ?></span><?php endif; ?></div>
                            <div class="mt-1" style="font-size: 0.9rem;"><strong class="text-danger"><i class="fas fa-sign-out-alt me-1"></i>Out:</strong> <?php echo $coutDate; ?> <?php if($coutTime): ?><span class="badge bg-danger bg-opacity-25 text-dark ms-1"><?php echo $coutTime; ?></span><?php endif; ?></div>
                        </td>
                       <td>
                            <?php
                            if ($s == 'Draft') {
                                echo "<span class='badge bg-secondary'><i class='fas fa-pencil-alt me-1'></i>Draft</span>";
                            } elseif ($s == 'Pending') {
                                echo "<span class='badge bg-warning text-dark'><i class='fas fa-hourglass-half me-1'></i>Pending</span>";
                            } elseif ($s == 'Rejected') {
                                echo "<span class='badge bg-danger'><i class='fas fa-times me-1'></i>Rejected</span>";
                            } elseif ($s == 'Booked') {
                                echo "<span class='badge bg-info text-dark shadow-sm'><i class='fas fa-bed me-1'></i>Booked</span>";
                            } elseif ($s == 'Cancelled' || $s == 'Canceled') {
                                echo "<span class='badge bg-danger'><i class='fas fa-ban me-1'></i>Cancelled</span>";
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
                                echo "<span class='fw-bold text-dark' style='font-size: 0.85rem;'>".htmlspecialchars($name ?: '-')."</span>";
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
                                <a href="my_requests.php?delete_id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-danger mb-1 shadow-sm" onclick="return confirm('Withdraw this request?\\nAdmin team will be notified.')" title="Withdraw Request">
                                    <i class="fas fa-times-circle"></i> Withdraw
                                </a>
                            
                            <?php elseif ($s == 'Booked'): ?>
                                <?php if ($is_checked_out): ?>
                                    <span class="badge bg-secondary mb-1"><i class="fas fa-history me-1"></i>Checked Out</span>
                                    <br><span class="text-muted small"><i class="fas fa-check-circle me-1"></i>Completed</span>
                                
                                <?php elseif (!empty($row['cancel_reason'])): ?>
                                    <span class="badge bg-danger bg-opacity-75 text-white mb-1"><i class="fas fa-exclamation-circle me-1"></i>Cancellation Requested</span>
                                    <br><span class="text-muted small"><i class="fas fa-lock me-1"></i>Locked</span>
                                
                                <?php else: ?>
                                    <span class="text-muted small me-2"><i class="fas fa-lock"></i> Locked</span><br>
                                    <button type="button" class="btn btn-sm btn-outline-danger shadow-sm mt-1" data-bs-toggle="modal" data-bs-target="#cancelModal<?php echo $row['id']; ?>" title="Request Cancellation">
                                        <i class="fas fa-times-circle"></i> Cancel
                                    </button>
                                    
                                    <div class="modal fade text-start" id="cancelModal<?php echo $row['id']; ?>" tabindex="-1" aria-labelledby="cancelModalLabel<?php echo $row['id']; ?>" aria-hidden="true">
                                      <div class="modal-dialog">
                                        <div class="modal-content">
                                          <div class="modal-header bg-danger text-white">
                                            <h5 class="modal-title" id="cancelModalLabel<?php echo $row['id']; ?>"><i class="fas fa-exclamation-triangle me-2"></i> Request Cancellation</h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                          </div>
                                          <form method="POST" action="my_requests.php">
                                              <div class="modal-body text-dark">
                                                  <p>You are about to request cancellation for the booked visit of <strong><?php echo htmlspecialchars($row['guest_name']); ?></strong> (Ref ID: #<?php echo $row['id']; ?>).</p>
                                                  <input type="hidden" name="cancel_request_id" value="<?php echo $row['id']; ?>">
                                                  <div class="mb-3">
                                                      <label class="form-label fw-bold">Reason for Cancellation <span class="text-danger">*</span></label>
                                                      <textarea name="cancel_reason" class="form-control" rows="3" placeholder="Please write exactly why you want to cancel this visit..." required></textarea>
                                                  </div>
                                                  <small class="text-muted"><i class="fas fa-info-circle"></i> Note: Submitting this will immediately notify the admin and staff team.</small>
                                              </div>
                                              <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                <button type="submit" name="cancel_request_submit" class="btn btn-danger"><i class="fas fa-paper-plane me-1"></i> Send Request</button>
                                              </div>
                                          </form>
                                        </div>
                                      </div>
                                    </div>
                                <?php endif; ?>
                            
                            <?php elseif ($s == 'Approved'): ?>
                                <span class="text-muted small"><i class="fas fa-clock me-1"></i> Waiting for Booking</span>
                                
                            <?php elseif ($s == 'Cancelled' || $s == 'Canceled'): ?>
                                <span class="text-danger small fw-bold"><i class="fas fa-ban me-1"></i> Cancelled</span>
                                
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
// Sidebar Toggle Logic for Mobile
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('active');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}

document.addEventListener('DOMContentLoaded', function () {
    var toastElList = [].slice.call(document.querySelectorAll('.toast'));
    var toastList = toastElList.map(function (toastEl) {
        return new bootstrap.Toast(toastEl, { autohide: true });
    });
});
</script>
</body>
</html>
