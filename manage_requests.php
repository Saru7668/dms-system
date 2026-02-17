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
        mysqli_query($conn, "DELETE FROM visit_requests WHERE id = $id");
        header("Location: manage_requests.php?msg=deleted");
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
            header("Location: manage_requests.php?error=noreason");
            exit;
        }
    }

//Approve/Reject handle querry

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
//============END=================

        if (mysqli_query($conn, $update_sql)) {

            // NOTIFICATION INSERT (Only when Approved + table thakle)
            if ($status === 'Approved' && $notifications_table_exists) {
                $chk = mysqli_query($conn, "SELECT id FROM notifications WHERE type='request_approved' AND request_id=$id LIMIT 1");
                if ($chk && mysqli_num_rows($chk) == 0) {
                    mysqli_query($conn, "INSERT INTO notifications (type, request_id) VALUES ('request_approved', $id)");
                }
            }

            // Common data for mails
            $g_name   = $req_data['guest_name'];
            $g_title  = $req_data['guest_title'] ?? '';
            $g_phone  = $req_data['phone'] ?? '';
            $dept     = $req_data['department'] ?? '';
            $purpose  = $req_data['purpose'] ?? '';
            $g_display_name = trim($g_title . ' ' . $g_name);
            
            // Check-in (date + time)
            $check_in_date  = $req_data['check_in_date'] ?? '';
            $check_in_time  = $req_data['check_in_time'] ?? '';
            $check_out_date = $req_data['check_out_date'] ?? '';
            $check_out_time = $req_data['check_out_time'] ?? '';
            
            $check_in_disp = $check_in_date
                ? date('d M Y', strtotime($check_in_date))
                : 'N/A';
            
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
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $headers .= "From: SCL Dormitory <no-reply@scl-dormitory.com>\r\n";

            // ========= SEND EMAIL TO GUEST + REQUESTER =========
              if (!empty($req_data['email'])) {
                  $to       = $req_data['email'];         // guest email
                  $g_email  = $to;
                  $g_name   = $req_data['guest_name'];
                  $status_is_approved = ($status === 'Approved');
              
                  // ----- requester info (login user who created request) -----
                  $requester_name  = $req_data['requested_by'];
                  $requester_email = '';
              
                  if (!empty($requester_name)) {
                      $rq_sql = mysqli_query(
                          $conn,
                          "SELECT email 
                           FROM users 
                           WHERE UserName = '" . mysqli_real_escape_string($conn, $requester_name) . "' 
                           LIMIT 1"
                      );
                      if ($rq_sql && mysqli_num_rows($rq_sql) > 0) {
                          $requester_email = mysqli_fetch_assoc($rq_sql)['email'];
                      }
                  }
              
                  // ---------- Guest mail ----------
                  if ($status_is_approved) {
              
                      $subject_guest = "Your dormitory visit request #$id has been approved";
              
                      $msg_guest  = "Dear $g_display_name,\r\n\r\n";
                      $msg_guest .= "We are happy to inform you that your dormitory visit request (Ref #$id) has been APPROVED.\r\n\r\n";
                      $msg_guest .= "Visit details:\r\n";
                      $msg_guest .= "- Check-in : $check_in_disp\r\n";
                      if ($checkout_disp !== '') {
                      $msg_guest .= "- Planned Check-out : $checkout_disp\r\n";
                  }
                      if ($dept   !== '') $msg_guest .= "- Department: $dept\r\n";
                      if ($purpose!== '') $msg_guest .= "- Purpose: $purpose\r\n";
                      $msg_guest .= "\r\n";
                      $msg_guest .= "Our team is looking forward to welcoming you to the SCL Dormitory.\r\n";
                      $msg_guest .= "If you need any further assistance, please feel free to contact the dormitory office.\r\n\r\n";
                      $msg_guest .= "Warm regards,\r\n";
                      $msg_guest .= "SCL Dormitory Management";
              
                  } else {
              
                      $subject_guest = "Update on your dormitory visit request #$id";
              
                      $msg_guest  = "Dear $g_display_name,\r\n\r\n";
                      $msg_guest .= "Thank you for your interest in staying at the SCL Dormitory.\r\n";
                      $msg_guest .= "After careful review, we are unable to approve your visit request (Ref #$id) at this time.\r\n\r\n";
                      $msg_guest .= "Reason:\r\n";
                      $msg_guest .= $reason . "\r\n\r\n";
                      $msg_guest .= "We understand this may be disappointing and we truly appreciate your understanding.\r\n";
                      $msg_guest .= "You are always welcome to submit a new request in the future if your plans change.\r\n\r\n";
                      $msg_guest .= "With best regards,\r\n";
                      $msg_guest .= "SCL Dormitory Management";
                  }
              
                  // Guest ke mail
                  @mail($g_email, $subject_guest, $msg_guest, $headers);
              
                  // ---------- Requester mail (copy) ----------
                  if (!empty($requester_email)) {
              
                      // guest ?? requester ??? ??? ?? (same email ?? same ???) ????? ????? ???? ????? ??
                      $same_person =
                          (strcasecmp(trim($requester_email), trim($g_email)) === 0) ||
                          (strcasecmp(trim($requester_name), trim($g_name)) === 0);
              
                      if (!$same_person) {
              
                          if ($status_is_approved) {
                              $subj_req = "Copy: Guest $g_name visit request #$id approved";
              
                              $msg_req  = "Dear $requester_name,\r\n\r\n";
                              $msg_req .= "The dormitory visit request you submitted for $g_name (Ref #$id) has been APPROVED.\r\n\r\n";
                              $msg_req .= "Guest Details:\r\n";
                              $msg_req .= "Guest Name       : $g_display_name\r\n";
                              $msg_req .= "Check-in       : $check_in_disp\r\n";
                              if ($checkout_disp !== '') {
                              $msg_req .= "Planned Check-out: $checkout_disp\r\n";
                          }
                              if ($dept   !== '') $msg_req .= "Department  : $dept\r\n";
                              if ($purpose!== '') $msg_req .= "Purpose     : $purpose\r\n";
                              $msg_req .= "\r\nBest regards,\r\nSCL Dormitory Management Team";
                          } else {
                              $subj_req = "Copy: Guest $g_name visit request #$id was not approved";
              
                              $msg_req  = "Dear $requester_name,\r\n\r\n";
                              $msg_req .= "The dormitory visit request you submitted for $g_name (Ref #$id) could not be approved.\r\n\r\n";
                              $msg_req .= "Reason provided:\r\n";
                              $msg_req .= $reason . "\r\n\r\n";
                              $msg_req .= "Best regards,\r\nSCL Dormitory Management Team";
                          }
              
                          @mail($requester_email, $subj_req, $msg_req, $headers);
                      }
                  }
              }


            // ========= SEND EMAIL TO STAFF / ADMIN (Only on Approval) =========
            if ($status === 'Approved') {
                // dhore ?????? users ?????? role ??????? ??? user_role
                $staff_admin_sql = "
                    SELECT email 
                    FROM users 
                    WHERE user_role IN ('admin', 'superadmin', 'staff') 
                      AND email IS NOT NULL 
                      AND email != ''
                ";
                $staff_result = mysqli_query($conn, $staff_admin_sql);

                if ($staff_result && mysqli_num_rows($staff_result) > 0) {
                    $staff_subject = "APPROVED: Visit Request #$id ready for booking";

                    $staff_msg  = "SCL DORMITORY MANAGEMENT SYSTEM\r\n";
                    $staff_msg .= "Internal Notification\r\n";
                    $staff_msg .= "==================================================\r\n\r\n";

                    $staff_msg .= "Dear Authorization Team,\r\n\r\n";
                    $staff_msg .= "A visit request has been APPROVED and is now pending room allocation.\r\n\r\n";

                    $staff_msg .= "VISITOR INFORMATION\r\n";
                    $staff_msg .= "--------------------------------------------------\r\n";
                    $staff_msg .= "Ref. ID        : #$id\r\n";
                    $staff_msg .= "Guest Name     : $g_display_name\r\n";
                    if ($g_phone !== '') {
                        $staff_msg .= "Contact        : $g_phone\r\n";
                    }
                    if ($dept !== '') {
                        $staff_msg .= "Department     : $dept\r\n";
                    }
                    if ($purpose !== '') {
                        $staff_msg .= "Visit Purpose  : $purpose\r\n";
                    }
                    $staff_msg .= "--------------------------------------------------\r\n\r\n";

                    $staff_msg .= "APPROVAL DETAILS\r\n";
                    $staff_msg .= "--------------------------------------------------\r\n";
                    $staff_msg .= "Check-in       : $check_in_disp\r\n";
                    if ($checkout_disp !== '') {
                    $staff_msg .= "Planned Out    : $checkout_disp\r\n";
                }
                    $staff_msg .= "Approved By    : $approver\r\n";
                    $staff_msg .= "Status         : APPROVED (Pending Booking)\r\n";
                    $staff_msg .= "--------------------------------------------------\r\n\r\n";

                    $staff_msg .= "ACTION REQUIRED:\r\n";
                    $staff_msg .= "Please log in to the system to assign a room for this guest.\r\n\r\n";

                    $staff_msg .= "Access Dashboard:\r\n";
                    $staff_msg .= "http://" . $_SERVER['HTTP_HOST'] . "/dormitory/index.php\r\n\r\n";

                    $staff_msg .= "==================================================\r\n";
                    $staff_msg .= "Note: This is an automated system notification.\r\n";
                    $staff_msg .= "SCL Dormitory Management Team\r\n";
                    $staff_msg .= "==================================================";

                    while ($staff = mysqli_fetch_assoc($staff_result)) {
                        @mail($staff['email'], $staff_subject, $staff_msg, $headers);
                    }
                }
            }
        }
    }

    header("Location: manage_requests.php");
    exit;
}

