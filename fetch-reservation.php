<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db_connect.php';

try {
  // ✅ Only show approved reservations (for both admin & customer calendar)
  $query = "
    SELECT
      id,
      code,
      customer_name,
      type AS category,
      package,
      start_date,
      end_date,
      time_slot,
      pax,
      status
    FROM reservations
    WHERE status = 'approved'
    ORDER BY start_date ASC
  ";

  $result = $conn->query($query);
  $rows = [];

  if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
      // Format for JSON output
      $rows[] = [
        'id' => $row['id'],
        'customer_name' => $row['customer_name'],
        'category' => $row['category'],
        'package' => $row['package'],
        'status' => $row['status'],
        'start' => $row['start_date'],
        'end' => $row['end_date'],
        'pax' => $row['pax'],
        'time_slot' => $row['time_slot'],
      ];
    }
  }

  echo json_encode($rows, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}
