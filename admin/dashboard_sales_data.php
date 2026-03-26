<?php

session_start();
require_once __DIR__ . "/../config/database.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] !== "admin") {
    http_response_code(403);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode(["error" => "Unauthorized"]);
    exit();
}

$today = new DateTimeImmutable("today");
$startDate = $today->modify("-6 days")->format("Y-m-d");
$endDate = $today->format("Y-m-d");

$labels = [];
$labelDates = [];
$values = [];

for ($i = 6; $i >= 0; $i--) {
    $date = $today->modify("-{$i} days")->format("Y-m-d");
    $labelDates[] = $date;
    $labels[] = $today->modify("-{$i} days")->format("d M");
    $values[] = 0;
}

// Single query for the whole range; avoids N+1 query pattern.
$stmt = $conn->prepare(
    "SELECT DATE(bill_date) AS day, SUM(total_amount) AS total
     FROM bill
     WHERE DATE(bill_date) BETWEEN ? AND ?
     GROUP BY DATE(bill_date)"
);

if ($stmt) {
    $stmt->bind_param("ss", $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        $totalsByDate = [];
        while ($row = $result->fetch_assoc()) {
            $totalsByDate[$row['day']] = (float)($row['total'] ?? 0);
        }

        foreach ($labelDates as $index => $date) {
            if (isset($totalsByDate[$date])) {
                $values[$index] = $totalsByDate[$date];
            }
        }
    }

    $stmt->close();
}

header("Content-Type: application/json; charset=UTF-8");
echo json_encode([
    "labels" => $labels,
    "values" => $values
]);




