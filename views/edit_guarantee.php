<?php
// Redirect to the main controller
$id = $_GET['id'] ?? '';
$url = "../index.php";
if ($id) {
    $url .= '?' . http_build_query(['id' => $id]);
}
header("Location: " . $url);
exit;
