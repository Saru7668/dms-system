<?php
require_once('db.php');
$id = (int)$_GET['id'];
$res = mysqli_query($conn, "SELECT * FROM visit_guests WHERE request_id = $id");
echo "<table class='table table-bordered'><thead><tr><th>Name</th><th>Phone</th><th>Designation</th></tr></thead><tbody>";
while($g = mysqli_fetch_assoc($res)) {
    echo "<tr><td>{$g['guest_title']} {$g['guest_name']}</td><td>{$g['phone']}</td><td>{$g['designation']}</td></tr>";
}
echo "</tbody></table>";
?>
