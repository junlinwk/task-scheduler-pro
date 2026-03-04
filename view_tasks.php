<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$userId = current_user_id();

// Simple debug page to list all tasks (not for production)
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><title>View tasks</title>';
echo '<h2>Your task table contents</h2>';

$stmt = $mysqli->prepare('SELECT * FROM tasks WHERE user_id = ? ORDER BY id ASC');
if (!$stmt) {
    echo '<p><strong>Error preparing query:</strong> ' . htmlspecialchars($mysqli->error, ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}
$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();

if (!$res) {
    echo '<p><strong>Error querying tasks:</strong> ' . htmlspecialchars($mysqli->error, ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}

echo '<table border="1" cellpadding="6" style="border-collapse:collapse">';
echo '<thead><tr>';
$fields = $res->fetch_fields();
foreach ($fields as $f) { echo '<th>' . htmlspecialchars($f->name) . '</th>'; }
echo '</tr></thead>';
echo '<tbody>';
while ($row = $res->fetch_assoc()) {
    echo '<tr>';
    foreach ($row as $v) { echo '<td>' . htmlspecialchars((string)$v) . '</td>'; }
    echo '</tr>';
}
echo '</tbody></table>';

echo '<p>If the table is empty, check the database name in <code>db.php</code> and whether your current user/session matches the sample data (user_id).</p>';

echo '<p><a href="/">Back to app</a></p>';

?>
