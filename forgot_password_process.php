<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/handlers/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status'=>'error','message'=>'Invalid request.']);
    exit;
}

$email = trim($_POST['email'] ?? '');

if ($email === '') {
    echo json_encode(['status'=>'error','message'=>'Email is required.']);
    exit;
}

$stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    echo json_encode(['status'=>'error','message'=>'Email not found.']);
    exit;
}

$token  = bin2hex(random_bytes(32));
$expiry = date("Y-m-d H:i:s", strtotime("+15 minutes"));

$upd = $conn->prepare("UPDATE users SET reset_token=?, token_expiry=? WHERE email=?");
$upd->bind_param("sss", $token, $expiry, $email);
$upd->execute();

$link = "http://localhost/cocovalley/admin/reset_password.php?token=$token";

echo json_encode([
    'status' => 'success',
    'message' => 'Password reset link has been sent.',
    'reset_link' => $link  // for dev view
]);
exit;
?>