// Sidebar Badge Logic
$pending_query = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM visit_requests WHERE status='Pending'");
$pending_count = mysqli_fetch_assoc($pending_query)['cnt'];

$sql    = "SELECT * FROM visit_requests ORDER BY id DESC";
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
            <?php if(isset($_GET['error']) && $_GET['error'] == 'noreason'): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    Rejection reason is required. The request was not rejected.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if(isset($_GET['msg']) && $_GET['msg'] == 'deleted'): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    Request Deleted Successfully!
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

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
                            // Title + Name
                            $guest_title = htmlspecialchars($row['guest_title'] ?? '', ENT_QUOTES, 'UTF-8');
                            $guest_name  = htmlspecialchars($row['guest_name'] ?? '', ENT_QUOTES, 'UTF-8');
                            $display_name = trim($guest_title . ' ' . $guest_name);
                        
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
                        <tr>
                            <td>#<?php echo $row['id']; ?></td>
                            <td>
                                <strong><?php echo $display_name; ?></strong><br>
                                <small><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($row['phone']); ?></small><br>
                                <small class="text-muted"><?php echo htmlspecialchars($row['designation']); ?></small>
                            </td>
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
                                    $s = $row['status'];
                                    if(in_array($s, ['Booked', 'Completed'])) {
                                        $s = 'Approved';
                                    }
                                    $cls = ($s == 'Pending') ? 'warning' : (($s == 'Approved') ? 'success' : 'danger');
                                    echo "<span class='badge bg-$cls'>$s</span>";
                                ?>
                            </td>
                            <td>
                                <?php 
                                    if (!empty($row['approved_by'])) {
                                        echo htmlspecialchars($row['approved_by']);
                                    } else {
                                        echo '<span class="text-muted">-</span>';
                                    }
                                ?>
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
                                                data-guest="<?php echo htmlspecialchars($display_name); ?>">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted small me-2">
                                            <?php 
                                                $final_status = $row['status'];
                                                if(in_array($final_status, ['Booked', 'Completed'])) {
                                                    echo "Approved";
                                                } else {
                                                    echo $final_status;
                                                }
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
