<?php
include 'handlers/db_connect.php';

if (isset($_GET['token'])) {
  $token = $_GET['token'];
  $query = $conn->prepare("SELECT * FROM users WHERE reset_token=? AND token_expiry > NOW()");
  $query->bind_param("s", $token);
  $query->execute();
  $result = $query->get_result();

  if ($result->num_rows === 0) {
    die("Invalid or expired link.");
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_pass = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
    $update = $conn->prepare("UPDATE users SET password_hash=?, reset_token=NULL, token_expiry=NULL WHERE reset_token=?");
    $update->bind_param("ss", $new_pass, $token);
    $update->execute();
    echo "Password updated! You can now <a href='customer-login.php'>login</a>.";
    exit;
  }
}
?>

<form method="POST">
  <h2>Reset Your Password</h2>
  <input type="password" name="new_password" placeholder="New Password" required><br><br>
  <button type="submit">Update Password</button>
</form>
