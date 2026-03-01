<?php
session_start();

// Debug à¦¦à¦°à¦•à¦¾à¦° à¦¹à¦²à§‡ 1 à¦•à¦°à§‹
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt');

require_once('db.php');
require_once('header.php');

if (!isset($_SESSION['UserName'])) {
    header("Location: login.php");
    exit;
}

$user = $_SESSION['UserName'];
$role = $_SESSION['UserRole'] ?? 'user';

// à¦¶à§à¦§à§ staff, admin, superadmin à¦¦à§‡à¦–à¦¬à§‡
if (!in_array($role, ['staff','admin','superadmin'])) {
    header("Location: index.php");
    exit;
}

// CSRF Token Generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

date_default_timezone_set('Asia/Dhaka');

// --------- DELETE LOGIC (Only Admin/Superadmin) ----------
if (isset($_POST['delete_cancelled']) && in_array($role, ['admin', 'superadmin'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid CSRF token.");
    }
    
    $delete_id = (int)$_POST['delete_id'];
    
    if ($delete_id > 0) {
        $stmt = mysqli_prepare($conn, "DELETE FROM cancelled_bookings WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $delete_id);
        
        if (mysqli_stmt_execute($stmt)) {
            header("Location: cancel_list.php?delete_success=1");
            exit;
        } else {
            header("Location: cancel_list.php?error=delete_failed");
            exit;
        }
    }
}

// --------- FILTERS ----------
$filter_name = isset($_GET['guest_name']) ? trim($_GET['guest_name']) : ''; // âœ… Name Filter
$filter_room = isset($_GET['room']) ? trim($_GET['room']) : '';
$filter_ref  = isset($_GET['ref_no']) ? trim($_GET['ref_no']) : ''; 
$filter_from = isset($_GET['from']) ? trim($_GET['from']) : '';
$filter_to   = isset($_GET['to'])   ? trim($_GET['to'])   : '';

// Validate format
if (!empty($filter_room) && !preg_match('/^[A-Z0-9-]+$/i', $filter_room)) {
    $filter_room = '';
}
if (!empty($filter_ref) && !preg_match('/^[0-9]+$/', $filter_ref)) {
    $filter_ref = '';
}

// Base query with prepared statement
$params = [];
$types = "";

$sql = "SELECT * FROM cancelled_bookings WHERE 1=1";

// Name filter (Search in both main and secondary guest)
if ($filter_name !== '') {
    $sql .= " AND (guest_name LIKE ? OR secondary_guest_name LIKE ?)";
    $like_name = "%" . $filter_name . "%";
    $params[] = $like_name;
    $params[] = $like_name;
    $types .= "ss";
}

// Room filter
if ($filter_room !== '') {
    $sql .= " AND room_number = ?";
    $params[] = $filter_room;
    $types .= "s";
}

// Ref filter
if ($filter_ref !== '') {
    $sql .= " AND request_ref_id = ?";
    $params[] = $filter_ref;
    $types .= "i";
}

// Date range filter (cancel_date)
if ($filter_from !== '' && $filter_to !== '') {
    $sql .= " AND DATE(cancel_date) BETWEEN ? AND ?";
    $params[] = $filter_from;
    $params[] = $filter_to;
    $types .= "ss";
} elseif ($filter_from !== '') {
    $sql .= " AND DATE(cancel_date) >= ?";
    $params[] = $filter_from;
    $types .= "s";
} elseif ($filter_to !== '') {
    $sql .= " AND DATE(cancel_date) <= ?";
    $params[] = $filter_to;
    $types .= "s";
}

$sql .= " ORDER BY cancel_date DESC";

// Execute with prepared statement
$stmt = mysqli_prepare($conn, $sql);
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- Essential Meta Tag for Mobile Responsiveness -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Cancelled Bookings - SCL DMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; margin: 0; padding: 0; }
        .page-fade-in { animation: fadeIn 0.6s ease-in-out forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        /* Floating Toast */
        .toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; }
        .custom-toast { min-width: 300px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); border: none; border-radius: 8px; overflow: hidden; }

        /* í ¼í¼Ÿ Sidebar Styling for Desktop */
        .sidebar { 
            background-color: #1a2332; 
            color: white; 
            height: 100vh; 
            position: fixed; 
            top: 0;
            left: 0;
            width: 280px; 
            padding: 20px; 
            overflow-y: auto; 
            z-index: 1000;
            transition: transform 0.3s ease-in-out;
            box-shadow: 2px 0 10px rgba(0,0,0,0.2);
        }
        
        .sidebar-brand { font-size: 1.5rem; font-weight: 500; letter-spacing: 1px; margin-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 15px; text-align: center; }

        /* Logged in info box */
        .user-info-box { background-color: #2a3441; border-radius: 8px; padding: 15px; text-align: center; margin-bottom: 25px; }
        .user-info-box small { color: #a0aec0; }
        .user-info-box strong { display: block; font-size: 1.1rem; margin: 5px 0; }
        .badge-role { background-color: #ffc107; color: #000; font-weight: 700; border-radius: 12px; padding: 3px 10px; font-size: 0.75rem; letter-spacing: 0.5px; }

        /* Sidebar Buttons */
        .sidebar .btn { text-align: left; padding: 10px 15px; font-weight: 500; border-radius: 6px; margin-bottom: 8px; }
        .sidebar .btn i { width: 25px; text-align: center; }

        /* í ¼í¼Ÿ Mobile Navbar (Hidden on Desktop) */
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

        /* Content Area */
        .content { margin-left: 280px; padding: 30px; transition: margin-left 0.3s ease-in-out; }
        
        .email-text { font-size: 0.85rem; color: #666; word-break: break-all; }
        .details-text { font-size: 0.8rem; color: #555; display: block; line-height: 1.3; }
        .details-icon { font-size: 0.75rem; width: 15px; text-align: center; color: #888; margin-right: 3px; }
        tbody tr { transition: background-color 0.2s ease; }
        tbody tr:hover { background-color: #fff4f4 !important; }

        /* í ¼í¼Ÿ STRICT RESPONSIVE STYLES FOR MOBILE í ¼í¼Ÿ */
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
            .content { 
                margin-left: 0 !important; 
                padding: 15px !important; 
                width: 100%;
            }
            
            /* Show overlay when active */
            .sidebar-overlay.active { 
                display: block; 
                opacity: 1;
            }

            /* Adjust Filter Form for Mobile */
            form.row.g-2 .col-md-1, form.row.g-2 .col-md-2, form.row.g-2 .col-md-3 {
                margin-bottom: 10px;
            }
            form.row.g-2 .d-flex {
                flex-direction: row !important;
                gap: 10px;
            }
            form.row.g-2 .d-flex button, form.row.g-2 .d-flex a {
                width: 50%;
                margin-bottom: 0 !important;
            }

            /* Table Optimizations */
            .table-responsive { overflow-x: auto; }
            .card-header { flex-direction: column; text-align: center; gap: 10px; }
        }
    </style>
</head>
<body class="page-fade-in">

<!-- Floating Toast -->
<div class="toast-container">
    <?php if(isset($_GET['delete_success'])): ?>
    <div class="toast custom-toast show" role="alert" data-bs-delay="4000">
        <div class="toast-header bg-success text-white border-0">
            <i class="fas fa-check-circle me-2"></i><strong class="me-auto">Success</strong>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
        </div>
        <div class="toast-body bg-white text-dark fw-semibold">Record deleted successfully.</div>
    </div>
    <?php endif; ?>
    <?php if(isset($_GET['cancel_success'])): ?>
    <div class="toast custom-toast show" role="alert" data-bs-delay="4000">
        <div class="toast-header bg-success text-white border-0">
            <i class="fas fa-check-circle me-2"></i><strong class="me-auto">Success</strong>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
        </div>
        <div class="toast-body bg-white text-dark fw-semibold">Booking successfully cancelled!</div>
    </div>
    <?php endif; ?>
    <?php if(isset($_GET['error']) && $_GET['error'] === 'delete_failed'): ?>
    <div class="toast custom-toast show" role="alert" data-bs-delay="4000">
        <div class="toast-header bg-danger text-white border-0">
            <i class="fas fa-exclamation-triangle me-2"></i><strong class="me-auto">Error</strong>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
        </div>
        <div class="toast-body bg-white text-dark">Failed to delete record. Please try again.</div>
    </div>
    <?php endif; ?>
</div>

<!-- í ¼í¼Ÿ Mobile Navbar -->
<div class="mobile-navbar">
    <h5>SCL DMS</h5>
    <button class="menu-toggle-btn" id="menuToggleBtn"><i class="fas fa-bars"></i></button>
</div>

<!-- Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- í ¼í¼Ÿ Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="d-flex justify-content-between align-items-center d-md-none mb-3">
        <div class="sidebar-brand mb-0 border-0 pb-0 w-100 text-start">SCL DMS</div>
        <button class="btn btn-sm text-light border-0 p-0 m-0" id="closeSidebarBtn" style="width:auto; border:none !important;"><i class="fas fa-times fa-lg"></i></button>
    </div>

    <h4 class="mb-4 text-center sidebar-brand d-none d-md-block"><i class="fas fa-hotel me-2"></i>SCL DMS</h4>
    
    <div class="user-info-box">
        <small>Logged in as:</small>
        <strong><?php echo htmlspecialchars($user, ENT_QUOTES, 'UTF-8'); ?></strong>
        <span class="badge badge-role mt-1"><?php echo strtoupper(htmlspecialchars($role, ENT_QUOTES, 'UTF-8')); ?></span>
    </div>

    <a href="index.php" class="btn btn-outline-light w-100"><i class="fas fa-home me-2"></i>Dashboard</a>
    <a href="guest_request.php" class="btn btn-outline-info w-100 text-white"><i class="fas fa-paper-plane me-2"></i>Submit Request</a>
    <a href="my_requests.php" class="btn btn-outline-light w-100"><i class="fas fa-list-alt me-2"></i>My Sent Requests</a>

    <?php if(in_array($role, ['admin', 'superadmin', 'approver'])): ?>
        <a href="manage_requests.php" class="btn btn-outline-warning w-100 text-white"><i class="fas fa-tasks me-2"></i>Manage Requests</a>
    <?php endif; ?>

    <?php if(in_array($role, ['staff','admin','superadmin'])): ?>
        <a href="checkout_list.php" class="btn btn-outline-light w-100"><i class="fas fa-clipboard-check me-2"></i>Active Bookings</a>
        <a href="checkout_history.php" class="btn btn-outline-light w-100"><i class="fas fa-history me-2"></i>Checkout History</a>
        <a href="cancel_list.php" class="btn btn-primary w-100"><i class="fas fa-ban me-2"></i>Cancelled List</a>
    <?php endif; ?>

    <?php if(in_array($role, ['admin', 'superadmin'])): ?>
        <hr class="border-light opacity-50 my-3">
        <a href="admin_dashboard.php" class="btn btn-warning w-100 text-dark fw-bold"><i class="fas fa-crown me-2"></i>Admin Panel</a>
    <?php endif; ?>

    <a href="logout.php" class="btn btn-danger w-100 mt-4"><i class="fas fa-sign-out-alt me-2"></i>Logout</a>
</div>

<div class="content">
    <div class="card shadow border-0 mb-3">
        <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center py-3">
            <h5 class="mb-0 fw-bold"><i class="fas fa-ban me-2"></i>Cancelled Bookings</h5>
            <span class="badge bg-white text-danger border border-danger px-3 py-2 rounded-pill"><i class="fas fa-info-circle me-1"></i>Total: <?php echo mysqli_num_rows($result); ?></span>
        </div>
        <div class="card-body p-4">

            <!-- Filter Form -->
            <form class="row g-2 mb-4 p-3 bg-light rounded border shadow-sm" method="get">
                <div class="col-md-3">
                    <label class="form-label small mb-1 fw-bold text-dark">Guest Name</label>
                    <input type="text" name="guest_name" value="<?php echo htmlspecialchars($filter_name, ENT_QUOTES, 'UTF-8'); ?>" class="form-control" placeholder="Search by name">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1 fw-bold">Room No.</label>
                    <input type="text" name="room" value="<?php echo htmlspecialchars($filter_room, ENT_QUOTES, 'UTF-8'); ?>" class="form-control" placeholder="e.g. 2A">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1 fw-bold text-primary">Reference ID</label>
                    <input type="number" name="ref_no" value="<?php echo htmlspecialchars($filter_ref, ENT_QUOTES, 'UTF-8'); ?>" class="form-control border-primary" placeholder="e.g. 45">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1 fw-bold">From Date</label>
                    <input type="date" name="from" value="<?php echo htmlspecialchars($filter_from, ENT_QUOTES, 'UTF-8'); ?>" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1 fw-bold">To Date</label>
                    <input type="date" name="to" value="<?php echo htmlspecialchars($filter_to, ENT_QUOTES, 'UTF-8'); ?>" class="form-control">
                </div>
                <div class="col-md-1 d-flex flex-column justify-content-end">
                    <button type="submit" class="btn btn-primary mb-1 w-100 p-2" title="Search"><i class="fas fa-search"></i></button>
                    <a href="cancel_list.php" class="btn btn-secondary w-100 p-2" title="Reset Filters"><i class="fas fa-sync-alt"></i></a>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light border-bottom border-danger">
                        <tr>
                            <th width="25%">Guest(s) Details</th>
                            <th>Contact Info</th>
                            <th>Room & Ref</th>
                            <th>Stay & Cancel Info</th>
                            <th>Dept</th>
                            <th>Cancellation Reason</th>
                            <?php if(in_array($role, ['admin', 'superadmin'])): ?>
                                <th width="8%" class="text-center">Action</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ($result && mysqli_num_rows($result) > 0):
                            while ($row = mysqli_fetch_assoc($result)):
                                $checkIn  = date('d M Y, h:i A', strtotime($row['check_in_date']));
                                $cancelOn = date('d M Y, h:i A', strtotime($row['cancel_date']));
                                $record_id = (int)$row['id'];
                                $req_ref_id = !empty($row['request_ref_id']) ? (int)$row['request_ref_id'] : 0;
                        ?>
                        <tr>
                            <td>
                                <div class="fw-bold text-primary fs-6"><?php echo htmlspecialchars($row['guest_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php if(!empty($row['designation'])): ?><span class="details-text mt-1"><i class="fas fa-briefcase details-icon"></i> <?php echo htmlspecialchars($row['designation'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                                <?php if(!empty($row['address'])): ?><span class="details-text"><i class="fas fa-map-marker-alt details-icon"></i> <?php echo htmlspecialchars($row['address'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                                <?php if(!empty($row['secondary_guest_name'])): ?>
                                    <div class="mt-2 pt-2 border-top border-light">
                                        <span class="badge bg-info text-dark me-1">2nd</span><span class="fw-semibold text-dark"><?php echo htmlspecialchars($row['secondary_guest_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php if(!empty($row['secondary_guest_designation'])): ?><span class="details-text ms-1"><i class="fas fa-briefcase details-icon"></i> <?php echo htmlspecialchars($row['secondary_guest_designation'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if(!empty($row['phone'])): ?><div class="mb-1"><i class="fas fa-phone-alt text-success me-1"></i> <span class="fw-semibold text-dark"><?php echo htmlspecialchars($row['phone'], ENT_QUOTES, 'UTF-8'); ?></span></div><?php endif; ?>
                                <?php if(!empty($row['secondary_guest_phone'])): ?><div><i class="fas fa-phone-alt text-success me-1"></i> <span class="fw-semibold text-dark"><?php echo htmlspecialchars($row['secondary_guest_phone'], ENT_QUOTES, 'UTF-8'); ?></span></div><?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-primary fs-6 mb-2 d-block text-start w-auto d-inline-block px-3 py-2 rounded-pill shadow-sm"><i class="fas fa-door-closed me-1"></i> Room <?php echo htmlspecialchars($row['room_number'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <br>
                                <?php if($req_ref_id > 0): ?>
                                    <span class="badge bg-warning text-dark border border-warning px-2 py-1"><i class="fas fa-tag me-1"></i>Ref #<?php echo $req_ref_id; ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary opacity-75 px-2 py-1">No Ref</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="bg-light p-2 rounded border shadow-sm">
                                    <span class="details-text text-success fw-bold border-bottom pb-1 mb-1"><i class="fas fa-sign-in-alt details-icon text-success"></i> In: <?php echo $checkIn; ?></span>
                                    <span class="details-text text-danger fw-bold"><i class="fas fa-times-circle details-icon text-danger"></i> Can: <?php echo $cancelOn; ?></span>
                                </div>
                            </td>
                            <td>
                                <?php if(!empty($row['department'])): ?><span class="badge bg-secondary text-wrap text-start px-2 py-1"><i class="fas fa-building me-1"></i> <?php echo htmlspecialchars($row['department'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                            </td>
                            <td>
                                <?php if(!empty($row['cancel_reason'])): ?>
                                    <div class="bg-danger bg-opacity-10 p-2 rounded border-start border-danger border-4">
                                        <span class="details-text text-dark fst-italic">"<?php echo htmlspecialchars($row['cancel_reason'], ENT_QUOTES, 'UTF-8'); ?>"</span>
                                    </div>
                                <?php endif; ?>
                                <?php if(!empty($row['cancelled_by'])): ?>
                                    <span class="details-text mt-2 text-muted fw-bold small"><i class="fas fa-user-shield details-icon"></i> Action by: <span class="text-dark"><?php echo htmlspecialchars($row['cancelled_by'], ENT_QUOTES, 'UTF-8'); ?></span></span>
                                <?php endif; ?>
                            </td>
                            <?php if(in_array($role, ['admin', 'superadmin'])): ?>
                                <td class="text-center align-middle">
                                    <form method="post" action="" onsubmit="return confirm('WARNING: Are you sure you want to permanently delete this cancelled record? This action cannot be undone.');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="delete_id" value="<?php echo $record_id; ?>">
                                        <button type="submit" name="delete_cancelled" class="btn btn-outline-danger btn-sm px-3 py-2 shadow-sm" title="Permanently Delete Record"><i class="fas fa-trash-alt me-1"></i> Delete</button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                        <?php
                            endwhile;
                        else:
                            echo "<tr><td colspan='" . (in_array($role, ['admin', 'superadmin']) ? '7' : '6') . "' class='text-center py-5 text-muted'>
                                    <div class='bg-light p-4 rounded d-inline-block shadow-sm border'>
                                        <i class='fas fa-folder-open fa-3x mb-3 text-secondary opacity-50'></i><br>
                                        <h5 class='fw-bold text-dark'>No Cancelled Bookings Found</h5>
                                        <p class='small mb-0'>Try adjusting your search filters to find records.</p>
                                    </div>
                                  </td></tr>";
                        endif;
                        ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Initialize Toasts
    var toastElList = [].slice.call(document.querySelectorAll('.toast'));
    var toastList = toastElList.map(function (toastEl) {
        return new bootstrap.Toast(toastEl, { autohide: true, delay: 4000 });
    });

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
 