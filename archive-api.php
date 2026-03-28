<?php
// archive-api.php
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/db.php';

function json_out($arr, int $code = 200){
  http_response_code($code);
  echo json_encode($arr);
  exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_REQUEST['action'] ?? '';

try {
  if ($method === 'GET' && $action === 'list') {
    $tab = strtolower(trim($_GET['tab'] ?? 'active'));
    if (!in_array($tab, ['active','archived'], true)) $tab = 'active';

    $sql = "SELECT id, full_name AS name, email, last_login, status
            FROM customers
            WHERE status = :status
            ORDER BY COALESCE(last_login, '1970-01-01') DESC, id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':status'=>$tab]);
    $rows = $stmt->fetchAll();

    json_out(['ok'=>true, 'rows'=>$rows]);
  }

  if ($method === 'POST' && in_array($action, ['archive','restore','delete'], true)) {
    // Basic CSRF (optional): if you’re using a session token, check here.

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) json_out(['ok'=>false,'error'=>'Invalid id'], 422);

    if ($action === 'archive') {
      // only archive if currently active
      $sql = "UPDATE customers
              SET status='archived', archived_at=NOW()
              WHERE id=:id AND status='active'";
      $stmt=$pdo->prepare($sql);
      $stmt->execute([':id'=>$id]);
      json_out(['ok'=>true, 'updated'=>$stmt->rowCount()]);
    }

    if ($action === 'restore') {
      // only restore if currently archived
      $sql = "UPDATE customers
              SET status='active', archived_at=NULL
              WHERE id=:id AND status='archived'";
      $stmt=$pdo->prepare($sql);
      $stmt->execute([':id'=>$id]);
      json_out(['ok'=>true, 'updated'=>$stmt->rowCount()]);
    }

    if ($action === 'delete') {
      // hard delete allowed only if archived
      $sql = "DELETE FROM customers WHERE id=:id AND status='archived'";
      $stmt=$pdo->prepare($sql);
      $stmt->execute([':id'=>$id]);
      json_out(['ok'=>true, 'deleted'=>$stmt->rowCount()]);
    }
  }

  json_out(['ok'=>false,'error'=>'Unsupported request'], 400);

} catch (Throwable $e) {
  json_out(['ok'=>false,'error'=>'Server error','detail'=>$e->getMessage()], 500);
}
