<?php
// customer-edit-profile.php (UPGRADED UI)
declare(strict_types=1);
session_start();
require_once '../admin/handlers/db_connect.php';

if (!isset($_SESSION['customer_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    header("Location: customer-login.php");
    exit;
}

$customer_id = (int) $_SESSION['customer_id'];

$stmt = $conn->prepare("
    SELECT first_name, last_name, email, phone, gender, birthdate
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param("i", $customer_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Profile • Coco Valley</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

<style>
:root {
  --primary:#004d40;
  --primary-dark:#00352d;
  --accent:#26a69a;
  --bg:#f3f4f6;
  --panel:#ffffff;
  --border:#d1d5db;
  --muted:#6b7280;
  --text:#0f172a;
}

body {
  background: var(--bg);
  font-family: "Inter", sans-serif;
}

.main {
  margin-left: 250px;
  padding: 28px 32px;
}

@media (max-width: 992px) {
  .main {
    margin-left: 0;
    padding: 90px 20px;
  }
}

.card-custom {
  background: var(--panel);
  border-radius: 18px;
  border: 1px solid var(--border);
  box-shadow: 0 10px 25px rgba(0,0,0,0.05);
}

.form-control, .form-select {
  border-radius: 12px;
  padding: .8rem;
  border: 1px solid var(--border);
}

.form-control:focus, .form-select:focus {
  border-color: var(--primary);
  box-shadow: 0 0 0 1px rgba(0,77,64,.35);
}

.btn-main {
  background: var(--primary);
  color: #fff;
  padding: .8rem;
  border-radius: 12px;
  width: 100%;
  border: none;
  font-weight: 600;
  transition: .2s;
}
.btn-main:hover {
  background: var(--primary-dark);
}

.password-toggle {
  position: absolute;
  top: 50%;
  right: 14px;
  transform: translateY(-50%);
  cursor: pointer;
  color: var(--muted);
}
</style>
</head>
<body>

<?php include 'sidebar-customer.php'; ?>

<main class="main">
  <div class="container">
    <div class="card-custom p-4 mx-auto" style="max-width: 750px;">

      <h4 class="fw-bold text-primary mb-2">
        <i class="bx bx-user-circle"></i> Edit Profile
      </h4>
      <p class="text-muted mb-4">Update your personal information below.</p>

      <form action="customer-profile-update.php" method="post">

        <div class="row g-3">

          <div class="col-md-6">
            <label class="form-label fw-semibold">First Name</label>
            <input type="text" name="first_name" class="form-control"
                   value="<?= htmlspecialchars($user['first_name']) ?>" required>
          </div>

          <div class="col-md-6">
            <label class="form-label fw-semibold">Last Name</label>
            <input type="text" name="last_name" class="form-control"
                   value="<?= htmlspecialchars($user['last_name']) ?>" required>
          </div>

          <div class="col-md-6">
            <label class="form-label fw-semibold">Email</label>
            <input type="email" name="email" class="form-control"
                   value="<?= htmlspecialchars($user['email']) ?>" required>
          </div>

          <div class="col-md-6">
            <label class="form-label fw-semibold">Phone Number</label>
            <input type="text" name="phone" maxlength="11" pattern="09[0-9]{9}"
                   class="form-control"
                   placeholder="09xxxxxxxxx"
                   value="<?= htmlspecialchars($user['phone']) ?>">
          </div>

          <div class="col-md-6">
            <label class="form-label fw-semibold">Gender</label>
            <select name="gender" class="form-select">
              <option value="">-- Select --</option>
              <option value="Male"   <?= $user['gender']==='Male'?'selected':''; ?>>Male</option>
              <option value="Female" <?= $user['gender']==='Female'?'selected':''; ?>>Female</option>
              <option value="Prefer not to say" <?= $user['gender']==='Prefer not to say'?'selected':''; ?>>Prefer not to say</option>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label fw-semibold">Birthdate</label>
            <input type="date" name="birthdate" class="form-control"
                   value="<?= htmlspecialchars($user['birthdate']) ?>">
          </div>

        </div>

        <hr class="my-4">

        <h5 class="fw-bold">Change Password <small class="text-muted">(Optional)</small></h5>

        <div class="row g-3 mt-1">

          <div class="col-md-6 position-relative">
            <label class="form-label fw-semibold">New Password</label>
            <input type="password" name="new_password" class="form-control" id="newPass">
            <i class="bx bx-show password-toggle" onclick="togglePassword('newPass', this)"></i>
          </div>

          <div class="col-md-6 position-relative">
            <label class="form-label fw-semibold">Confirm Password</label>
            <input type="password" name="confirm_password" class="form-control" id="confirmPass">
            <i class="bx bx-show password-toggle" onclick="togglePassword('confirmPass', this)"></i>
          </div>
        </div>

        <button class="btn-main mt-4">
          <i class="bx bx-save"></i> Save Changes
        </button>

      </form>
    </div>
  </div>
</main>

<script>
function togglePassword(id, icon) {
  const input = document.getElementById(id);
  if (input.type === "password") {
    input.type = "text";
    icon.classList.replace("bx-show", "bx-hide");
  } else {
    input.type = "password";
    icon.classList.replace("bx-hide", "bx-show");
  }
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
