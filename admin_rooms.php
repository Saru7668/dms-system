<?php
session_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once('db.php');
require_once('header.php');

if (!isset($_SESSION['UserName']) || $_SESSION['UserRole'] != 'admin') {
    header("Location: index.php");
    exit;
}

$userName = $_SESSION['UserName'];
$userRole = $_SESSION['UserRole'];

// URL diye asha success message
if (isset($_GET['msg']) && $_GET['msg'] === 'added') {
    $_SESSION['msg'] = "Room Added Successfully!";
    $_SESSION['msg_type'] = "success";
    header("Location: admin_rooms.php");
    exit;
}

// Add room
if (isset($_POST['add_room'])) {
    $room_no = mysqli_real_escape_string($conn, $_POST['room_no']);
    $floor   = mysqli_real_escape_string($conn, $_POST['floor']);
    $type    = mysqli_real_escape_string($conn, $_POST['type']);
    $is_fixed = mysqli_real_escape_string($conn, $_POST['is_fixed']);

    // VIP hole force fixed
    if ($type === 'VIP') {
        $is_fixed = 'Yes';
    }

    $status  = ($is_fixed == 'Yes') ? 'Booked' : 'Available';

    $check = mysqli_query($conn, "SELECT id FROM rooms WHERE room_no = '$room_no'");
    if (mysqli_num_rows($check) > 0) {
        $_SESSION['msg'] = "Room $room_no already exists!";
        $_SESSION['msg_type'] = "danger";
    } else {
        $sql = "INSERT INTO rooms (room_no, floor, room_type, status, is_fixed, current_guest)
                VALUES ('$room_no', '$floor', '$type', '$status', '$is_fixed', NULL)";

        if (mysqli_query($conn, $sql)) {
            // success -> redirect, jate refresh dile abar insert na hoy
            header("Location: admin_rooms.php?msg=added");
            exit;
        } else {
            $_SESSION['msg'] = "DB Error: " . mysqli_error($conn);
            $_SESSION['msg_type'] = "danger";
        }
    }
    header("Location: admin_rooms.php");
    exit;
}

// Delete room
if (isset($_GET['del'])) {
    $id = (int)$_GET['del'];
    if(mysqli_query($conn, "DELETE FROM rooms WHERE id=$id")){
        $_SESSION['msg'] = "Room Deleted Successfully!";
        $_SESSION['msg_type'] = "warning";
    } else {
        $_SESSION['msg'] = "Delete failed: " . mysqli_error($conn);
        $_SESSION['msg_type'] = "danger";
    }
    header("Location: admin_rooms.php");
    exit;
}

