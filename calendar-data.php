<?php
// calendar-data.php
// Returns JSON for admin calendar (approved reservations only)

header('Content-Type: application/json; charset=utf-8');

// ❌ Huwag mag-echo ng PHP warnings sa JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/handlers/db_connect.php';

$start = $_GET['start'] ?? null;
$end   = $_GET['end']   ?? null;

if (!$start || !$end) {
    echo json_encode([
        'ok'      => false,
        'message' => 'Missing start or end parameter.',
        'events'  => []
    ]);
    exit;
}

// sanitize / validate dates
$startTs = strtotime($start);
$endTs   = strtotime($end);

if ($startTs === false || $endTs === false) {
    echo json_encode([
        'ok'      => false,
        'message' => 'Invalid date format.',
        'events'  => []
    ]);
    exit;
}

$startDate = date('Y-m-d', $startTs);
$endDate   = date('Y-m-d', $endTs);

$events = [];

// NOTE: added r.cottage_number
$sql = "
  SELECT 
    r.id,
    r.code,
    r.customer_name,
    r.package,
    r.type          AS category,
    r.pax,
    r.time_slot,
    r.start_date,
    COALESCE(r.end_date, r.start_date) AS end_date,
    r.cottage_number
  FROM reservations r
  WHERE r.status = 'approved'
    AND r.start_date <= ?
    AND COALESCE(r.end_date, r.start_date) >= ?
  ORDER BY r.start_date ASC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode([
        'ok'      => false,
        'message' => 'SQL prepare error: ' . $conn->error,
        'events'  => []
    ]);
    exit;
}

$stmt->bind_param('ss', $endDate, $startDate);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $customer = $row['customer_name'] ?: 'Guest';
    $type     = strtolower($row['category'] ?? 'event');

    // cottage_number can be null or empty for non-cottage
    $cottageNumber = $row['cottage_number'] ?? null;

    // Title sample: "Cottage • Lane (#1)" kung may number
    $baseTitle = sprintf('%s • %s', ucfirst($type), $row['package']);
    if ($type === 'cottage' && $cottageNumber !== null && $cottageNumber !== '') {
        $baseTitle .= ' (#' . $cottageNumber . ')';
    }

    $events[] = [
        'id'             => (int)$row['id'],
        'code'           => $row['code'],
        'customer'       => $customer,
        'type'           => $type,                // cottage / room / event
        'package'        => $row['package'],
        'pax'            => (int)$row['pax'],
        'time_slot'      => $row['time_slot'],
        'start'          => $row['start_date'],
        'end'            => $row['end_date'],
        'cottage_number' => $cottageNumber,
        'title'          => $baseTitle
    ];
}

$stmt->close();

echo json_encode([
    'ok'      => true,
    'message' => 'OK',
    'events'  => $events
]);
