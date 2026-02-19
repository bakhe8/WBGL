<?php
// Redirect to the main controller
$id = $_GET['id'] ?? '';
$url = "../index.php";
if ($id) {
    $url .= "?id=" . urlencode($id);
}
header("Location: " . $url);
exit;
