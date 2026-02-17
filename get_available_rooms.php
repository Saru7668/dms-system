<?php
require_once('db.php');
require_once('header.php');

$rooms = mysqli_query($conn, "SELECT * FROM rooms WHERE status = 'Available' AND is_fixed = 'No' ORDER BY floor, room_no");
?>
<option value="">Select Room</option>
<?php while($room = mysqli_fetch_assoc($rooms)): ?>
<option value="<?php echo $room['room_no']; ?>" 
        data-type="<?php echo $room['room_type']; ?>" 
        data-floor="<?php echo $room['floor']; ?>">
    <?php echo $room['room_no']; ?> (<?php echo $room['floor']; ?>) - <?php echo $room['room_type']; ?>
</option>
<?php endwhile; ?>
