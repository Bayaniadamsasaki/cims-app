<?php
$db = new PDO('mysql:host=127.0.0.1;dbname=db-cims26;charset=utf8mb4', 'root', '');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$stmt = $db->query("SELECT COUNT(*) as cnt FROM devices WHERE serial_number = 'HD508CJZHSR'");
$row = $stmt->fetch();
echo "Count of devices with serial HD508CJZHSR: " . $row['cnt'] . "\n";

$stmt2 = $db->query("SELECT COUNT(*) as cnt FROM devices");
$row2 = $stmt2->fetch();
echo "Total devices: " . $row2['cnt'] . "\n";