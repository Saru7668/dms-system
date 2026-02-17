<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once('db.php');
require_once('header.php');

// Only admin
if (!isset($_SESSION['UserName']) || $_SESSION['UserRole'] !== 'admin') {
    header("Location: index.php");
    exit;
}

$userName = $_SESSION['UserName'];
$userRole = $_SESSION['UserRole'];

// --- STATS QUERIES ---
$total_rooms      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM rooms"))['count'];
$available_rooms  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM rooms WHERE status='Available'"))['count'];
$booked_rooms     = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM rooms WHERE status='Booked'"))['count'];
$active_bookings  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM bookings WHERE status='Booked'"))['count'];
$total_users      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM users WHERE user_role != 'pending'"))['count'];
$checkout_history = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM checked_out_guests"))['count'];

// ? NEW: Fixed & VIP Room Stats
$fixed_rooms      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM rooms WHERE is_fixed='Yes'"))['count'];
$vip_rooms        = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM rooms WHERE room_type LIKE '%VIP%'"))['count'];

// ? NEW: Visit Request Stats
$pending_requests = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM visit_requests WHERE status='Pending'"))['count'];
$total_visit_reqs = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM visit_requests"))['count'];

// ? NEW: Who made how many requests (Group by User)
$requester_sql = "SELECT requested_by, 
                  COUNT(*) as total_req, 
                  SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) as pending_req 
                  FROM visit_requests 
                  GROUP BY requested_by 
                  ORDER BY total_req DESC";
$requester_result = mysqli_query($conn, $requester_sql);

// ? NEW: Live Room Status Query (index.php er moto)
$rooms_sql = "SELECT r.*, b.guest_name 
              FROM rooms r 
              LEFT JOIN bookings b 
                     ON r.room_no = b.room_no 
                    AND b.status = 'Booked'
              ORDER BY r.floor, r.room_no";
