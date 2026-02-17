<?php
session_start();
$captcha_code = rand(1000, 9999);
$_SESSION['captcha'] = $captcha_code;

// ইমেজ তৈরি (১০০x৪০ সাইজ)
$target_layer = imagecreatetruecolor(70, 30);
$captcha_background = imagecolorallocate($target_layer, 255, 160, 119); // ব্যাকগ্রাউন্ড কালার
imagefill($target_layer, 0, 0, $captcha_background);
$captcha_text_color = imagecolorallocate($target_layer, 0, 0, 0); // টেক্সট কালার
imagestring($target_layer, 5, 15, 7, $captcha_code, $captcha_text_color);

header("Content-type: image/jpeg");
imagejpeg($target_layer);
?>
