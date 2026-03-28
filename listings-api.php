<?php
// listings-api.php
declare(strict_types=1);
header('Content-Type: application/json');
require __DIR__ . '/db.php';

$action = $_REQUEST['action'] ?? 'list';
$kind   = $_REQUEST['kind']   ?? 'cottages'; // cottages | rooms | events

function tableFor(string $kind): array {
  switch ($kind) {
    case 'rooms':   return ['table'=>'rooms','pk'=>'id'];
    case 'events':  return ['table'=>'event_spaces','pk'=>'id'];
    default:        return ['table'=>'cottages','pk'=>'id'];
  }
}
function ensureUploadDir(string $dir): void {
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  if (!is_dir($dir) || !is_writable($dir)) throw new RuntimeException('Upload directory not writable');
}

try {
  $meta = tableFor($kind);
  $table = $meta['table'];

  if ($action === 'list') {
    $rows = $pdo->query("SELECT * FROM {$table} ORDER BY created_at DESC, id DESC")->fetchAll();
    echo json_encode(['ok'=>true,'rows'=>$rows]);
    exit;
  }

  if ($action === 'save') {
    $id = isset($_POST['id']) && $_POST['id']!=='' ? (int)$_POST['id'] : null;
    $name = trim((string)($_POST['name'] ?? ''));
    $desc = trim((string)($_POST['description'] ?? ''));
    $imageUrl = trim((string)($_POST['image_url'] ?? ''));

    // Optional upload
    if (!empty($_FILES['image']['name'])) {
      ensureUploadDir(__DIR__.'/uploads/listings');
      $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
      $fname = $kind.'_'.date('Ymd_His').'_' . bin2hex(random_bytes(3)) . '.' . ($ext ?: 'jpg');
      $dest = __DIR__ . '/uploads/listings/' . $fname;
      if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
        echo json_encode(['ok'=>false,'error'=>'Upload failed']); exit;
      }
      $imageUrl = 'uploads/listings/' . $fname;
    }

    if ($kind === 'cottages') {
      $capacity = (int)($_POST['capacity'] ?? 0);
      $rate     = (float)($_POST['rate'] ?? 0);
      if ($id === null) {
        $stmt = $pdo->prepare("INSERT INTO cottages (name,capacity,rate,image_url,description) VALUES (?,?,?,?,?)");
        $stmt->execute([$name,$capacity,$rate,$imageUrl ?: null,$desc ?: null]);
        echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]);
      } else {
        $stmt = $pdo->prepare("UPDATE cottages SET name=?,capacity=?,rate=?,image_url=?,description=? WHERE id=?");
        $stmt->execute([$name,$capacity,$rate,$imageUrl ?: null,$desc ?: null,$id]);
        echo json_encode(['ok'=>true,'id'=>$id]);
      }
      exit;
    }

    if ($kind === 'rooms') {
      $pax   = (int)($_POST['pax'] ?? 0);
      $rate10= (float)($_POST['rate10'] ?? 0);
      $rate22= (float)($_POST['rate22'] ?? 0);
      if ($id === null) {
        $stmt = $pdo->prepare("INSERT INTO rooms (name,pax,rate10,rate22,image_url,description) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$name,$pax,$rate10,$rate22,$imageUrl ?: null,$desc ?: null]);
        echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]);
      } else {
        $stmt = $pdo->prepare("UPDATE rooms SET name=?,pax=?,rate10=?,rate22=?,image_url=?,description=? WHERE id=?");
        $stmt->execute([$name,$pax,$rate10,$rate22,$imageUrl ?: null,$desc ?: null,$id]);
        echo json_encode(['ok'=>true,'id'=>$id]);
      }
      exit;
    }

    // events
    $capacity = (int)($_POST['capacity'] ?? 0);
    $rate     = (float)($_POST['rate'] ?? 0);
    if ($id === null) {
      $stmt = $pdo->prepare("INSERT INTO event_spaces (name,capacity,rate,image_url,description) VALUES (?,?,?,?,?)");
      $stmt->execute([$name,$capacity,$rate,$imageUrl ?: null,$desc ?: null]);
      echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]);
    } else {
      $stmt = $pdo->prepare("UPDATE event_spaces SET name=?,capacity=?,rate=?,image_url=?,description=? WHERE id=?");
      $stmt->execute([$name,$capacity,$rate,$imageUrl ?: null,$desc ?: null,$id]);
      echo json_encode(['ok'=>true,'id'=>$id]);
    }
    exit;
  }

  if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id<=0) { echo json_encode(['ok'=>false,'error'=>'Invalid id']); exit; }
    $stmt = $pdo->prepare("DELETE FROM {$table} WHERE id=?");
    $stmt->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
  }

  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Unknown action']);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
