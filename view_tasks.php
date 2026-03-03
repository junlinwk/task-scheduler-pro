<?php
require_once __DIR__ . '/db.php';

// Simple debug page to list all tasks (not for production)
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><title>View tasks</title>';
echo '<h2>Tasks table contents</h2>';

$res = $mysqli->query('SELECT * FROM tasks ORDER BY id ASC');
if (! $res) {
    echo '<p><strong>Error querying tasks:</strong> ' . htmlspecialchars($mysqli->error) . '</p>';
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

echo '<h3>Helpful info</h3>';
echo '<ul>';
echo '<li>Database host: ' . htmlspecialchars($DB_HOST) . '</li>';
echo '<li>Database name: ' . htmlspecialchars($DB_NAME) . '</li>';
echo '<li>Database user: ' . htmlspecialchars($DB_USER) . '</li>';
echo '</ul>';

echo '<p><a href="/">Back to app</a></p>';

?>