// Update current guest for fixed room
if (isset($_POST['update_guest'])) {
    $room_id = (int)$_POST['room_id'];
    $guest_name = mysqli_real_escape_string($conn, $_POST['current_guest']);
    if(mysqli_query($conn, "UPDATE rooms SET current_guest = '$guest_name' WHERE id = $room_id")){
        $_SESSION['msg'] = "Guest name updated!";
        $_SESSION['msg_type'] = "info";
    } else {
        $_SESSION['msg'] = "Failed to update guest!";
        $_SESSION['msg_type'] = "danger";
    }
    header("Location: admin_rooms.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Manage Rooms - SCL MRBS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
body { background: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
.sidebar { background: #1a2a3a; color: white; min-height: 100vh; padding: 20px; }
.badge-role { font-size: 0.75rem; padding: 4px 8px; border-radius: 10px; }

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

    <div class="col-md-10 py-4">
        
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

        <div class="row">
            <div class="col-md-4">
                <div class="card p-4 shadow-sm">
                    <h4 class="text-primary"><i class="fas fa-plus-circle"></i> Add New Room</h4>
                    
                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Room Number</label>
                            <input type="text" name="room_no" class="form-control" placeholder="e.g. 101" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Floor</label>
                            <input type="text" name="floor" class="form-control" placeholder="e.g. 2nd Floor" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Type</label>
                            <select name="type" class="form-select">
                                <option value="Single">Single</option>
                                <option value="Double">Double</option>
                                <option value="VIP">VIP</option>
                                <option value="Dormitory">Dormitory</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Fixed</label>
                            <select name="is_fixed" class="form-select">
                                <option value="No">No (Available for booking)</option>
                                <option value="Yes">Yes (Fixed - Manual guest)</option>
                            </select>
                        </div>
                        <button type="submit" name="add_room" class="btn btn-primary w-100">
                            <i class="fas fa-plus"></i> Add Room
                        </button>
                        <a href="index.php" class="btn btn-secondary w-100 mt-2">Back to Dashboard</a>
                    </form>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card p-4 shadow-sm">
                    <h4><i class="fas fa-list"></i> Room List (<?php echo mysqli_num_rows(mysqli_query($conn, "SELECT * FROM rooms")); ?> total)</h4>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>Room No</th>
                                    <th>Floor</th>
                                    <th>Type</th>
                                    <th>Current Guest</th>
                                    <th>Fixed</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $res = mysqli_query($conn, "SELECT * FROM rooms ORDER BY room_no ASC");
                                while ($row = mysqli_fetch_assoc($res)) {
                                    $fixed_badge = ($row['is_fixed'] == 'Yes')
                                        ? '<span class="badge bg-warning text-dark"><i class="fas fa-lock"></i> Yes</span>'
                                        : '<span class="badge bg-success">No</span>';

                                    $status_badge = ($row['is_fixed'] == 'Yes')
                                        ? '<span class="badge bg-danger"><i class="fas fa-user-check"></i> Occupied</span>'
                                        : (($row['status'] == 'Available')
                                            ? '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Available</span>'
                                            : '<span class="badge bg-orange"><i class="fas fa-clock"></i> Booked</span>');

                                    $guest_display = !empty($row['current_guest'])
                                        ? '<span class="badge bg-info text-dark">' . htmlspecialchars($row['current_guest']) . '</span>'
                                        : '<span class="text-muted small">No guest</span>';
                                    
                                    $type_badge_class = 'bg-secondary';
                                        if ($row['room_type'] === 'VIP') {
                                            $type_badge_class = 'bg-warning text-dark';
                                        }    

                                    echo "<tr>
                                            <td><strong>{$row['room_no']}</strong></td>
                                            <td>{$row['floor']}</td>
                                            <td><span class='badge {$type_badge_class}'>"
                                                . htmlspecialchars($row['room_type']) .
                                            "</span>
                                            </td>
                                            <td>$guest_display</td>
                                            <td>$fixed_badge</td>
                                            <td>$status_badge</td>
                                            <td>
                                                <div class='btn-group' role='group'>
                                                    <a href='admin_edit_room.php?id={$row['id']}' class='btn btn-primary btn-sm' title='Edit'>
                                                        <i class='fas fa-edit'></i>
                                                    </a>
                                                    <a href='admin_rooms.php?del={$row['id']}' class='btn btn-danger btn-sm' onclick='return confirm(\"Delete {$row['room_no']}?\")' title='Delete'>
                                                        <i class='fas fa-trash'></i>
                                                    </a>
                                                </div>
                                            </td>
                                          </tr>";

                                    if ($row['is_fixed'] == 'Yes') {
                                        echo "<tr class='table-secondary'>
                                                <td colspan='7'>
                                                    <form method='POST' class='row g-2'>
                                                        <input type='hidden' name='room_id' value='{$row['id']}'>
                                                        <div class='col-md-6'>
                                                            <input type='text' name='current_guest' class='form-control form-control-sm'
                                                                placeholder='Enter guest name for {$row['room_no']}'
                                                                value='".htmlspecialchars($row['current_guest'])."'>
                                                        </div>
                                                        <div class='col-md-3'>
                                                            <button type='submit' name='update_guest' class='btn btn-success btn-sm'>
                                                                <i class='fas fa-save'></i> Update Guest
                                                            </button>
                                                        </div>
                                                        <div class='col-md-3'>
                                                            <button type='button' class='btn btn-outline-secondary btn-sm' onclick='this.parentElement.parentElement.parentElement.remove()'>
                                                                <i class='fas fa-times'></i> Hide
                                                            </button>
                                                        </div>
                                                    </form>
                                                </td>
                                              </tr>";
                                    }
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>

</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
