<?php
session_start();
require_once('db.php');
require_once('header.php');

// Security Check (Admin & Staff Access)
if (!isset($_SESSION['UserName']) || !in_array($_SESSION['UserRole'], ['admin', 'staff', 'superadmin'])) {
    header("Location: index.php");
    exit;
}

$userName = $_SESSION['UserName'];
$userRole = $_SESSION['UserRole']; 

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ✅ Toast Message Handling
$toast_msg = "";
$toast_type = "";

// --- DELETE LOGIC (Only Admin) ---
if (isset($_GET['delete']) && $_GET['delete'] == '1' && isset($_GET['id']) && isset($_GET['csrf'])) {
    
    // CSRF Protection
    if ($_GET['csrf'] !== $_SESSION['csrf_token']) {
        die("Invalid CSRF token.");
    }
    
    if (!in_array($userRole, ['admin', 'superadmin'])) {
        header("Location: checkout_history.php?error=access_denied");
        exit;
    }

    $record_id = (int)$_GET['id'];
    
    // Prepared statement for security
    $stmt = mysqli_prepare($conn, "SELECT booking_id FROM checked_out_guests WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $record_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $booking_check = mysqli_fetch_assoc($result);
    
    // Delete from history
    $stmt2 = mysqli_prepare($conn, "DELETE FROM checked_out_guests WHERE id = ?");
    mysqli_stmt_bind_param($stmt2, "i", $record_id);
    
    if (mysqli_stmt_execute($stmt2)) {
        // Delete from bookings table too
        if ($booking_check && $booking_check['booking_id']) {
            $stmt3 = mysqli_prepare($conn, "DELETE FROM bookings WHERE id = ?");
            mysqli_stmt_bind_param($stmt3, "i", $booking_check['booking_id']);
            mysqli_stmt_execute($stmt3);
        }
        header("Location: checkout_history.php?deleted=1");
    } else {
        header("Location: checkout_history.php?error=delete_failed");
    }
    exit;
}

// Catch redirection messages for Toast
if(isset($_GET['deleted']) && $_GET['deleted'] == '1') {
    $toast_msg = "Record deleted successfully!";
    $toast_type = "success";
} elseif(isset($_GET['error'])) {
    if($_GET['error'] == 'access_denied') {
        $toast_msg = "Access Denied! Only Admin can delete records.";
        $toast_type = "danger";
    } elseif($_GET['error'] == 'delete_failed') {
        $toast_msg = "Failed to delete record.";
        $toast_type = "danger";
    }
}

// ✅ FILTERS: DATE RANGE + ROOM + GUEST NAME + REF NO
$from_date = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$to_date = isset($_GET['to_date']) ? trim($_GET['to_date']) : '';
$room_filter = isset($_GET['room']) ? trim($_GET['room']) : '';
$guest_filter = isset($_GET['guest_name']) ? trim($_GET['guest_name']) : '';
$ref_filter = isset($_GET['ref_no']) ? trim($_GET['ref_no']) : '';

if (!empty($room_filter) && !preg_match('/^[A-Z0-9-]+$/i', $room_filter)) {
    $room_filter = '';
}

// Build WHERE clause
$where_conditions = [];

// Date Filter
if (!empty($from_date) && !empty($to_date)) {
    $where_conditions[] = "DATE(cog.check_out_date) BETWEEN '" . mysqli_real_escape_string($conn, $from_date) . "' AND '" . mysqli_real_escape_string($conn, $to_date) . "'";
} elseif (!empty($from_date)) {
    $where_conditions[] = "DATE(cog.check_out_date) >= '" . mysqli_real_escape_string($conn, $from_date) . "'";
} elseif (!empty($to_date)) {
    $where_conditions[] = "DATE(cog.check_out_date) <= '" . mysqli_real_escape_string($conn, $to_date) . "'";
}

// Room Filter
if (!empty($room_filter)) {
    $where_conditions[] = "cog.room_number = '" . mysqli_real_escape_string($conn, $room_filter) . "'";
}

// Guest Filter
if (!empty($guest_filter)) {
    $guest_esc = mysqli_real_escape_string($conn, $guest_filter);
    $where_conditions[] = "(cog.guest_name LIKE '%$guest_esc%' OR b.secondary_guest_name LIKE '%$guest_esc%')";
}

// Ref Filter
if (!empty($ref_filter)) {
    $where_conditions[] = "b.request_ref_id = '" . mysqli_real_escape_string($conn, $ref_filter) . "'";
}

$where_clause = "";
if (count($where_conditions) > 0) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

// ==========================================
// ✅ PAGINATION SETUP
// ==========================================
$limit = 10; // Number of records per page
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// 1. Get total rows for calculation
$count_sql = "SELECT COUNT(cog.id) as total 
              FROM checked_out_guests cog 
              LEFT JOIN bookings b ON cog.booking_id = b.id 
              $where_clause";
$count_res = mysqli_query($conn, $count_sql);
$total_rows = mysqli_fetch_assoc($count_res)['total'];
$total_pages = ceil($total_rows / $limit);

// 2. Main Query with LIMIT and OFFSET
$sql = "SELECT cog.*, 
               b.arrival_time, 
               b.guest_email as primary_email, 
               b.secondary_guest_email as sec_email_booking,
               b.secondary_guest_phone as sec_phone_booking,
               b.request_ref_id
        FROM checked_out_guests cog 
        LEFT JOIN bookings b ON cog.booking_id = b.id 
        $where_clause
        ORDER BY cog.check_out_date DESC
        LIMIT $limit OFFSET $offset";

$result = mysqli_query($conn, $sql);

// Retain query parameters for pagination links
$q_params = $_GET;
unset($q_params['page']); // Remove old page parameter so it doesn't duplicate
$query_string = http_build_query($q_params);
if (!empty($query_string)) {
    $query_string = '&' . $query_string;
}

// ✅ Auto Shuffle Animation Logic
$animations = ['anim-bounce', 'anim-zoom', 'anim-fade'];
$selected_anim = $animations[array_rand($animations)];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout History - SCL DMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f8f9fa; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; }

        /* ✅ Wrapper layout to handle sidebar and content */
        .wrapper { display: flex; width: 100%; min-height: 100vh; }
        
        .main-content {
            flex-grow: 1;
            padding: 15px;
            width: 100%;
            overflow-x: hidden;
            transition: all 0.3s ease;
        }

        .header { text-align: center; background: linear-gradient(135deg, #224895, #2f6b96); color: white; padding: 30px; border-radius: 8px;}
        .table-container { max-height: 70vh; overflow: auto; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.08); }
        .delete-btn { color: #dc3545; font-size: 1.2rem; transition: all 0.3s; cursor: pointer; }
        .delete-btn:hover { color: #b02a37; transform: scale(1.2); }
        .time-badge { font-weight: 500; font-size: 0.85em; }
        .sec-tag { font-size: 0.72rem; }
        .email-text { font-size: 0.8rem; color: #555; display: block; margin-top: 2px;}
        .details-text { font-size: 0.8rem; color: #555; display: block; line-height: 1.3; }
        .details-icon { font-size: 0.75rem; width: 15px; text-align: center; color: #888; margin-right: 3px; }
        .call-link { text-decoration: none; color: inherit; transition: color 0.2s; }
        .call-link:hover { color: #198754; text-decoration: underline; }

        /* ✅ Auto Shuffle Animations */
        .anim-bounce { animation: bounceIn 0.8s cubic-bezier(0.25, 0.46, 0.45, 0.94) both; }
        .anim-zoom { animation: zoomIn 0.6s ease-out both; }
        .anim-fade { animation: fadeIn 0.8s ease-out both; }

        @keyframes bounceIn {
            0% { transform: scale(0.85); opacity: 0; }
            50% { transform: scale(1.02); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }
        @keyframes zoomIn {
            0% { transform: scale(0.5); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ✅ Responsive Mobile Adjustments */
        @media (max-width: 768px) {
            .wrapper { flex-direction: column; }
            .header { padding: 15px; }
            .table-container { max-height: unset; overflow-x: auto; }
            .main-content { padding: 10px; }
        }
    </style>
</head>
<body>

    <!-- ✅ Floating Toast Alert Container -->
    <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1055;">
        <div id="liveToast" class="toast align-items-center border-0 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body fw-bold fs-6" id="toastMessage"></div>
                <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close" id="toastCloseBtn"></button>
            </div>
        </div>
    </div>

<div class="wrapper">
    <!-- Sidebar content comes here automatically from header.php -->

    <!-- Main Content layout adjustment start -->
    <div class="main-content">
        <div class="header shadow">
            <div class="container-fluid">
                <h2><i class="fas fa-history me-3"></i>Checkout History</h2>
                <p class="lead mb-0">Complete guest checkout records</p>
                
                <!-- ✅ ENHANCED FILTER FORM -->
                <div class="card bg-white text-dark mt-4 shadow">
                    <div class="card-body">
                        <form method="GET" action="checkout_history.php" class="row g-3 align-items-end mb-3">
                            <div class="col-6 col-md-2">
                                <label class="form-label fw-bold"><i class="fas fa-hashtag me-2"></i>Ref No</label>
                                <input type="text" name="ref_no" class="form-control" placeholder="e.g. 1024" value="<?php echo htmlspecialchars($ref_filter, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label fw-bold"><i class="fas fa-user me-2"></i>Guest Name</label>
                                <input type="text" name="guest_name" class="form-control" placeholder="Search by name" value="<?php echo htmlspecialchars($guest_filter, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label fw-bold"><i class="fas fa-door-open me-2"></i>Room</label>
                                <input type="text" name="room" class="form-control" placeholder="e.g. 101" value="<?php echo htmlspecialchars($room_filter, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label fw-bold"><i class="fas fa-calendar-alt me-2"></i>From</label>
                                <input type="date" name="from_date" class="form-control" value="<?php echo htmlspecialchars($from_date, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label fw-bold"><i class="fas fa-calendar-alt me-2"></i>To Date</label>
                                <input type="date" name="to_date" class="form-control" value="<?php echo htmlspecialchars($to_date, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>

                            <!-- BUTTONS ROW -->
                            <div class="col-md-12">
                                <div class="row g-2 align-items-center mt-2">
                                    <?php 
                                    $export_params = http_build_query([
                                        'from_date' => $from_date, 
                                        'to_date' => $to_date,
                                        'room' => $room_filter,
                                        'guest_name' => $guest_filter,
                                        'ref_no' => $ref_filter
                                    ]);
                                    ?>
                                    <div class="col-6 col-md-4">
                                        <a href="export_history_pdf.php?<?php echo $export_params; ?>" class="btn btn-danger w-100 shadow-sm" target="_blank">
                                            <i class="fas fa-file-pdf me-2"></i>PDF
                                        </a>
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <a href="export_history_excel.php?<?php echo $export_params; ?>" class="btn btn-success w-100 shadow-sm">
                                            <i class="fas fa-file-excel me-2"></i>Excel
                                        </a>
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <button type="submit" class="btn btn-primary w-100 shadow-sm">
                                            <i class="fas fa-search me-2"></i>Search
                                        </button>
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <a href="checkout_history.php" class="btn btn-secondary w-100 shadow-sm">
                                            <i class="fas fa-redo me-2"></i>Reset
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if($total_rows > 0): ?>
                    <div class="mt-3 text-start">
                        <span class="badge bg-light text-dark fs-6 px-3 py-2 shadow-sm mb-1">
                            <i class="fas fa-list me-2"></i>Total: <?php echo $total_rows; ?> Records
                        </span>
                        <?php if(!empty($ref_filter)): ?>
                            <span class="badge bg-primary fs-6 px-3 py-2 ms-2 mb-1 shadow-sm">Ref: <?php echo htmlspecialchars($ref_filter, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                        <?php if(!empty($guest_filter)): ?>
                            <span class="badge bg-secondary fs-6 px-3 py-2 ms-2 mb-1 shadow-sm">Guest: <?php echo htmlspecialchars($guest_filter, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                        <?php if(!empty($room_filter)): ?>
                            <span class="badge bg-info text-dark fs-6 px-3 py-2 ms-2 mb-1 shadow-sm">Room: <?php echo htmlspecialchars($room_filter, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ✅ Apply Auto Shuffle Animation Here -->
        <div class="container-fluid mt-4 <?php echo $selected_anim; ?>">
            
            <?php if(!$result || mysqli_num_rows($result) == 0): ?>
                <div class="row justify-content-center">
                    <div class="col-md-8 text-center py-5 bg-white rounded shadow-sm">
                        <i class="fas fa-search bg-light p-4 rounded-circle fa-3x text-muted mb-4 shadow-sm"></i>
                        <h4 class="text-muted fw-bold">No Checkout Records Found</h4>
                        <p class="text-muted mb-4">Try adjusting your search filters or check active bookings.</p>
                        <a href="checkout_list.php" class="btn btn-primary btn-lg px-4 shadow-sm">
                            <i class="fas fa-clipboard-list me-2"></i>Active Bookings
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="card shadow border-0">
                    <div class="card-header bg-white py-3 border-0">
                        <div class="row align-items-center">
                            <div class="col-6 col-md-8">
                                <h5 class="mb-0 text-primary fw-bold">
                                    <i class="fas fa-table me-2"></i>Records List 
                                    <small class="text-muted fs-6">(Page <?php echo $page; ?> of <?php echo $total_pages; ?>)</small>
                                </h5>
                            </div>
                            <div class="col-6 col-md-4 text-end">
                                <a href="checkout_list.php" class="btn btn-outline-primary btn-sm fw-bold shadow-sm">
                                    <i class="fas fa-plus me-1"></i>New Checkout
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive table-container">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-dark sticky-top">
                                <tr>
                                    <th width="40">#</th>
                                    <th>Guest(s) & Details</th>
                                    <th>Contact Info</th>
                                    <th>Room</th>
                                    <th>Check-in</th>
                                    <th>Check-out</th>
                                    <th>Duration</th>
                                    <th>Dept</th>
                                    <?php if(in_array($userRole, ['admin', 'superadmin'])): ?>
                                        <th width="60" class="text-center">Action</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $sl = $offset + 1; // Start SL number depending on current page
                                while($row = mysqli_fetch_assoc($result)): 
                                
                                    $guestTitle  = htmlspecialchars($row['guest_title'] ?? '', ENT_QUOTES, 'UTF-8');
                                    $guestName   = htmlspecialchars($row['guest_name']  ?? '', ENT_QUOTES, 'UTF-8');
                                    $displayName = trim($guestTitle . ' ' . $guestName);
                                
                                    $secTitle    = htmlspecialchars($row['secondary_guest_title'] ?? '', ENT_QUOTES, 'UTF-8');
                                    $secName     = htmlspecialchars($row['secondary_guest_name']  ?? '', ENT_QUOTES, 'UTF-8');
                                    $secDispName = trim($secTitle . ' ' . $secName);
                                    
                                    $pEmail = !empty($row['primary_email']) ? $row['primary_email'] : 'N/A';
                                    $sEmail = !empty($row['secondary_guest_email']) ? $row['secondary_guest_email'] : (!empty($row['sec_email_booking']) ? $row['sec_email_booking'] : '');
                                    $secPhone = !empty($row['secondary_guest_phone']) ? $row['secondary_guest_phone'] : (!empty($row['sec_phone_booking']) ? $row['sec_phone_booking'] : '');
                                    
                                    $desig    = isset($row['designation']) ? $row['designation'] : '';
                                    $addr     = isset($row['address']) ? $row['address'] : '';
                                    $secDesig = isset($row['secondary_guest_designation']) ? $row['secondary_guest_designation'] : '';
                                    $secAddr  = isset($row['secondary_guest_address']) ? $row['secondary_guest_address'] : '';
                                ?>
                                <tr>
                                    <td class="fw-bold"><?php echo $sl++; ?></td>
                                    
                                    <td>
                                        <!-- ✅ Reference Number -->
                                        <?php if(!empty($row['request_ref_id'])): ?>
                                            <span class="badge bg-secondary mb-1 shadow-sm"><i class="fas fa-hashtag me-1"></i>Ref: <?php echo htmlspecialchars($row['request_ref_id']); ?></span><br>
                                        <?php endif; ?>

                                        <!-- Primary Guest Name -->
                                        <div class="fw-bold text-primary fs-6"><?php echo $displayName; ?></div>
                                        
                                        <!-- Primary Designation & Address -->
                                        <?php if($desig): ?>
                                            <span class="details-text"><i class="fas fa-briefcase details-icon"></i> <?php echo htmlspecialchars($desig, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endif; ?>
                                        <?php if($addr): ?>
                                            <span class="details-text"><i class="fas fa-map-marker-alt details-icon"></i> <?php echo htmlspecialchars($addr, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endif; ?>
                                        
                                        <?php if(!empty($row['id_proof'])): ?>
                                            <small class="text-muted d-block mt-1"><i class="fas fa-id-card details-icon"></i> ID: <?php echo htmlspecialchars($row['id_proof'], ENT_QUOTES, 'UTF-8'); ?></small>
                                        <?php endif; ?>

                                        <!-- Secondary Guest -->
                                        <?php if(!empty($row['secondary_guest_name'])): ?>
                                            <div class="mt-2 pt-2 border-top">
                                                <span class="badge bg-info text-dark sec-tag shadow-sm">Secondary</span>
                                                <span class="ms-1 fw-semibold">
                                                    <?php echo $secDispName; ?>
                                                </span>
                                                
                                                <?php if($secDesig): ?>
                                                    <span class="details-text mt-1 ms-1"><i class="fas fa-briefcase details-icon"></i> <?php echo htmlspecialchars($secDesig, ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php endif; ?>
                                                <?php if($secAddr): ?>
                                                    <span class="details-text ms-1"><i class="fas fa-map-marker-alt details-icon"></i> <?php echo htmlspecialchars($secAddr, ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <div>
                                            <a href="tel:<?php echo htmlspecialchars($row['phone'], ENT_QUOTES, 'UTF-8'); ?>" class="call-link fw-bold" title="Call">
                                                <i class="fas fa-phone text-success me-1"></i> <?php echo htmlspecialchars($row['phone'], ENT_QUOTES, 'UTF-8'); ?>
                                            </a>

                                            <?php if($pEmail != 'N/A'): ?>
                                                <br><i class="fas fa-envelope text-primary me-1"></i> <span class="email-text d-inline"><?php echo htmlspecialchars($pEmail, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <?php if(!empty($row['secondary_guest_name'])): ?>
                                            <div class="mt-2 pt-2 border-top">
                                                <?php if(!empty($secPhone)): ?>
                                                    <a href="tel:<?php echo htmlspecialchars($secPhone, ENT_QUOTES, 'UTF-8'); ?>" class="call-link fw-bold" title="Call">
                                                        <i class="fas fa-phone text-success me-1"></i> <?php echo htmlspecialchars($secPhone, ENT_QUOTES, 'UTF-8'); ?> <span class="text-muted">(Sec)</span>
                                                    </a>
                                                <?php else: ?>
                                                    <small class="text-muted"><i class="fas fa-phone-slash me-1"></i>No Phone</small>
                                                <?php endif; ?>

                                                <?php if(!empty($sEmail)): ?>
                                                    <br><i class="fas fa-envelope text-primary me-1"></i> <span class="email-text d-inline"><?php echo htmlspecialchars($sEmail, ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td><span class="badge bg-warning text-dark fs-6 shadow-sm"><i class="fas fa-door-closed me-1"></i><?php echo htmlspecialchars($row['room_number'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    
                                    <td>
                                        <div class="fw-medium"><?php echo date('M d, Y', strtotime($row['check_in_date'])); ?></div>
                                        <span class="badge bg-info time-badge text-dark shadow-sm">
                                            <i class="far fa-clock me-1"></i>
                                            <?php 
                                            $in_time = date('h:i A', strtotime($row['check_in_date']));
                                            if($in_time == '12:00 AM' && !empty($row['arrival_time'])) {
                                                echo date('h:i A', strtotime($row['arrival_time']));
                                            } else {
                                                echo $in_time;
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    
                                    <td>
                                        <div class="fw-medium"><?php echo date('M d, Y', strtotime($row['check_out_date'])); ?></div>
                                        <span class="badge bg-success time-badge shadow-sm">
                                            <i class="far fa-clock me-1"></i>
                                            <?php echo date('h:i A', strtotime($row['check_out_date'])); ?>
                                        </span>
                                    </td>
                                    
                                    <td><span class="badge bg-primary fs-6 shadow-sm"><?php echo (int)$row['total_days']; ?> day(s)</span></td>
                                    
                                    <td><span class="badge bg-secondary shadow-sm"><?php echo htmlspecialchars($row['department'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    
                                    <?php if(in_array($userRole, ['admin', 'superadmin'])): ?>
                                        <td class="text-center">
                                            <a href="?delete=1&id=<?php echo $row['id']; ?>&csrf=<?php echo urlencode($_SESSION['csrf_token']); ?>" 
                                               class="delete-btn btn btn-sm p-2 rounded-circle"
                                               onclick="return confirm('⚠️ Are you sure? This will delete the record permanently.')"
                                               title="Delete Record">
                                                <i class="fas fa-trash-alt"></i>
                                            </a>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ✅ PAGINATION COMPONENT -->
                <?php if($total_pages > 1): ?>
                <nav class="mt-4 mb-2">
                    <ul class="pagination justify-content-center shadow-sm">
                        <!-- Previous Link -->
                        <li class="page-item <?php if($page <= 1){ echo 'disabled'; } ?>">
                            <a class="page-link" href="<?php if($page > 1){ echo "?page=".($page-1).$query_string; } else { echo '#'; } ?>">Previous</a>
                        </li>
                        
                        <!-- Page Numbers List -->
                        <?php 
                            // Page logic logic to prevent showing too many buttons
                            $start_loop = max(1, $page - 2);
                            $end_loop = min($total_pages, $page + 2);
                            
                            if ($start_loop > 1) {
                                echo '<li class="page-item"><a class="page-link" href="?page=1'.$query_string.'">1</a></li>';
                                if ($start_loop > 2) {
                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                }
                            }
                            
                            for($i = $start_loop; $i <= $end_loop; $i++): 
                        ?>
                            <li class="page-item <?php if($page == $i){ echo 'active'; } ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?><?php echo $query_string; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; 
                        
                            if ($end_loop < $total_pages) {
                                if ($end_loop < $total_pages - 1) {
                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                }
                                echo '<li class="page-item"><a class="page-link" href="?page='.$total_pages.$query_string.'">'.$total_pages.'</a></li>';
                            }
                        ?>
                        
                        <!-- Next Link -->
                        <li class="page-item <?php if($page >= $total_pages){ echo 'disabled'; } ?>">
                            <a class="page-link" href="<?php if($page < $total_pages){ echo "?page=".($page+1).$query_string; } else { echo '#'; } ?>">Next</a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>

            <?php endif; ?>

            <div class="text-center mt-5 mb-5">
                <div class="row justify-content-center g-3">
                    <div class="col-md-3">
                        <a href="checkout_list.php" class="btn btn-primary btn-lg w-100 shadow-sm fw-bold">
                            <i class="fas fa-clipboard-list me-2"></i>Active Bookings
                        </a>
                    </div>
                    <div class="col-md-3">
                        <a href="index.php" class="btn btn-outline-secondary btn-lg w-100 shadow-sm fw-bold bg-white">
                            <i class="fas fa-home me-2"></i>Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Main Content layout adjustment end -->
</div> <!-- end wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- ✅ Toast Initialization Script -->
<?php if($toast_msg != ""): ?>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        var toastEl = document.getElementById('liveToast');
        var toastBody = document.getElementById('toastMessage');
        var closeBtn = document.getElementById('toastCloseBtn');
        var type = '<?php echo $toast_type; ?>';
        
        // Set colors based on success or danger
        toastEl.className = 'toast align-items-center border-0 shadow-lg bg-' + type + (type === 'warning' ? ' text-dark' : ' text-white');
        closeBtn.className = 'btn-close me-2 m-auto ' + (type === 'warning' ? '' : 'btn-close-white');
        
        // Set Icon
        var icon = type === 'success' ? 'fa-check-circle' : (type === 'danger' ? 'fa-exclamation-triangle' : 'fa-info-circle');
        toastBody.innerHTML = '<i class="fas ' + icon + ' me-2"></i> <?php echo addslashes($toast_msg); ?>';
        
        var toast = new bootstrap.Toast(toastEl, { delay: 4000 });
        toast.show();
    });
</script>
<?php endif; ?>

</body>
</html>
