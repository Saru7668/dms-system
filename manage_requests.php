<?php
session_start();
require_once('db.php');
require_once('header.php');

// OPTIONAL: Debug er jonno (problem thakle 1 bar on kore error dekhte paro)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Access Control
if (!isset($_SESSION['UserName'])) {
    header("Location: login.php");
    exit;
}

$user = $_SESSION['UserName'];
$role = $_SESSION['UserRole'] ?? 'user';

// Check Role Permissions
if (!in_array($role, ['admin', 'staff', 'superadmin', 'approver'])) {
    echo "Access Denied";
    exit;
}

// Notifications table ache naki (mysqli strict mode hole missing table e 500 dibe)
$notifications_table_exists = false;
$tbl_check = mysqli_query($conn, "SHOW TABLES LIKE 'notifications'");
if ($tbl_check && mysqli_num_rows($tbl_check) > 0) {
    $notifications_table_exists = true;
}

// HANDLE DELETE ACTION (Only for Admin/Superadmin)
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    if (in_array($role, ['admin', 'superadmin'])) {
        $id = (int)$_GET['id'];
        
        // Delete associated guests first
        mysqli_query($conn, "DELETE FROM visit_guests WHERE request_id = $id");
        // Delete master request
        mysqli_query($conn, "DELETE FROM visit_requests WHERE id = $id");
        
        // Set Session Message for Toast
        $_SESSION['msg'] = "Request Deleted Successfully!";
        header("Location: manage_requests.php");
        exit;
    }
}

