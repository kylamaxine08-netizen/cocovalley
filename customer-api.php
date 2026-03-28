<?php
session_start();
require_once '../admin/handlers/db_connect.php';

// ✅ Secure access
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
  exit;
}

header('Content-Type: application/json; charset=utf-8');

// INPUTS
$q       = trim($_GET['q'] ?? '');
$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = max(1, intval($_GET['per_page'] ?? 100));
$offset  = ($page - 1) * $perPage;

// 🔎 SEARCH FILTER
$where = "WHERE role='customer'";
if ($q !== '') {
  $safe = "%{$conn->real_escape_string($q)}%";
  $where .= " AND (CONCAT(first_name, ' ', last_name) LIKE '$safe' OR email LIKE '$safe')";
}

// ===============================================
// ✅ Fetch customers (WITH created_at)
// ===============================================
$sql = "
  SELECT 
    id,
    CONCAT(first_name, ' ', last_name) AS name,
    email,
    phone AS contact,
    CASE WHEN status = 1 THEN 'active' ELSE 'inactive' END AS status,
    created_at
  FROM users
  $where
  ORDER BY created_at DESC
  LIMIT $offset, $perPage
";

$res = $conn->query($sql);

$list = [];
while ($row = $res->fetch_assoc()) {
  $list[] = [
    "id"         => $row["id"],
    "name"       => $row["name"],
    "email"      => $row["email"],
    "contact"    => $row["contact"],
    "status"     => $row["status"],
    "created_at" => $row["created_at"]   // 👈 NOW INCLUDED
  ];
}

// ===============================================
// ✅ Count total customers
// ===============================================
$countSql = "SELECT COUNT(*) AS total FROM users $where";
$countRes = $conn->query($countSql);
$total = ($countRes->fetch_assoc())['total'] ?? 0;

// ===============================================
// ✅ JSON Output
// ===============================================
echo json_encode([
  "ok"    => true,
  "total" => intval($total),
  "data"  => $list
], JSON_PRETTY_PRINT);

?>