$rooms_result = mysqli_query($conn, $rooms_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Super Admin Panel - SCL DMS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
.admin-sidebar { 
    background: linear-gradient(180deg, #1a2a3a 0%, #2f6b96 100%);
    color: white; 
    min-height: 100vh; 
    padding: 20px; 
    position: fixed; 
    width: 250px;
    box-shadow: 2px 0 10px rgba(0,0,0,0.1);
    z-index: 100;
}
.content { margin-left: 260px; padding: 30px; }
.card-stat { 
    padding: 25px; 
    border-radius: 15px; 
    color: white; 
    text-align: center; 
    transition: transform 0.3s;
    position: relative;
    overflow: hidden;
    height: 100%; /* Equal height */
}
.card-stat:hover { transform: translateY(-5px); }
.card-stat::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
}
.bg-primary.card-stat::before { background: #0d6efd; }
.bg-success.card-stat::before { background: #198754; }
.bg-warning.card-stat::before { background: #ffc107; }
.bg-info.card-stat::before { background: #0dcaf0; }
.bg-danger.card-stat::before { background: #dc3545; }
.bg-purple.card-stat { background-color: #6f42c1; }
.bg-purple.card-stat::before { background: #4c2d87; }
.bg-teal.card-stat { background-color: #20c997; } /* Fixed Room Color */
.bg-teal.card-stat::before { background: #198754; }
.bg-gold.card-stat { background: linear-gradient(135deg, #b8860b, #ffd700); color: #2c2100; } /* VIP Room Color */
.bg-gold.card-stat::before { background: #fff; }

/* New Color for Active Bookings */
.bg-indigo.card-stat { background-color: #6610f2; }
.bg-indigo.card-stat::before { background: #520dc2; }

.badge-role { font-size: 0.75rem; padding: 4px 8px; border-radius: 10px; }
.stats-grid { gap: 20px; }
.quick-actions { background: white; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); }

/* ? Live Room Status cards (index.php style) */
.room-card { 
    height: 220px; 
    border-radius: 12px; 
    color: white !important; 
    text-align: center; 
    padding: 15px; 
    margin-bottom: 20px; 
    transition: transform 0.3s ease, box-shadow 0.3s ease; 
    position: relative; 
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    display: flex; 
    flex-direction: column; 
    justify-content: center; 
    align-items: center;
}
.room-card:hover { 
    transform: translateY(-4px); 
    box-shadow: 0 10px 20px rgba(0,0,0,0.2); 
    z-index: 10; 
}
.available-room { background: linear-gradient(135deg, #28a745, #20c997); }
.booked-room    { background: linear-gradient(135deg, #dc3545, #fd7e14); }
.room-card h4 { 
    font-weight: 800; 
    font-size: 1.4rem; 
    text-shadow: 1px 1px 2px rgba(0,0,0,0.3); 
    margin-bottom: 2px; 
}
.status-text { 
    text-transform: uppercase; 
    font-size: 0.75rem; 
    letter-spacing: 1px; 
    margin-top: 5px; 
    font-weight: bold; 
    opacity: 0.9; 
}
.guest-name-badge {
    background: rgba(0,0,0,0.25); 
    padding: 5px 12px; 
    border-radius: 20px; 
    font-size: 0.9rem; 
    font-weight: 700;
    margin-top: 10px; 
    white-space: nowrap; 
    overflow: hidden; 
    text-overflow: ellipsis; 
    max-width: 95%; 
    display: inline-block; 
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    
}

/* VIP room card – golden, ????? look */
.vip-room {
    background: linear-gradient(135deg, #b8860b, #ffd700, #fff9c4);
    box-shadow: 0 0 20px rgba(255, 215, 0, 0.8);
    border: 1px solid rgba(255, 255, 255, 0.7);
    color: #2c2100 !important;
}
</style>
</head>
<body>

<div class="admin-sidebar">
    <h4><i class="fas fa-hotel me-2"></i>SCL DMS Admin</h4>
    <hr>
    
    <!-- Admin info -->
    <div class="p-3 mb-4 bg-white bg-opacity-10 rounded text-center">
        <small class="text-light d-block">Super Admin</small>
        <strong><?php echo htmlspecialchars($userName); ?></strong><br>
        <span class="badge badge-role bg-warning text-dark"><?php echo strtoupper($userRole); ?></span>
    </div>

    <!-- Admin Menu -->
    <a href="admin_dashboard.php" class="btn btn-primary w-100 mb-2 text-start active">
        <i class="fas fa-tachometer-alt me-2"></i> Dashboard
    </a>
    
    <a href="manage_requests.php" class="btn btn-outline-warning w-100 mb-2 text-start text-white">
        <i class="fas fa-tasks me-2"></i> Manage Requests 
        <?php if($pending_requests > 0): ?>
            <span class="badge bg-danger ms-2"><?php echo $pending_requests; ?></span>
        <?php endif; ?>
    </a>

    <a href="admin_rooms.php" class="btn btn-outline-light w-100 mb-2 text-start">
        <i class="fas fa-door-open me-2"></i> Manage Rooms
    </a>
    <a href="admin_users.php" class="btn btn-outline-light w-100 mb-2 text-start">
        <i class="fas fa-users me-2"></i> Manage Users
    </a>
    <a href="checkout_list.php" class="btn btn-outline-light w-100 mb-2 text-start">
        <i class="fas fa-clipboard-list me-2"></i> Active Bookings
    </a>
    <a href="checkout_history.php" class="btn btn-outline-light w-100 mb-2 text-start">
        <i class="fas fa-history me-2"></i> Checkout History
    </a>
    <hr class="my-3">
    <a href="index.php" class="btn btn-outline-light w-100 mb-2 text-start">
        <i class="fas fa-user-circle me-2"></i> User Dashboard
    </a>
    <a href="logout.php" class="btn btn-danger w-100 mt-3">
        <i class="fas fa-sign-out-alt me-2"></i> Logout
    </a>
</div>

<div class="content">
    <div class="row g-3 mb-4">
        <!-- 1. Pending Request Card -->
        <div class="col-lg-4 col-md-6">
            <div class="card-stat bg-purple">
                <i class="fas fa-envelope-open-text fa-2x mb-3"></i>
                <h5>Pending Requests</h5>
                <h2><?php echo $pending_requests; ?></h2>
                <small>Total Requests: <?php echo $total_visit_reqs; ?></small>
            </div>
        </div>

        <!-- 2. Total Rooms -->
        <div class="col-lg-4 col-md-6">
            <div class="card-stat bg-primary">
                <i class="fas fa-door-open fa-2x mb-3"></i>
                <h5>Total Rooms</h5>
                <h2><?php echo $total_rooms; ?></h2>
            </div>
        </div>

        <!-- 3. Available Rooms -->
        <div class="col-lg-4 col-md-6">
            <div class="card-stat bg-success">
                <i class="fas fa-check-circle fa-2x mb-3"></i>
                <h5>Available</h5>
                <h2><?php echo $available_rooms; ?></h2>
            </div>
        </div>

        <!-- 4. Active Bookings (UPDATED COLOR) -->
        <div class="col-lg-4 col-md-6">
            <div class="card-stat bg-indigo"> <!-- Changed from bg-warning -->
                <i class="fas fa-user-check fa-2x mb-3"></i>
                <h5>Active Bookings</h5>
                <h2><?php echo $active_bookings; ?></h2>
            </div>
        </div>

        <!-- 5. VIP Rooms -->
        <div class="col-lg-4 col-md-6">
            <div class="card-stat bg-gold">
                <i class="fas fa-crown fa-2x mb-3"></i>
                <h5>VIP Rooms</h5>
                <h2><?php echo $vip_rooms; ?></h2>
            </div>
        </div>

        <!-- 6. Fixed Rooms -->
        <div class="col-lg-4 col-md-6">
            <div class="card-stat bg-teal">
                <i class="fas fa-thumbtack fa-2x mb-3"></i>
                <h5>Fixed Rooms</h5>
                <h2><?php echo $fixed_rooms; ?></h2>
            </div>
        </div>
    </div>

    <!-- User Request Statistics + Users/History -->
    <div class="row mt-4">
        <div class="col-lg-7">
            <div class="card quick-actions p-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0"><i class="fas fa-chart-bar text-primary me-2"></i>User Request Statistics</h5>
                    <a href="manage_requests.php" class="btn btn-sm btn-primary">View All</a>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>User Name</th>
                                <th class="text-center">Total Requests</th>
                                <th class="text-center">Pending</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if(mysqli_num_rows($requester_result) > 0) {
                                while($row = mysqli_fetch_assoc($requester_result)): 
                            ?>
                            <tr>
                                <td class="fw-bold"><?php echo htmlspecialchars($row['requested_by']); ?></td>
                                <td class="text-center"><span class="badge bg-secondary"><?php echo $row['total_req']; ?></span></td>
                                <td class="text-center">
                                    <?php if($row['pending_req'] > 0): ?>
                                        <span class="badge bg-danger"><?php echo $row['pending_req']; ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a href="manage_requests.php" class="btn btn-sm btn-outline-info"><i class="fas fa-eye"></i></a>
                                </td>
                            </tr>
                            <?php 
                                endwhile; 
                            } else {
                                echo "<tr><td colspan='4' class='text-center text-muted'>No requests found.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="row g-3">
                <div class="col-12">
                    <div class="card quick-actions p-4">
                        <h5><i class="fas fa-users text-primary me-2"></i>Total Users</h5>
                        <h3 class="text-success"><?php echo $total_users; ?></h3>
                        <a href="admin_users.php" class="btn btn-outline-primary mt-2 w-100">Manage Users</a>
                    </div>
                </div>
                <div class="col-12">
                    <div class="card quick-actions p-4">
                        <h5><i class="fas fa-history text-info me-2"></i>Checkout History</h5>
                        <h3 class="text-info"><?php echo $checkout_history; ?></h3>
                        <a href="checkout_history.php" class="btn btn-outline-info mt-2 w-100">View History</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Admin Actions -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card quick-actions p-4">
                <h4 class="mb-3">Quick Admin Actions</h4>
                <div class="row">
                    <div class="col-md-3 mb-2">
                        <a href="admin_rooms.php" class="btn btn-outline-primary w-100 h-100 p-3">
                            <i class="fas fa-door-open fa-2x d-block mb-2"></i>
                            Rooms
                        </a>
                    </div>
                    <div class="col-md-3 mb-2">
                        <a href="admin_users.php" class="btn btn-outline-secondary w-100 h-100 p-3">
                            <i class="fas fa-users fa-2x d-block mb-2"></i>
                            Users
                        </a>
                    </div>
                    <div class="col-md-3 mb-2">
                        <a href="checkout_list.php" class="btn btn-outline-warning w-100 h-100 p-3">
                            <i class="fas fa-clipboard-list fa-2x d-block mb-2"></i>
                            Checkouts
                        </a>
                    </div>
                    <div class="col-md-3 mb-2">
                        <a href="checkout_history.php" class="btn btn-outline-info w-100 h-100 p-3">
                            <i class="fas fa-history fa-2x d-block mb-2"></i>
                            History
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ? NEW: Live Room Status (same cards as index.php) -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card quick-actions p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="mb-0"><i class="fas fa-th text-info me-2"></i>Live Room Status</h4>
                </div>
                                <div class="row g-3">
                    <?php if ($rooms_result && mysqli_num_rows($rooms_result) > 0): ?>
                        <?php while($room = mysqli_fetch_assoc($rooms_result)): ?>
                            <?php
                                $is_booked = ($room['status'] == 'Booked' || $room['is_fixed'] == 'Yes');

                                // VIP ???? ???
                                $is_vip = (stripos($room['room_type'], 'VIP') !== false);

                                if ($is_vip) {
                                    // VIP ????? ???? ????? golden card
                                    $card_class  = 'vip-room';
                                    // Status text-? ????? ?????
                                    $status_text = ($room['is_fixed'] == 'Yes') ? 'VIP FIXED' : 'VIP';
                                    
                                    // UPDATED: VIP ???? (Crown)
                                    $center_icon = '<i class="fas fa-crown"></i>'; 
                                } else {
                                    // Normal logic (available/booked)
                                    $card_class  = $is_booked ? 'booked-room' : 'available-room';
                                    $status_text = $is_booked
                                        ? ($room['is_fixed'] == 'Yes' ? 'FIXED' : 'BOOKED')
                                        : 'AVAILABLE';
                                    
                                    // Normal ???? (??????/????)
                                    $is_double = (stripos($room['room_type'], 'Double') !== false);
                                    if($is_booked) {
                                        // ??? ??? ????? ??????
                                        $center_icon = $is_double 
                                            ? '<i class="fas fa-bed"></i><i class="fas fa-bed"></i>' 
                                            : '<i class="fas fa-bed"></i>';
                                    } else {
                                        // ???? ????? ????
                                        $center_icon = $is_double 
                                            ? '<i class="fas fa-door-open"></i><small>(2)</small>' 
                                            : '<i class="fas fa-door-open"></i>';
                                    }
                                }

                                $guest_display = "";
                                if (!empty($room['guest_name'])) {
                                    $guest_display = $room['guest_name']; 
                                } elseif ($room['is_fixed'] == 'Yes') {
                                    $guest_display = !empty($room['current_guest']) ? $room['current_guest'] : "Fixed Guest";
                                }
                            ?>
                            <div class="col-xl-3 col-lg-4 col-md-4 col-sm-6 col-6">
                                <div class="room-card <?php echo $card_class; ?>">
                                    <h4><?php echo $room['room_no']; ?></h4>
                                    <small><?php echo htmlspecialchars($room['room_type']); ?></small>
                                    
                                    <!-- UPDATED: Dynamic Icon (Crown for VIP, Bed/Door for others) -->
                                    <div class="room-icons fa-2x my-2">
                                        <?php echo $center_icon; ?>
                                    </div>
                                    
                                    <div class="status-text"><?php echo $status_text; ?></div>

                                    <?php if($is_booked && $guest_display): ?>
                                        <div class="guest-name-badge">
                                            <i class="fas fa-user-circle me-1"></i>
                                            <?php echo htmlspecialchars($guest_display); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="col-12 text-center text-muted">
                            No room data found.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