// HANDLE APPROVE / REJECT
if (isset($_GET['action']) && isset($_GET['id']) && in_array($_GET['action'], ['approve', 'reject'])) {
    $id     = (int)$_GET['id'];
    $action = $_GET['action'];
    $status = ($action === 'approve') ? 'Approved' : 'Rejected';

    // Reject reason check
    $reason = '';
    if ($action === 'reject') {
        $reason = isset($_GET['reason']) ? trim($_GET['reason']) : '';
        if ($reason === '') {
            $_SESSION['msg'] = "?? Rejection reason is required!";
            header("Location: manage_requests.php");
            exit;
        }
    }

    // Fetch master request info
    $req_sql = mysqli_query(
        $conn,
        "SELECT email, guest_name, guest_title,
                check_in_date, check_in_time,
                check_out_date, check_out_time,
                department, purpose, phone, status, requested_by
         FROM visit_requests WHERE id = $id"
    );

    if ($req_sql && mysqli_num_rows($req_sql) > 0) {
        $req_data = mysqli_fetch_assoc($req_sql);

        $approver   = mysqli_real_escape_string($conn, $user);
        $update_sql = "
            UPDATE visit_requests 
            SET status = '$status', approved_by = '$approver'
            WHERE id = $id
        ";

        if (mysqli_query($conn, $update_sql)) {

            // NOTIFICATION INSERT (Only when Approved + table thakle)
            if ($status === 'Approved' && $notifications_table_exists) {
                $chk = mysqli_query($conn, "SELECT id FROM notifications WHERE type='request_approved' AND request_id=$id LIMIT 1");
                if ($chk && mysqli_num_rows($chk) == 0) {
                    mysqli_query($conn, "INSERT INTO notifications (type, request_id) VALUES ('request_approved', $id)");
                }
            }

            // Common data for mails
            $dept     = $req_data['department'] ?? '';
            $purpose  = $req_data['purpose'] ?? '';
            
            // Check-in (date + time)
            $check_in_date  = $req_data['check_in_date'] ?? '';
            $check_in_time  = $req_data['check_in_time'] ?? '';
            $check_out_date = $req_data['check_out_date'] ?? '';
            $check_out_time = $req_data['check_out_time'] ?? '';
            
            $check_in_disp = $check_in_date ? date('d M Y', strtotime($check_in_date)) : 'N/A';
            if (!empty($check_in_time) && $check_in_time !== '00:00:00') {
                $check_in_disp .= ' ' . date('h:i A', strtotime($check_in_time));
            }
            
            // Planned check-out (date + time)
            $checkout_disp = '';
            if (!empty($check_out_date)) {
                $checkout_disp = date('d M Y', strtotime($check_out_date));
            }
            if (!empty($check_out_time) && $check_out_time !== '00:00:00') {
                $checkout_disp .= ($checkout_disp ? ' ' : '') . date('h:i A', strtotime($check_out_time));
            }

            // Mail headers
            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8\r\n";
            $headers .= "From: SCL Dormitory <no-reply@scl-dormitory.com>\r\n";

            // ========================================================
            // ?? 1. SEND INDIVIDUAL EMAIL TO ALL GUESTS IN THE REQUEST
            // ========================================================
            $guests_sql = mysqli_query($conn, "SELECT * FROM visit_guests WHERE request_id = $id");
            $guest_list_for_staff = ""; // This will build the text list for the staff email
            $guest_emails_sent = [];

            while($g = mysqli_fetch_assoc($guests_sql)) {
                $g_name = "{$g['guest_title']} {$g['guest_name']}";
                $g_email = $g['email'];
                $g_phone = $g['phone'];
                $g_address = $g['address'];
                
                // Build string for Staff/Admin Email later
                $guest_list_for_staff .= "<li><strong>$g_name</strong> (Phone: $g_phone, Email: $g_email, Addr: $g_address)</li>";

                // Send Mail to Guest if email exists
                if (!empty($g_email) && !in_array($g_email, $guest_emails_sent)) {
                    $guest_emails_sent[] = $g_email; // Prevent duplicate emails
                    
                    if ($status === 'Approved') {
                        $subject_guest = "Your dormitory visit request #$id has been approved";
                        $mail_body = "
                        <html><body style='font-family:Segoe UI,sans-serif;color:#333;'>
                            <div style='max-width:600px;margin:20px auto;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden;'>
                                <div style='background:#1a2a3a;color:#fff;padding:20px;text-align:center;'>
                                    <h2 style='margin:0;'>Visit Request Approved</h2>
                                    <p style='margin:5px 0 0;'>Reference ID: #$id</p>
                                </div>
                                <div style='padding:25px;'>
                                    <p style='font-size:16px;'><strong>Dear $g_name,</strong></p>
                                    <p>We are happy to inform you that your dormitory visit request (Ref #$id) has been <strong style='color:#28a745;'>APPROVED</strong>.</p>
                                    
                                    <p style='margin-top:20px; margin-bottom:5px;'><strong>Visit details:</strong></p>
                                    <ul style='margin-top:0; padding-left:20px;'>
                                        <li><strong>Check-in:</strong> $check_in_disp</li>";
                                        if ($checkout_disp !== '') $mail_body .= "<li><strong>Planned Check-out:</strong> $checkout_disp</li>";
                                        if ($dept !== '') $mail_body .= "<li><strong>Department:</strong> $dept</li>";
                                        if ($purpose !== '') $mail_body .= "<li><strong>Purpose:</strong> $purpose</li>";
                        $mail_body .= "</ul>
                                    <p style='margin-top:20px;'>Our team is looking forward to welcoming you to the SCL Dormitory.</p>
                                    <br>
                                    <p style='margin:0;'>Best regards,<br>SCL Dormitory Management Team</p>
                                </div>
                                <div style='background:#f1f1f1;padding:15px;text-align:center;font-size:12px;color:#666;'>
                                    <p style='margin:0;'>SCL Dormitory Management System</p>
                                </div>
                            </div>
                        </body></html>";
                    } else {
                        $subject_guest = "Update on your dormitory visit request #$id";
                        $mail_body = "
                        <html><body style='font-family:Segoe UI,sans-serif;color:#333;'>
                            <div style='max-width:600px;margin:20px auto;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden;'>
                                <div style='background:#dc3545;color:#fff;padding:20px;text-align:center;'>
                                    <h2 style='margin:0;'>Visit Request Update</h2>
                                    <p style='margin:5px 0 0;'>Reference ID: #$id</p>
                                </div>
                                <div style='padding:25px;'>
                                    <p style='font-size:16px;'><strong>Dear $g_name,</strong></p>
                                    <p>Thank you for your interest in staying at the SCL Dormitory.</p>
                                    <p>After careful review, we are unable to approve your visit request (Ref #$id) at this time.</p>
                                    
                                    <div style='background:#fff3f3;border-left:4px solid #dc3545;padding:15px;margin:20px 0;'>
                                        <p style='margin:0;'><strong>Reason for rejection:</strong><br>$reason</p>
                                    </div>
                                    
                                    <p>We understand this may be disappointing and we truly appreciate your understanding.</p>
                                    <p>You are always welcome to submit a new request in the future if your plans change.</p>
                                    <br>
                                    <p style='margin:0;'>Best regards,<br>SCL Dormitory Management Team</p>
                                </div>
                                <div style='background:#f1f1f1;padding:15px;text-align:center;font-size:12px;color:#666;'>
                                    <p style='margin:0;'>SCL Dormitory Management System</p>
                                </div>
                            </div>
                        </body></html>";
                    }
                    
                    @mail($g_email, $subject_guest, $mail_body, $headers);
                }
            }

            // ========================================================
            // ?? 2. SEND EMAIL TO REQUESTER (If different from guests)
            // ========================================================
            $requester_name = $req_data['requested_by'];
            if (!empty($requester_name)) {
                $rq_sql = mysqli_query($conn, "SELECT email FROM users WHERE UserName = '" . mysqli_real_escape_string($conn, $requester_name) . "' LIMIT 1");
                if ($rq_sql && mysqli_num_rows($rq_sql) > 0) {
                    $requester_email = mysqli_fetch_assoc($rq_sql)['email'];
                    
                    if (!empty($requester_email) && !in_array($requester_email, $guest_emails_sent)) {
                        if ($status === 'Approved') {
                            $subj_req = "Copy: Visit request #$id has been approved";
                            $mail_body_req = "
                            <html><body style='font-family:Segoe UI,sans-serif;color:#333;'>
                                <div style='max-width:600px;margin:20px auto;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden;'>
                                    <div style='background:#1a2a3a;color:#fff;padding:20px;text-align:center;'>
                                        <h2 style='margin:0;'>Visit Request Approved</h2>
                                        <p style='margin:5px 0 0;'>Reference ID: #$id</p>
                                    </div>
                                    <div style='padding:25px;'>
                                        <p style='font-size:16px;'><strong>Dear $requester_name,</strong></p>
                                        <p>The dormitory visit request you submitted (Ref #$id) has been <strong style='color:#28a745;'>APPROVED</strong>.</p>
                                        
                                        <p style='margin-top:20px; margin-bottom:5px;'><strong>Visit details:</strong></p>
                                        <ul style='margin-top:0; padding-left:20px;'>
                                            <li><strong>Check-in:</strong> $check_in_disp</li>";
                                            if ($checkout_disp !== '') $mail_body_req .= "<li><strong>Planned Check-out:</strong> $checkout_disp</li>";
                                            if ($dept !== '') $mail_body_req .= "<li><strong>Department:</strong> $dept</li>";
                                            if ($purpose !== '') $mail_body_req .= "<li><strong>Purpose:</strong> $purpose</li>";
                            $mail_body_req .= "</ul>
                                        
                                        <h4 style='border-bottom:1px solid #eee;padding-bottom:5px;'>Visitor(s):</h4>
                                        <ul style='padding-left:20px;'>
                                            $guest_list_for_staff
                                        </ul>
                                        
                                        <br>
                                        <p style='margin:0;'>Best regards,<br>SCL Dormitory Management Team</p>
                                    </div>
                                    <div style='background:#f1f1f1;padding:15px;text-align:center;font-size:12px;color:#666;'>
                                        <p style='margin:0;'>SCL Dormitory Management System</p>
                                    </div>
                                </div>
                            </body></html>";
                        } else {
                            $subj_req = "Copy: Visit request #$id was not approved";
                            $mail_body_req = "
                            <html><body style='font-family:Segoe UI,sans-serif;color:#333;'>
                                <div style='max-width:600px;margin:20px auto;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden;'>
                                    <div style='background:#dc3545;color:#fff;padding:20px;text-align:center;'>
                                        <h2 style='margin:0;'>Visit Request Update</h2>
                                        <p style='margin:5px 0 0;'>Reference ID: #$id</p>
                                    </div>
                                    <div style='padding:25px;'>
                                        <p style='font-size:16px;'><strong>Dear $requester_name,</strong></p>
                                        <p>The dormitory visit request you submitted (Ref #$id) could not be approved.</p>
                                        
                                        <div style='background:#fff3f3;border-left:4px solid #dc3545;padding:15px;margin:20px 0;'>
                                            <p style='margin:0;'><strong>Reason provided:</strong><br>$reason</p>
                                        </div>
                                        <br>
                                        <p style='margin:0;'>Best regards,<br>SCL Dormitory Management Team</p>
                                    </div>
                                    <div style='background:#f1f1f1;padding:15px;text-align:center;font-size:12px;color:#666;'>
                                        <p style='margin:0;'>SCL Dormitory Management System</p>
                                    </div>
                                </div>
                            </body></html>";
                        }
                        
                        @mail($requester_email, $subj_req, $mail_body_req, $headers);
                    }
                }
            }

            // ========================================================
            // ?? 3. SEND EMAIL TO STAFF / ADMIN (Only on Approval)
            // ========================================================
            if ($status === 'Approved') {
                $staff_admin_sql = "SELECT email FROM users WHERE user_role IN ('admin', 'superadmin', 'staff') AND email IS NOT NULL AND email != ''";
                $staff_result = mysqli_query($conn, $staff_admin_sql);

                if ($staff_result && mysqli_num_rows($staff_result) > 0) {
                    $staff_subject = "APPROVED: Visit Request #$id ready for booking";

                    $staff_msg = "
                    <html><body style='font-family:Segoe UI,sans-serif;color:#333;'>
                        <div style='max-width:600px;margin:20px auto;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden;'>
                            <div style='background:#1a2a3a;color:#fff;padding:20px;text-align:center;'>
                                <h2 style='margin:0;'>Action Required: Room Allocation</h2>
                                <p style='margin:5px 0 0;'>Reference ID: #$id</p>
                            </div>
                            <div style='padding:25px;'>
                                <p>Dear Authorization Team,</p>
                                <p>A visit request has been <strong style='color:#28a745;'>APPROVED</strong> and is now pending room allocation.</p>
                                
                                <div style='background:#f8f9fa;border-left:4px solid #1a2a3a;padding:15px;margin:20px 0;'>
                                    <p style='margin:0 0 5px 0;'><strong>Check-in:</strong> $check_in_disp</p>";
                                    if ($checkout_disp !== '') $staff_msg .= "<p style='margin:0 0 5px 0;'><strong>Planned Out:</strong> $checkout_disp</p>";
                    $staff_msg .= " <p style='margin:0;'><strong>Approved By:</strong> $approver</p>
                                </div>

                                <h4 style='border-bottom:1px solid #eee;padding-bottom:5px;'>Visitor Information:</h4>
                                <ul style='padding-left:20px;'>
                                    $guest_list_for_staff
                                </ul>

                                <p style='margin-top:25px;'>Please log in to the system to assign a room for the guests.</p>
                                <div style='text-align:center; margin:30px 0;'>
                                    <a href='http://" . $_SERVER['HTTP_HOST'] . "/dormitory/index.php' style='display:inline-block;background:#0d6efd;color:#fff;padding:10px 20px;text-decoration:none;border-radius:5px;font-weight:bold;'>Access Dashboard</a>
                                </div>
                                
                                <br>
                                <p style='margin:0;font-size:14px;'>SCL Dormitory Management Team</p>
                            </div>
                            <div style='background:#f1f1f1;padding:15px;text-align:center;font-size:12px;color:#666;'>
                                <p style='margin:0;'>SCL Dormitory Management System</p>
                            </div>
                        </div>
                    </body></html>";

                    while ($staff = mysqli_fetch_assoc($staff_result)) {
                        @mail($staff['email'], $staff_subject, $staff_msg, $headers);
                    }
                }
            }
        }
        $_SESSION['msg'] = ($status === 'Approved') ? "Request Approved Successfully!" : "Request Rejected Successfully!";
    }

    header("Location: manage_requests.php");
    exit;
}

// Sidebar Badge Logic
$pending_query = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM visit_requests WHERE status='Pending'");
$pending_count = mysqli_fetch_assoc($pending_query)['cnt'];

// ? Fetch all requests EXCEPT 'Draft'
$sql    = "SELECT * FROM visit_requests WHERE status != 'Draft' ORDER BY id DESC";
$result = mysqli_query($conn, $sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Requests</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        
        /* ?? Fade-in Animation */
        .page-fade-in { animation: fadeIn 0.6s ease-in-out forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        /* ?? Floating Toast Notifications */
        .toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; }
        .custom-toast { min-width: 300px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); border: none; border-radius: 8px; overflow: hidden; }

        .sidebar { background: #1a2a3a; color: white; height: 100vh; position: fixed; width: 250px; padding: 20px; overflow-y: auto; z-index: 1000; }
        .content { margin-left: 250px; padding: 30px; }
        .guest-item { border-left: 3px solid #28a745; padding-left: 10px; margin-bottom: 10px; }
        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; }
            .content { margin-left: 0; }
        }
    </style>
</head>
<body class="page-fade-in">

<!-- ?? Floating Toast Notifications -->
<div class="toast-container">
    <?php if (isset($_SESSION['msg']) && !empty($_SESSION['msg'])): ?>
        <?php 
            $msg_text = $_SESSION['msg'];
            $is_warning = strpos($msg_text, '??') !== false;
            $bg_class = $is_warning ? 'bg-warning text-dark' : 'bg-success text-white';
            $icon = $is_warning ? 'fa-exclamation-triangle' : 'fa-check-circle';
            $title = $is_warning ? 'Warning' : 'Success';
        ?>
        <div class="toast custom-toast show" role="alert" data-bs-delay="4000">
            <div class="toast-header <?php echo $bg_class; ?> border-0">
                <i class="fas <?php echo $icon; ?> me-2"></i><strong class="me-auto"><?php echo $title; ?></strong>
                <button type="button" class="btn-close <?php echo $is_warning ? '' : 'btn-close-white'; ?>" data-bs-dismiss="toast"></button>
            </div>
            <div class="toast-body bg-white text-dark fw-semibold">
                <?php echo str_replace('??', '', $msg_text); ?>
            </div>
        </div>
        <?php unset($_SESSION['msg']); ?>
    <?php endif; ?>
</div>

<div class="sidebar">
    <h4 class="mb-4 text-center"><i class="fas fa-hotel me-2"></i>SCL DMS</h4>
    <div class="p-3 mb-4 bg-white bg-opacity-10 rounded text-center">
        <small>Welcome,</small><br><strong><?php echo htmlspecialchars($user); ?></strong><br>
        <span class="badge bg-warning text-dark mt-1"><?php echo strtoupper($role); ?></span>
    </div>
    
    <a href="index.php" class="btn btn-outline-light w-100 mb-2"><i class="fas fa-home me-2"></i>Dashboard</a>
    <a href="guest_request.php" class="btn btn-outline-info w-100 mb-2 text-white"><i class="fas fa-paper-plane me-2"></i>Submit Request</a>

    <?php if(in_array($role, ['staff', 'admin', 'superadmin', 'approver'])): ?>
        <a href="manage_requests.php" class="btn btn-warning w-100 mb-2 position-relative text-dark fw-bold">
            <i class="fas fa-tasks me-2"></i>Requests 
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
    <div class="card shadow border-0">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-list-ul me-2"></i>Manage Visit Requests</h5>
            <span class="badge bg-light text-dark"><?php echo $pending_count; ?> Pending</span>
        </div>

        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Ref ID</th>
                            <th>Guest Info</th>
                            <th>Dept & Purpose</th>
                            <th>Check-in / Check-out</th>
                            <th>Status</th>
                            <th>Approver</th>
                            <th>Requested By</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = mysqli_fetch_assoc($result)): ?>
                        <?php
                            $cinDate  = !empty($row['check_in_date']) ? date('d M Y', strtotime($row['check_in_date'])) : '-';
                            $cinTime  = (!empty($row['check_in_time']) && $row['check_in_time'] != '00:00:00') ? date('h:i A', strtotime($row['check_in_time'])) : '';
                            $coutDate = !empty($row['check_out_date']) ? date('d M Y', strtotime($row['check_out_date'])) : '-';
                            $coutTime = (!empty($row['check_out_time']) && $row['check_out_time'] != '00:00:00') ? date('h:i A', strtotime($row['check_out_time'])) : '';
                        ?>
                        <tr>
                            <td>#<?php echo $row['id']; ?></td>
                            <td>
                                <!-- ? MULTIPLE GUESTS DISPLAY WITH ADDRESS -->
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
                                    else: 
                                ?>
                                    <strong><?php echo htmlspecialchars($row['guest_name']); ?></strong><br>
                                    <small class="text-muted"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($row['phone']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-secondary"><?php echo htmlspecialchars($row['department']); ?></span><br>
                                <small><?php echo htmlspecialchars($row['purpose']); ?></small>
                            </td>
                            <td>
                                <div>
                                    <strong>In:</strong> <?php echo $cinDate; ?>
                                    <?php if ($cinTime !== ''): ?> <span class="badge bg-info text-dark ms-1"><?php echo $cinTime; ?></span> <?php endif; ?>
                                </div>
                                <div class="mt-1">
                                    <strong>Out:</strong> <?php echo $coutDate; ?>
                                    <?php if ($coutTime !== ''): ?> <span class="badge bg-secondary text-light ms-1"><?php echo $coutTime; ?></span> <?php endif; ?>
                                </div>
                            </td>

                            <td>
                                <?php 
                                    $s = $row['status'];
                                    if(in_array($s, ['Booked', 'Completed'])) { $s = 'Approved'; }
                                    $cls = ($s == 'Pending') ? 'warning text-dark' : (($s == 'Approved') ? 'success' : 'danger');
                                    echo "<span class='badge bg-$cls'>$s</span>";
                                ?>
                            </td>
                            <td>
                                <?php echo !empty($row['approved_by']) ? htmlspecialchars($row['approved_by']) : '<span class="text-muted">-</span>'; ?>
                            </td>
                            <td><small class="text-muted"><?php echo htmlspecialchars($row['requested_by']); ?></small></td>
                            <td>
                                <div class="btn-group">
                                    <?php if($row['status'] == 'Pending'): ?>
                                        <a href="?action=approve&id=<?php echo $row['id']; ?>" 
                                           class="btn btn-sm btn-success" 
                                           onclick="return confirm('Approve this request?')">
                                            <i class="fas fa-check"></i>
                                        </a>

                                        <button type="button"
                                                class="btn btn-sm btn-danger"
                                                data-bs-toggle="modal"
                                                data-bs-target="#rejectModal"
                                                data-id="<?php echo $row['id']; ?>"
                                                data-guest="<?php echo htmlspecialchars($row['guest_name']); ?>">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted small me-2">
                                            <?php 
                                                $final_status = $row['status'];
                                                if(in_array($final_status, ['Booked', 'Completed'])) { echo "Approved"; } 
                                                else { echo $final_status; }
                                            ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if(in_array($role, ['admin', 'superadmin'])): ?>
                                        <a href="?action=delete&id=<?php echo $row['id']; ?>" 
                                           class="btn btn-sm btn-dark ms-1" 
                                           onclick="return confirm('Are you sure you want to delete this record permanently?')" 
                                           title="Delete Record">
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

<!-- Modal for Rejection Reason -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title" id="rejectModalLabel">
            <i class="fas fa-times-circle me-2"></i>Reject Visit Request
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="rejectForm">
        <div class="modal-body">
          <p class="mb-2">
            You are about to reject the request for:<br>
            <strong id="rejectGuestName"></strong>
          </p>
          <input type="hidden" id="rejectRequestId">
          <div class="mb-3">
            <label for="rejectReason" class="form-label fw-semibold">
                Please write the reason for rejection
            </label>
            <textarea class="form-control" id="rejectReason" rows="3" 
                      placeholder="Example: No room is available on the requested date."
                      required></textarea>
            <div class="form-text">
                This reason will be shared with the guest in the email.
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">
            <i class="fas fa-paper-plane me-1"></i>Confirm Reject
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // ?? Initialize Toasts automatically
    var toastElList = [].slice.call(document.querySelectorAll('.toast'));
    var toastList = toastElList.map(function (toastEl) {
        return new bootstrap.Toast(toastEl, { autohide: true });
    });
});

var rejectModal = document.getElementById('rejectModal');
rejectModal.addEventListener('show.bs.modal', function (event) {
    var button = event.relatedTarget;
    var reqId  = button.getAttribute('data-id');
    var guest  = button.getAttribute('data-guest');

    document.getElementById('rejectRequestId').value   = reqId;
    document.getElementById('rejectGuestName').textContent = guest;
    document.getElementById('rejectReason').value = '';
});

document.getElementById('rejectForm').addEventListener('submit', function (e) {
    e.preventDefault();

    var id     = document.getElementById('rejectRequestId').value;
    var reason = document.getElementById('rejectReason').value.trim();

    if (reason === '') {
        alert('Rejection reason is required.');
        return;
    }

    if (!confirm('Are you sure you want to reject this request?')) {
        return;
    }

    var url = "manage_requests.php?action=reject&id=" + encodeURIComponent(id) +
              "&reason=" + encodeURIComponent(reason);

    window.location.href = url;
});
</script>
</body>
</html>
