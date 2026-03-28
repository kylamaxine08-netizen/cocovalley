<?php
session_start();
require_once '../admin/handlers/db_connect.php';
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    header("Location: customer-login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$stmt = $conn->prepare("
    SELECT 
        first_name,
        last_name,
        email,
        phone,
        birthdate,
        gender,
        avatar_path
    FROM users 
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* ============================================================
      SAFE VARIABLE PREPARATION
============================================================ */
$first_name = htmlspecialchars($user['first_name'] ?? '');
$last_name  = htmlspecialchars($user['last_name'] ?? '');
$email      = htmlspecialchars($user['email'] ?? '');
$phone      = htmlspecialchars($user['phone'] ?? '');
$birthdate  = htmlspecialchars($user['birthdate'] ?? '');
$gender     = htmlspecialchars($user['gender'] ?? '');

if (!empty($user['avatar_path'])) {

    // Ensure path does not break directory structure
    $cleanPath = ltrim($user['avatar_path'], '/\\');

    // Build correct full path
    $avatar = '../admin/' . $cleanPath;

} else {
    // Auto-generated avatar
    $avatar = 'https://ui-avatars.com/api/?name=' . urlencode($first_name . ' ' . $last_name)
            . '&background=004d40&color=fff';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Account • Coco Valley</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

<style>
:root {
  --primary:#004d40;
  --secondary:#26a69a;
  --panel:#ffffff;
  --bg:#f4f7f6;
  --shadow:0 6px 18px rgba(0,0,0,0.08);
}

body {
  background: linear-gradient(135deg,#f6f9f8,#e8f5f3);
  font-family: "Inter","Segoe UI",sans-serif;
  color: #0f172a;
}

.main { margin-left: 250px; transition: margin-left 0.3s ease; }

.card {
  border:0;
  border-radius:14px;
  background:var(--panel);
  box-shadow:var(--shadow);
  padding:20px;
}
.card h5 {color:var(--primary); font-weight:700;}
.subtle {color:#64748b;}
.form-control:focus {box-shadow:0 0 0 3px rgba(38,166,154,.25); border-color:#9be0d4;}
</style>
</head>
<body>

<?php include 'sidebar-customer.php'; ?>

<main class="main container py-4">
  <div class="card mb-4">
    <h5>Profile Information</h5>
    <div class="subtle mb-3">Update your personal details and profile photo.</div>

    <form action="update-profile.php" method="POST" enctype="multipart/form-data">
      <!-- Avatar -->
      <div class="text-center mb-3">
        <div class="mx-auto mb-2" style="width:120px;height:120px;overflow:hidden;border-radius:18px;border:2px solid #ddd;position:relative;">
          <img id="avatarPreview" src="<?= $avatar ?>" alt="Avatar" style="width:100%;height:100%;object-fit:cover;">
        </div>
        <input type="file" name="avatar" id="avatarInput" accept="image/*" class="form-control mb-2" style="max-width:250px;margin:auto;">
      </div>

      <!-- Auto-filled fields -->
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">First Name</label>
          <input type="text" name="first_name" class="form-control" value="<?= $first_name ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Last Name</label>
          <input type="text" name="last_name" class="form-control" value="<?= $last_name ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Email</label>
          <input type="email" class="form-control" value="<?= $email ?>" readonly>
        </div>
        <div class="col-md-6">
          <label class="form-label">Phone</label>
          <input type="text" name="phone" class="form-control" value="<?= $phone ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Birthday</label>
          <input type="date" name="birthdate" class="form-control" value="<?= $birthdate ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Gender</label>
          <select name="gender" class="form-select">
            <option value="">Select Gender</option>
            <option value="Male" <?= ($gender === 'Male') ? 'selected' : '' ?>>Male</option>
            <option value="Female" <?= ($gender === 'Female') ? 'selected' : '' ?>>Female</option>
          </select>
        </div>
      </div>

      <div class="text-end mt-4">
        <button class="btn btn-success px-4"><i class="bx bx-save me-1"></i> Save Changes</button>
      </div>
    </form>
  </div>

  <!-- Change Password Section -->
  <div class="card mb-3">
    <h5>Change Password</h5>
    <div class="subtle mb-3">Use 8+ characters with a mix of letters & numbers.</div>
    <form action="update-password.php" method="POST">
      <div class="mb-3">
        <label class="form-label">Current Password</label>
        <input type="password" name="current_password" class="form-control" required>
      </div>
      <div class="mb-3">
        <label class="form-label">New Password</label>
        <input type="password" name="new_password" class="form-control" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Confirm Password</label>
        <input type="password" name="confirm_password" class="form-control" required>
      </div>
      <div class="text-end">
        <button class="btn btn-outline-success"><i class='bx bx-lock-alt me-1'></i> Update Password</button>
      </div>
    </form>
  </div>
</main>

<script>
// Live avatar preview
document.getElementById('avatarInput').addEventListener('change', e => {
  const file = e.target.files[0];
  if (file) {
    const reader = new FileReader();
    reader.onload = () => document.getElementById('avatarPreview').src = reader.result;
    reader.readAsDataURL(file);
  }
});
</script>

</body>
</html>
