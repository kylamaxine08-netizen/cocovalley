<?php
// announcements-api.php
declare(strict_types=1);
header('Content-Type: application/json');

require __DIR__ . '/db.php';

$action = $_REQUEST['action'] ?? 'list';

function ensureUploadDir(string $dir): void {
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
  if (!is_dir($dir) || !is_writable($dir)) {
    throw new RuntimeException('Upload directory not writable');
  }
}

try {
  if ($action === 'list') {
    // Optional filters/pagination (simple)
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per  = min(200, max(1, (int)($_GET['per'] ?? 100)));
    $off  = ($page - 1) * $per;

    $q = trim((string)($_GET['q'] ?? ''));
    $where = '';
    $bind = [];
    if ($q !== '') {
      $where = 'WHERE title LIKE :q OR message LIKE :q';
      $bind[':q'] = "%{$q}%";
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM announcements {$where}");
    foreach ($bind as $k=>$v) $stmt->bindValue($k,$v);
    $stmt->execute();
    $total = (int)$stmt->fetchColumn();

    $sql = "SELECT id,title,message,image_url,start_date,end_date,created_at
            FROM announcements
            {$where}
            ORDER BY created_at DESC, id DESC
            LIMIT :per OFFSET :off";
    $stmt = $pdo->prepare($sql);
    foreach ($bind as $k=>$v) $stmt->bindValue($k,$v);
    $stmt->bindValue(':per',$per,PDO::PARAM_INT);
    $stmt->bindValue(':off',$off,PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    echo json_encode(['ok'=>true,'total'=>$total,'page'=>$page,'per'=>$per,'rows'=>$rows]);
    exit;
  }

  if ($action === 'save') {
    // create or update (multipart/form-data accepted)
    $id       = isset($_POST['id']) && $_POST['id']!=='' ? (int)$_POST['id'] : null;
    $title    = trim((string)($_POST['title'] ?? ''));
    $message  = trim((string)($_POST['message'] ?? ''));
    $start    = trim((string)($_POST['start'] ?? ''));
    $end      = trim((string)($_POST['end'] ?? ''));
    $imageUrl = trim((string)($_POST['image_url'] ?? ''));

    if ($title === '' || $message === '' || $start === '' || $end === '') {
      echo json_encode(['ok'=>false,'error'=>'Missing required fields']);
      exit;
    }

    // file upload optional
    if (!empty($_FILES['image']['name'])) {
      ensureUploadDir(__DIR__.'/uploads/announcements');
      $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
      $fname = 'ann_'.date('Ymd_His').'_' . bin2hex(random_bytes(3)) . '.' . ($ext ?: 'jpg');
      $dest = __DIR__ . '/uploads/announcements/' . $fname;
      if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
        echo json_encode(['ok'=>false,'error'=>'Upload failed']);
        exit;
      }
      $imageUrl = 'uploads/announcements/' . $fname;
    }

    if ($id === null) {
      $stmt = $pdo->prepare("INSERT INTO announcements (title,message,image_url,start_date,end_date)
                             VALUES (?,?,?,?,?)");
      $stmt->execute([$title,$message,$imageUrl ?: null,$start,$end]);
      echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]);
    } else {
      $stmt = $pdo->prepare("UPDATE announcements
                             SET title=?, message=?, image_url=?, start_date=?, end_date=? WHERE id=?");
      $stmt->execute([$title,$message,$imageUrl ?: null,$start,$end,$id]);
      echo json_encode(['ok'=>true,'id'=>$id]);
    }
    exit;
  }

  if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id<=0) { echo json_encode(['ok'=>false,'error'=>'Invalid id']); exit; }
    $stmt = $pdo->prepare("DELETE FROM announcements WHERE id=?");
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
