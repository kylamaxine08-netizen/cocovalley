<?php
// reports-api.php
declare(strict_types=1);
header('Content-Type: application/json');

require __DIR__.'/db.php';

/**
 * Returns:
 * { ok:true, type, labels:[], data:[], count:[] }
 * - data[]  : revenue (sum of approved payments.amount)
 * - count[] : number of reservations that have an approved payment
 *
 * Query:
 *   ?action=summary&period=daily|monthly|yearly
 */
$action = $_GET['action'] ?? 'summary';
$period = $_GET['period'] ?? 'monthly'; // default

if ($action !== 'summary') {
  echo json_encode(['ok'=>false,'error'=>'Unsupported action']); exit;
}

try {
  if ($period === 'daily') {
    // last 14 days
    $stmt = $pdo->query("
      WITH days AS (
        SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL
        SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL
        SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL
        SELECT 12 UNION ALL SELECT 13
      )
      SELECT DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL d DAY),'%Y-%m-%d') AS day_key
      FROM days ORDER BY day_key
    ");
    $labels = [];
    while($r = $stmt->fetch()) $labels[] = $r['day_key'];

    // revenue + counts per day
    $q = $pdo->query("
      SELECT DATE(p.paid_at) AS k,
             SUM(p.amount)   AS rev,
             COUNT(DISTINCT p.reservation_id) AS cnt
      FROM payments p
      WHERE p.status='approved'
        AND p.paid_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
      GROUP BY DATE(p.paid_at)
    ");
    $map = [];
    foreach($q as $r){ $map[$r['k']] = ['rev'=>(int)$r['rev'], 'cnt'=>(int)$r['cnt']]; }

    $data=[]; $count=[];
    foreach($labels as $k){
      $data[]  = $map[$k]['rev']  ?? 0;
      $count[] = $map[$k]['cnt']  ?? 0;
    }

    echo json_encode(['ok'=>true,'type'=>'daily','labels'=>$labels,'data'=>$data,'count'=>$count]); exit;
  }

  if ($period === 'monthly') {
    // current year by month
    $year = (int)date('Y');
    $labels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    $stmt = $pdo->prepare("
      SELECT MONTH(p.paid_at) AS m,
             SUM(p.amount) AS rev,
             COUNT(DISTINCT p.reservation_id) AS cnt
      FROM payments p
      WHERE p.status='approved' AND YEAR(p.paid_at)=?
      GROUP BY MONTH(p.paid_at)
    ");
    $stmt->execute([$year]);
    $map=[];
    foreach($stmt as $r){ $map[(int)$r['m']] = ['rev'=>(int)$r['rev'],'cnt'=>(int)$r['cnt']]; }

    $data=[]; $count=[];
    for($m=1;$m<=12;$m++){
      $data[]  = $map[$m]['rev']  ?? 0;
      $count[] = $map[$m]['cnt']  ?? 0;
    }
    echo json_encode(['ok'=>true,'type'=>'monthly','labels'=>$labels,'data'=>$data,'count'=>$count]); exit;
  }

  if ($period === 'yearly') {
    // since 2016 by year
    $start = 2016;
    $yearNow = (int)date('Y');
    $labels = [];
    for($y=$start;$y<=$yearNow;$y++) $labels[]=(string)$y;

    $stmt = $pdo->prepare("
      SELECT YEAR(p.paid_at) AS y,
             SUM(p.amount) AS rev,
             COUNT(DISTINCT p.reservation_id) AS cnt
      FROM payments p
      WHERE p.status='approved' AND YEAR(p.paid_at) BETWEEN ? AND ?
      GROUP BY YEAR(p.paid_at)
    ");
    $stmt->execute([$start, $yearNow]);
    $map=[];
    foreach($stmt as $r){ $map[(int)$r['y']] = ['rev'=>(int)$r['rev'],'cnt'=>(int)$r['cnt']]; }

    $data=[]; $count=[];
    for($y=$start;$y<=$yearNow;$y++){
      $data[]  = $map[$y]['rev']  ?? 0;
      $count[] = $map[$y]['cnt']  ?? 0;
    }
    echo json_encode(['ok'=>true,'type'=>'yearly','labels'=>$labels,'data'=>$data,'count'=>$count]); exit;
  }

  echo json_encode(['ok'=>false,'error'=>'Unknown period']);
} catch(Throwable $e){
  echo json_encode(['ok'=>false,'error'=>'Query failed']);
}
