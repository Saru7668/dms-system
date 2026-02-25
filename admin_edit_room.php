<?php
session_start();
require_once('db.php');
require_once('header.php');

// ? AUTHENTICATION CHECK
if (!isset($_SESSION['UserName']) || $_SESSION['UserRole'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// ? USER INFO FOR SIDEBAR
$userName = $_SESSION['UserName'];
$userRole = $_SESSION['UserRole'];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$query = mysqli_query($conn, "SELECT * FROM rooms WHERE id=$id");
$room = mysqli_fetch_assoc($query);

// Room ?? ???? ????? ??????????? admin_rooms.php ?? ???? ??????
if (!$room) {
    $_SESSION['msg'] = "Room not found!";
    $_SESSION['msg_type'] = "danger";
    header("Location: admin_rooms.php");
    exit;
}

// ? UPDATE LOGIC
if (isset($_POST['update_room'])) {
    $room_no = mysqli_real_escape_string($conn, $_POST['room_no']);
    $floor   = mysqli_real_escape_string($conn, $_POST['floor']);
    $type    = mysqli_real_escape_string($conn, $_POST['type']);
    $is_fixed = mysqli_real_escape_string($conn, $_POST['is_fixed']);
    $current_guest = isset($_POST['current_guest']) ? mysqli_real_escape_string($conn, $_POST['current_guest']) : '';

    $status = ($is_fixed === 'Yes') ? 'Booked' : 'Available';

    $sql = "UPDATE rooms 
            SET room_no='$room_no', 
                floor='$floor', 
                room_type='$type', 
                current_guest='$current_guest',
                is_fixed='$is_fixed',
                status='$status'
            WHERE id=$id";

    if (mysqli_query($conn, $sql)) {
        // ? Save ???? admin_rooms.php ?? redirect ????
        $_SESSION['msg'] = "Room updated successfully!";
        $_SESSION['msg_type'] = "success";
        header("Location: admin_rooms.php");
        exit;
    } else {
        // ? Error ??? ?? ????? ??? ????? ??????
        $_SESSION['msg'] = "Error updating room: " . mysqli_error($conn);
        $_SESSION['msg_type'] = "danger";
        header("Location: admin_edit_room.php?id=$id");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Room - SCL DMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        
        /* ? SIDEBAR STYLES FROM ADMIN ROOMS */
        .sidebar { background: #1a2a3a; color: white; min-height: 100vh; padding: 20px; }
        .badge-role { font-size: 0.75rem; padding: 4px 8px; border-radius: 10px; }
        
        /* ? PAGE SPECIFIC STYLES */
        .edit-card { border: none; border-radius: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .main-content { padding: 30px; }

        /* ? ???-?? ?????????? */
        .fade-in {
            animation: fadeIn 0.6s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

<!-- ? ??????? ????? ????????? ????????? -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1055;">
    <div id="liveToast" class="toast align-items-center border-0 shadow" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body fw-bold" id="toastMessage"></div>
            <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close" id="toastCloseBtn"></button>
        </div>
    </div>
</div>

<div class="container-fluid fade-in">
    <div class="row">

        <!-- ? SIDEBAR (COPIED EXACTLY FROM ADMIN_ROOMS.PHP) -->
        <div class="col-md-2 sidebar">
            <h4>SCL DMS</h4>
            <hr>
            <!-- Logged in user info -->
            <div class="p-3 mb-4 bg-white bg-opacity-10 rounded text-center">
                <small class="text-light d-block">Logged in as:</small>
                <strong><?php echo htmlspecialchars($userName); ?></strong><br>
                <span class="badge badge-role bg-warning text-dark">
                    <?php echo strtoupper($userRole); ?>
                </span>
            </div>

            <a href="index.php" class="btn btn-outline-light w-100 mb-2">Dashboard</a>
            <a href="admin_rooms.php" class="btn btn-primary w-100 mb-2">Manage Rooms</a>
            <a href="admin_users.php" class="btn btn-outline-light w-100 mb-2">Manage Users</a>
            <a href="checkout_list.php" class="btn btn-outline-light w-100 mb-2">Active Checkouts</a>
            <a href="logout.php" class="btn btn-danger w-100 mt-4">Logout</a>
        </div>

        <!-- ? MAIN CONTENT AREA -->
        <div class="col-md-10 main-content">
            
            <?php if(isset($_SESSION['msg'])): ?>
                <?php 
                    $toast_msg = $_SESSION['msg'];
                    $toast_type = isset($_SESSION['msg_type']) ? $_SESSION['msg_type'] : 'info';
                    unset($_SESSION['msg']);
                    unset($_SESSION['msg_type']);
                ?>
                <script>
                    document.addEventListener("DOMContentLoaded", function() {
                        var toastEl = document.getElementById('liveToast');
                        var toastBody = document.getElementById('toastMessage');
                        var closeBtn = document.getElementById('toastCloseBtn');
                        
                        toastEl.classList.remove('bg-success', 'bg-danger', 'bg-warning', 'bg-info', 'text-white', 'text-dark');
                        closeBtn.classList.remove('btn-close-white');
                        
                        if ('<?php echo $toast_type; ?>' === 'warning' || '<?php echo $toast_type; ?>' === 'info') {
                            toastEl.classList.add('bg-<?php echo $toast_type; ?>', 'text-dark');
                        } else {
                            toastEl.classList.add('bg-<?php echo $toast_type; ?>', 'text-white');
                            closeBtn.classList.add('btn-close-white');
                        }
                        
                        toastBody.innerHTML = "<?php echo addslashes($toast_msg); ?>";
                        var toast = new bootstrap.Toast(toastEl, { delay: 4000 });
                        toast.show();
                    });
                </script>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3 class="text-dark fw-bold"><i class="fas fa-edit me-2"></i>Edit Room Configuration</h3>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="admin_rooms.php">Manage Rooms</a></li>
                        <li class="breadcrumb-item active">Edit Room</li>
                    </ol>
                </nav>
            </div>

            <div class="card edit-card">
                <div class="card-body p-5">
                    <form method="post">
                        <div class="row g-4">
                            <!-- Room Number -->
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Room Number</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-door-open text-primary"></i></span>
                                    <input type="text" name="room_no" class="form-control" 
                                           value="<?php echo htmlspecialchars($room['room_no']); ?>" required>
                                </div>
                            </div>

                            <!-- Floor -->
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Floor / Level</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-layer-group text-primary"></i></span>
                                    <input type="text" name="floor" class="form-control" 
                                           value="<?php echo htmlspecialchars($room['floor']); ?>" required>
                                </div>
                            </div>

                            <!-- Room Type -->
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Room Type</label>
                                <select name="type" class="form-select border-2">
                                    <option value="Single" <?php if(($room['room_type']??'')=='Single') echo 'selected'; ?>>Single Bed</option>
                                    <option value="Double" <?php if(($room['room_type']??'')=='Double') echo 'selected'; ?>>Double Bed</option>
                                    <option value="VIP" <?php if(($room['room_type']??'')=='VIP') echo 'selected'; ?>>VIP Suite</option>
                                    <option value="Dormitory" <?php if(($room['room_type']??'')=='Dormitory') echo 'selected'; ?>>Dormitory</option>
                                </select>
                            </div>

                            <!-- Fixed Status -->
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Booking Type</label>
                                <select name="is_fixed" class="form-select border-2" id="fixedStatus">
                                    <option value="No" <?php if(($room['is_fixed']??'')=='No') echo 'selected'; ?>>General (Available for Booking)</option>
                                    <option value="Yes" <?php if(($room['is_fixed']??'')=='Yes') echo 'selected'; ?>>Fixed (Permanent Guest)</option>
                                </select>
                            </div>

                            <!-- Guest Field (Dynamic) -->
                            <div class="col-12" id="guestField" style="<?php echo ($room['is_fixed'] == 'Yes') ? '' : 'display:none;'; ?>">
                                <div class="p-3 bg-primary bg-opacity-10 rounded-3 border border-primary border-opacity-25">
                                    <label class="form-label fw-bold text-primary">Current Guest Name</label>
                                    <input type="text" name="current_guest" class="form-control form-control-lg border-primary" 
                                           value="<?php echo htmlspecialchars($room['current_guest'] ?? ''); ?>" 
                                           placeholder="Enter full name of the permanent guest">
                                </div>
                            </div>
                        </div>

                        <!-- Buttons -->
                        <div class="mt-5 pt-3 border-top d-flex gap-2">
                            <button type="submit" name="update_room" class="btn btn-primary px-5 py-2">
                                <i class="fas fa-save me-2"></i>Update Room Info
                            </button>
                            <a href="admin_rooms.php" class="btn btn-light px-4 py-2 text-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const fixedSelect = document.getElementById('fixedStatus');
    const guestField = document.getElementById('guestField');

    fixedSelect.addEventListener('change', function() {
        if (this.value === 'Yes') {
            guestField.style.display = 'block';
        } else {
            guestField.style.display = 'none';
        }
    });
</script>
</body>
</html>
