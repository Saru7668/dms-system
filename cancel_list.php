<?php
session_start();

// Debug দরকার হলে 1 করো
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

// শুধু staff, admin, superadmin দেখবে
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
$filter_room = isset($_GET['room']) ? trim($_GET['room']) : '';
$filter_from = isset($_GET['from']) ? trim($_GET['from']) : '';
$filter_to   = isset($_GET['to'])   ? trim($_GET['to'])   : '';

// Validate room format
if (!empty($filter_room) && !preg_match('/^[A-Z0-9-]+$/i', $filter_room)) {
    $filter_room = '';
}

// Base query with prepared statement
$params = [];
$types = "";

$sql = "SELECT * FROM cancelled_bookings WHERE 1=1";

// Room filter
if ($filter_room !== '') {
    $sql .= " AND room_number = ?";
    $params[] = $filter_room;
    $types .= "s";
}

// Date range filter (cancel_date)
if ($filter_from !== '' && $filter_to !== '') {
    $sql .= " AND cancel_date BETWEEN ? AND ?";
    $params[] = $filter_from . " 00:00:00";
    $params[] = $filter_to . " 23:59:59";
    $types .= "ss";
} elseif ($filter_from !== '') {
    $sql .= " AND cancel_date >= ?";
    $params[] = $filter_from . " 00:00:00";
    $types .= "s";
} elseif ($filter_to !== '') {
    $sql .= " AND cancel_date <= ?";
    $params[] = $filter_to . " 23:59:59";
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cancelled Bookings - SCL DMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .sidebar { background: #1a2a3a; color: white; height: 100vh; position: fixed; width: 250px; padding: 20px; overflow-y: auto; }
        .content { margin-left: 250px; padding: 30px; }
        .badge-role { font-size: 0.75rem; padding: 4px 8px; border-radius: 10px; }
        .email-text { font-size: 0.85rem; color: #666; word-break: break-all; }
        .details-text { font-size: 0.8rem; color: #555; display: block; line-height: 1.3; }
        .details-icon { font-size: 0.75rem; width: 15px; text-align: center; color: #888; margin-right: 3px; }
    </style>
</head>
<body>

<!-- Sidebar (index.php style) -->
<div class="sidebar">
    <h4 class="mb-4 text-center"><i class="fas fa-hotel me-2"></i>SCL DMS</h4>
    <div class="p-3 mb-4 bg-white bg-opacity-10 rounded text-center">
        <small>Welcome,</small><br>
        <strong><?php echo htmlspecialchars($user, ENT_QUOTES, 'UTF-8'); ?></strong><br>
        <span class="badge badge-role bg-warning text-dark mt-1"><?php echo strtoupper(htmlspecialchars($role, ENT_QUOTES, 'UTF-8')); ?></span>
    </div>

    <a href="index.php" class="btn btn-outline-light w-100 mb-2">
        <i class="fas fa-home me-2"></i>Dashboard
    </a>

    <a href="guest_request.php" class="btn btn-outline-info w-100 mb-2 text-white">
        <i class="fas fa-paper-plane me-2"></i>Submit Request
    </a>
    <a href="my_requests.php" class="btn btn-outline-light w-100 mb-2">
        <i class="fas fa-list-alt me-2"></i>My Sent Requests
    </a>

    <?php if(in_array($role, ['admin', 'superadmin', 'approver'])): ?>
        <a href="manage_requests.php" class="btn btn-outline-warning w-100 mb-2 text-white">
            <i class="fas fa-tasks me-2"></i>Manage Requests
        </a>
    <?php endif; ?>

    <?php if(in_array($role, ['staff','admin','superadmin'])): ?>
        <a href="checkout_list.php" class="btn btn-outline-light w-100 mb-2">
            <i class="fas fa-clipboard-check me-2"></i>Active Checkouts
        </a>
        <a href="checkout_history.php" class="btn btn-outline-light w-100 mb-2">
            <i class="fas fa-history me-2"></i>Checkout History
        </a>
        <a href="cancel_list.php" class="btn btn-primary w-100 mb-2">
            <i class="fas fa-ban me-2"></i>Cancelled List
        </a>
    <?php endif; ?>

    <?php if(in_array($role, ['admin', 'superadmin'])): ?>
        <hr class="border-light">
        <a href="admin_dashboard.php" class="btn btn-warning w-100 mb-2">
            <i class="fas fa-crown me-2"></i>Admin Panel
        </a>
    <?php endif; ?>

    <a href="logout.php" class="btn btn-danger w-100 mt-4">
        <i class="fas fa-sign-out-alt me-2"></i>Logout
    </a>
</div>

<div class="content">
    <!-- Success/Error Messages -->
    <?php if(isset($_GET['delete_success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>Record deleted successfully.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if(isset($_GET['error']) && $_GET['error'] === 'delete_failed'): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>Failed to delete record. Please try again.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow border-0 mb-3">
        <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
            <h4 class="mb-0"><i class="fas fa-ban me-2"></i>Cancelled Bookings</h4>
        </div>
        <div class="card-body">

            <!-- Filter Form -->
            <form class="row g-2 mb-3" method="get">
                <div class="col-md-3">
                    <label class="form-label small mb-1">Room</label>
                    <input type="text" name="room" value="<?php echo htmlspecialchars($filter_room, ENT_QUOTES, 'UTF-8'); ?>" class="form-control" placeholder="e.g. 2A">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">From (Cancel Date)</label>
                    <input type="date" name="from" value="<?php echo htmlspecialchars($filter_from, ENT_QUOTES, 'UTF-8'); ?>" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">To (Cancel Date)</label>
                    <input type="date" name="to" value="<?php echo htmlspecialchars($filter_to, ENT_QUOTES, 'UTF-8'); ?>" class="form-control">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-outline-primary me-2 w-100">
                        <i class="fas fa-filter me-1"></i>Filter
                    </button>
                    <a href="cancel_list.php" class="btn btn-outline-secondary w-100">
                        Reset
                    </a>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th width="25%">Guest(s)</th>
                            <th>Contact</th>
                            <th>Room</th>
                            <th>Stay & Cancel</th>
                            <th>Dept</th>
                            <th>Cancelled Info</th>
                            <?php if(in_array($role, ['admin', 'superadmin'])): ?>
                                <th width="8%">Action</th>
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
                        ?>
                        <tr>
                            <td>
                                <div class="fw-bold text-primary">
                                    <?php echo htmlspecialchars($row['guest_name'], ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <?php if(!empty($row['designation'])): ?>
                                    <span class="details-text">
                                        <i class="fas fa-briefcase details-icon"></i>
                                        <?php echo htmlspecialchars($row['designation'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if(!empty($row['address'])): ?>
                                    <span class="details-text">
                                        <i class="fas fa-map-marker-alt details-icon"></i>
                                        <?php echo htmlspecialchars($row['address'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                <?php endif; ?>

                                <?php if(!empty($row['secondary_guest_name'])): ?>
                                    <div class="mt-2 pt-2 border-top">
                                        <span class="badge bg-info text-dark me-1">2nd</span>
                                        <span class="fw-semibold">
                                            <?php echo htmlspecialchars($row['secondary_guest_name'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                        <?php if(!empty($row['secondary_guest_designation'])): ?>
                                            <span class="details-text ms-1">
                                                <i class="fas fa-briefcase details-icon"></i>
                                                <?php echo htmlspecialchars($row['secondary_guest_designation'], ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if(!empty($row['secondary_guest_address'])): ?>
                                            <span class="details-text ms-1">
                                                <i class="fas fa-map-marker-alt details-icon"></i>
                                                <?php echo htmlspecialchars($row['secondary_guest_address'], ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if(!empty($row['phone'])): ?>
                                    <i class="fas fa-phone-alt text-success me-1"></i>
                                    <?php echo htmlspecialchars($row['phone'], ENT_QUOTES, 'UTF-8'); ?><br>
                                <?php endif; ?>
                                <?php if(!empty($row['secondary_guest_phone'])): ?>
                                    <i class="fas fa-phone-alt text-success me-1"></i>
                                    <?php echo htmlspecialchars($row['secondary_guest_phone'], ENT_QUOTES, 'UTF-8'); ?><br>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="badge bg-primary fs-6">
                                    <?php echo htmlspecialchars($row['room_number'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>

                            <td>
                                <span class="details-text">
                                    <i class="fas fa-sign-in-alt details-icon"></i>
                                    In: <?php echo $checkIn; ?>
                                </span>
                                <span class="details-text">
                                    <i class="fas fa-ban details-icon"></i>
                                    Cancel: <?php echo $cancelOn; ?>
                                </span>
                            </td>

                            <td>
                                <?php if(!empty($row['department'])): ?>
                                    <span class="badge bg-secondary">
                                        <?php echo htmlspecialchars($row['department'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if(!empty($row['cancel_reason'])): ?>
                                    <span class="details-text">
                                        <i class="fas fa-comment-alt details-icon"></i>
                                        <?php echo htmlspecialchars($row['cancel_reason'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if(!empty($row['cancelled_by'])): ?>
                                    <span class="details-text">
                                        <i class="fas fa-user-shield details-icon"></i>
                                        By: <?php echo htmlspecialchars($row['cancelled_by'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <?php if(in_array($role, ['admin', 'superadmin'])): ?>
                                <td>
                                    <form method="post" action="" onsubmit="return confirm('Are you sure you want to delete this cancelled booking record? This action cannot be undone.');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="delete_id" value="<?php echo $record_id; ?>">
                                        <button type="submit" name="delete_cancelled" class="btn btn-danger btn-sm w-100">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                        <?php
                            endwhile;
                        else:
                            echo "<tr><td colspan='" . (in_array($role, ['admin', 'superadmin']) ? '7' : '6') . "' class='text-center py-4 text-muted'>
                                    <h5>No Cancelled Bookings Found</h5>
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
</body>
</html>
