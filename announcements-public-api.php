<?php
// announcements-public-api.php
declare(strict_types=1);
header('Content-Type: application/json');

// (Optional) basic CORS kung kukunin ng ibang domain/subdomain:
// header('Access-Control-Allow-Origin: https://your-customer-domain.com');

require __DIR__ . '/db.php';

// optional query params
$q   = trim((string)($_GET['q'] ?? ''));         // keyword search
$now = (new DateTime('today'))->format('Y-m-d');  // "active today"
$limit = min(200, max(1, (int)($_GET['limit'] ?? 50)));

$where = " WHERE is_published = 1
           AND start_date <= :now
           AND end_date   >= :now ";
$params = [':now' => $now];

if ($q !== '') {
  $where .= " AND (title LIKE :q OR message LIKE :q) ";
  $params[':q'] = "%{$q}%";
}

$sql = "SELECT id, title, message, image_url,
               start_date, end_date, created_at
        FROM announcements
        {$where}
        ORDER BY start_date DESC, created_at DESC
        LIMIT :lim";

$stmt = $pdo->prepare($sql);
foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->execute();

echo json_encode(['ok'=>true, 'rows'=>$stmt->fetchAll()]);
