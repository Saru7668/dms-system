<?php
session_start();
// Sob session variable muche fela hobe
$_SESSION = array();

// Session destroy kora holo
session_destroy();

// Login page-e redirect kora holo
header("Location: login.php");
exit;
?>
