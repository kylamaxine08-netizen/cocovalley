<?php
declare(strict_types=1);
header('Content-Type: application/json');

require __DIR__ . '/db.php';

/* Simple CSRF (pair with reservation-list.php) */
session_start();
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf'];

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

function json_out($arr){ echo json_encode($arr); exit; }

function to_like(string $s): string { return '%' . str_replace(['%','_'], ['\\%','\\_'], $s) . '%'; }

if ($action === 'list' || $action === 'export') {
  // Filters
  $status = $_GET['status'] ?? 'all';              // all|pending|approved|cancelled
  $type   = $_GET['type'] ?? 'all';                // all|cottage|room|event
  $q      = trim((string)($_GET['q'] ?? ''));
  $sort   = $_GET['sort'] ?? 'latest';             // latest|oldest|name
  $page   = max(1, (int)($_GET['page'] ?? 1));
  $per    = min(100, max(1, (int)($_GET['per'] ?? 10)));

  $where = [];
  $bind  = [];

  if (in_array($status, ['pending','approved','cancelled'], true)) {
    $where[] = 'r.status = :status';
    $bind[':status'] = $status;
  }
  if (in_array($type, ['cottage','room','event'], true)) {
    $where[] = 'r.type = :type';
    $bind[':type'] = $type;
  }
  if ($q !== '') {
    $where[] = '(r.customer_name LIKE :q OR r.package LIKE :q)';
    $bind[':q'] = to_like($q);
  }

  $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

  // Sorting
  if ($sort === 'name') $order = 'ORDER BY r.customer_name ASC, r.id DESC';
  elseif ($sort === 'oldest') $order = 'ORDER BY COALESCE(r.approved_at, r.created_at) ASC';
  else $order = 'ORDER BY COALESCE(r.approved_at, r.created_at) DESC';

  // Counts (for mini stats)
  $counts = [];
  foreach (['pending','approved','cancelled'] as $st) {
    $sqlC = "SELECT COUNT(*) AS c FROM reservations r ".($where ? 'WHERE '.implode(' AND ', array_map(
      fn($w)=>str_replace('r.status = :status', 'r.status = '.$pdo->quote($st), $w), $where)) : '');
    $stmtC = $pdo->prepare($sqlC);
    // bind except :status because we inlined per-st value
    $tmpBind = $bind; unset($tmpBind[':status']);
    $stmtC->execute($tmpBind);
    $counts[$st] = (int)$stmtC->fetchColumn();
  }
  // Total with current filters (ignoring pagination)
  $sqlTotal = "SELECT COUNT(*) FROM reservations r $whereSql";
  $stmtT = $pdo->prepare($sqlTotal);
  $stmtT->execute($bind);
  $total = (int)$stmtT->fetchColumn();

  // Rows
  $offset = ($page-1)*$per;
  $sql = "SELECT r.*, u.name AS approved_by_name
          FROM reservations r
          LEFT JOIN users u ON u.id = r.approved_by
          $whereSql
          $order
          LIMIT :limit OFFSET :offset";
  $stmt = $pdo->prepare($sql);
  foreach ($bind as $k=>$v) $stmt->bindValue($k, $v);
  $stmt->bindValue(':limit', $per, PDO::PARAM_INT);
  $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll();

  // format for front-end
  $events = array_map(function($r){
    return [
      'id'        => (int)$r['id'],
      'code'      => $r['code'],
      'name'      => $r['customer_name'],
      'status'    => $r['status'],
      'type'      => $r['type'],
      'pkg'       => $r['package'],
      'approvedAt'=> $r['approved_at'] ? date('Y-m-d H:i', strtotime($r['approved_at'])) : null,
      'time'      => $r['time_str'],
      'pax'       => (int)$r['pax'],
      'approvedBy'=> $r['approved_by_name'] ?: null,
      'cancelled' => $r['status']==='cancelled',
    ];
  }, $rows);

  if ($action === 'export') {
    // stream CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reservations.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['#','Code','Customer','Status','Type','Package','Approved At','Time','Pax','Approved By']);
    $i = $offset + 1;
    foreach ($events as $e) {
      fputcsv($out, [$i++, $e['code'], $e['name'], $e['status'], $e['type'], $e['pkg'], $e['approvedAt'], $e['time'], $e['pax'], $e['approvedBy']]);
    }
    fclose($out);
    exit;
  }

  json_out([
    'ok'=>true,
    'csrf'=>$CSRF,
    'page'=>$page, 'per'=>$per, 'total'=>$total,
    'counts'=>['total'=>$total, 'pending'=>$counts['pending'], 'approved'=>$counts['approved'], 'cancelled'=>$counts['cancelled']],
    'rows'=>$events
  ]);
}

if ($action === 'approve') {
  if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) json_out(['ok'=>false,'error'=>'Bad CSRF']);
  $id = (int)($_POST['id'] ?? 0);
  if ($id<=0) json_out(['ok'=>false,'error'=>'Invalid id']);

  // set approved if not cancelled
  $sql = "UPDATE reservations
          SET status='approved', approved_at=NOW(), approved_by=1
          WHERE id=:id AND status <> 'cancelled'";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([':id'=>$id]);

  json_out(['ok'=>true]);
}

if ($action === 'cancel') {
  if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) json_out(['ok'=>false,'error'=>'Bad CSRF']);
  $id = (int)($_POST['id'] ?? 0);
  $reason = trim((string)($_POST['reason'] ?? ''));
  if ($id<=0) json_out(['ok'=>false,'error'=>'Invalid id']);

  $sql = "UPDATE reservations
          SET status='cancelled', cancelled_at=NOW(), cancel_reason=:reason
          WHERE id=:id";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([':id'=>$id, ':reason'=>$reason]);

  json_out(['ok'=>true]);
}

json_out(['ok'=>false,'error'=>'Unknown action']);
