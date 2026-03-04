<?php
require_once 'db.php';
require_once 'auth.php';
require_login();

$user_id = current_user_id();
$csrf_token = csrf_token();
$message = '';
$today = new DateTime('today');
$today_key = $today->format('Y-m-d');
$stats = [
    'total' => 0,
    'completed' => 0,
    'active' => 0,
    'overdue' => 0,
    'upcoming' => 0,
];
$category_counts = [];
$next_deadline = null;
$calendar_events = [];

if (!function_exists('format_category_label')) {
    function format_category_label($name) {
        $label = '';
        if (is_string($name)) {
            $label = trim($name);
        }

        if ($label === '') {
            return 'None';
        }

        return strcasecmp($label, 'none') === 0 ? 'None' : $label;
    }
}

if (!function_exists('is_ajax_request')) {
    function is_ajax_request() {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return true;
        }
        $accepts_json = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
        return $accepts_json;
    }
}

if (!function_exists('escape_data_value')) {
    function escape_data_value($value) {
        $safe = htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
        return str_replace(["\r", "\n"], ['&#13;', '&#10;'], $safe);
    }
}

if (!function_exists('normalize_task_title')) {
    function normalize_task_title($value) {
        return sanitize_single_line($value, 140);
    }
}

if (!function_exists('normalize_category_name')) {
    function normalize_category_name($value) {
        return sanitize_single_line($value, 80);
    }
}

if (!function_exists('category_belongs_to_user')) {
    function category_belongs_to_user($mysqli, $user_id, $category_id) {
        $stmt = $mysqli->prepare('SELECT id FROM categories WHERE id = ? AND user_id = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ii', $category_id, $user_id);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        return $exists;
    }
}

if (!function_exists('format_timeline_tasks')) {
    function format_timeline_tasks($task_list) {
        return array_map(function ($task) {
            return [
                'id' => $task['id'] ?? null,
                'title' => $task['title'],
                'category' => format_category_label($task['category_name'] ?? null),
                'deadline' => $task['deadline_formatted'] ?? null,
                'relative' => $task['deadline_relative'] ?? 'Anytime',
                'status' => $task['status_slug'],
                'is_done' => (bool)$task['is_done'],
                'deadline_key' => $task['deadline_key'],
                'notes' => $task['notes'] ?? '',
            ];
        }, $task_list);
    }
}

if (!function_exists('calculate_stats')) {
    function calculate_stats($mysqli, $user_id) {
        global $today;
        $stats = [
            'total' => 0,
            'completed' => 0,
            'active' => 0,
            'overdue' => 0,
            'upcoming' => 0,
        ];
        
        $query = 'SELECT t.id, t.deadline, t.is_done FROM tasks t WHERE t.user_id = ?';
        $stmt = $mysqli->prepare($query);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->bind_result($tid, $tdeadline, $tdone);
        
        $next_deadline = null;
        while ($stmt->fetch()) {
            $deadline_obj = $tdeadline ? new DateTime($tdeadline) : null;
            $is_done = (bool)$tdone;
            
            $stats['total']++;
            if ($is_done) {
                $stats['completed']++;
            } else {
                $stats['active']++;
            }
            
            if ($deadline_obj && !$is_done) {
                if ($deadline_obj < $today) {
                    $stats['overdue']++;
                } else {
                    $stats['upcoming']++;
                    if ($next_deadline === null || $deadline_obj < $next_deadline) {
                        $next_deadline = clone $deadline_obj;
                    }
                }
            }
        }
        $stmt->close();
        
        $stats['completion_percent'] = $stats['total'] > 0 ? round(($stats['completed'] / $stats['total']) * 100) : 0;
        $stats['health_text'] = $stats['overdue'] > 0
            ? 'Needs attention - tackle overdue work first.'
            : ($stats['total'] === 0 ? 'Add tasks to start tracking progress.' : 'Great pace - keep shipping!');
        $stats['next_deadline'] = $next_deadline ? $next_deadline->format('M j, Y') : null;
        if ($next_deadline) {
            $diff = $today->diff($next_deadline);
            if ($diff->days === 0) {
                $stats['next_deadline_relative'] = 'Today';
            } elseif ($diff->days === 1) {
                $stats['next_deadline_relative'] = 'Tomorrow';
            } else {
                $stats['next_deadline_relative'] = 'In ' . $diff->days . ' days';
            }
        } else {
            $stats['next_deadline_relative'] = null;
        }
        
        return $stats;
    }
}

// Handle actions (add/edit/delete task/category, toggle done)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_valid_csrf_token($_POST['csrf_token'] ?? '')) {
        $prefer_json = is_ajax_request() || isset($_POST['ajax']);
        http_response_code(400);
        if ($prefer_json) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Invalid request token.',
            ]);
            exit;
        }
        die('Invalid request token.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add_task') {
        $title = normalize_task_title($_POST['title'] ?? '');
        $deadline = normalize_iso_date($_POST['deadline'] ?? '');
        $category_id = (int)($_POST['category_id'] ?? 0);
        $notes = '';

        if ($title !== '') {
            if ($category_id > 0 && !category_belongs_to_user($mysqli, $user_id, $category_id)) {
                $category_id = 0;
            }
            if ($category_id === 0) {
                $stmt = $mysqli->prepare('SELECT id FROM categories WHERE user_id = ? AND is_default = 1 LIMIT 1');
                $stmt->bind_param('i', $user_id);
                $stmt->execute();
                $stmt->bind_result($cid);
                if ($stmt->fetch()) {
                    $category_id = $cid;
                }
                $stmt->close();
            }
            $stmt = $mysqli->prepare('INSERT INTO tasks (user_id, category_id, title, deadline, is_done, notes) VALUES (?, ?, ?, ?, 0, ?)');
            $stmt->bind_param('iisss', $user_id, $category_id, $title, $deadline, $notes);
            $stmt->execute();
            $stmt->close();
        }
        header('Location: todo.php');
        exit;
    }

    if ($action === 'update_task_name') {
        $task_id = (int)($_POST['task_id'] ?? 0);
        $title = normalize_task_title($_POST['title'] ?? '');
        if ($task_id && $title !== '') {
            $stmt = $mysqli->prepare('UPDATE tasks SET title = ? WHERE id = ? AND user_id = ?');
            $stmt->bind_param('sii', $title, $task_id, $user_id);
            $stmt->execute();
            $stmt->close();
        }
        header('Location: todo.php');
        exit;
    }

    if ($action === 'update_task_category') {
        $task_id = (int)($_POST['task_id'] ?? 0);
        $category_id = (int)($_POST['category_id'] ?? 0);
        if ($task_id && $category_id && category_belongs_to_user($mysqli, $user_id, $category_id)) {
            $stmt = $mysqli->prepare('UPDATE tasks SET category_id = ? WHERE id = ? AND user_id = ?');
            $stmt->bind_param('iii', $category_id, $task_id, $user_id);
            $stmt->execute();
            $stmt->close();
        }
        header('Location: todo.php');
        exit;
    }

    if ($action === 'update_task_notes') {
        $task_id = (int)($_POST['task_id'] ?? 0);
        $notes_raw = sanitize_multiline_text($_POST['notes'] ?? '', 5000);
        $notes = trim($notes_raw) === '' ? '' : $notes_raw;
        $notes_error = null;

        if ($task_id) {
            $stmt = $mysqli->prepare('UPDATE tasks SET notes = ? WHERE id = ? AND user_id = ?');
            if ($stmt) {
                $stmt->bind_param('sii', $notes, $task_id, $user_id);
                if (!$stmt->execute()) {
                    $notes_error = $stmt->error ?: 'Failed to update notes.';
                }
                $stmt->close();
            } else {
                $notes_error = $mysqli->error ?: 'Failed to prepare notes update.';
            }
        } else {
            $notes_error = 'Invalid task.';
        }

        $prefer_json = is_ajax_request() || isset($_POST['ajax']);

        if ($prefer_json) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => $notes_error === null,
                'notes' => $notes,
                'task_id' => $task_id,
                'notes_html' => htmlspecialchars($notes, ENT_QUOTES, 'UTF-8'),
                'error' => $notes_error,
            ]);
            exit;
        }

        if ($notes_error) {
            $message = $notes_error;
        }
        header('Location: todo.php');
        exit;
    }

    if ($action === 'toggle_done') {
        $task_id = (int)($_POST['task_id'] ?? 0);
        $is_done = ((string)($_POST['is_done'] ?? '') === '1' || (string)($_POST['is_done'] ?? '') === 'on') ? 1 : 0;
        if ($task_id) {
            $stmt = $mysqli->prepare('UPDATE tasks SET is_done = ? WHERE id = ? AND user_id = ?');
            $stmt->bind_param('iii', $is_done, $task_id, $user_id);
            $stmt->execute();
            $stmt->close();
        }
        
        // Check if this is an AJAX request
        if ((isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
            header('Content-Type: application/json');
            $stats = calculate_stats($mysqli, $user_id);
            echo json_encode($stats);
            exit;
        }
        
        header('Location: todo.php');
        exit;
    }

    if ($action === 'delete_task') {
        $task_id = (int)($_POST['task_id'] ?? 0);
        if ($task_id) {
            $stmt = $mysqli->prepare('DELETE FROM tasks WHERE id = ? AND user_id = ?');
            $stmt->bind_param('ii', $task_id, $user_id);
            $stmt->execute();
            $stmt->close();
        }
        header('Location: todo.php');
        exit;
    }

    if ($action === 'add_category') {
        $name = normalize_category_name($_POST['name'] ?? '');
        $color = normalize_hex_color($_POST['color'] ?? '#5C6CFF', '#5C6CFF');
        if ($name !== '') {
            $is_default = 0;
            $stmt = $mysqli->prepare('INSERT INTO categories (user_id, name, is_default, color) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('isis', $user_id, $name, $is_default, $color);
            $stmt->execute();
            $stmt->close();
        }
        header('Location: todo.php');
        exit;
    }

    if ($action === 'update_category_name') {
        $category_id = (int)($_POST['category_id'] ?? 0);
        $name = normalize_category_name($_POST['name'] ?? '');
        if ($category_id && $name !== '') {
            // check default
            $stmt = $mysqli->prepare('SELECT is_default FROM categories WHERE id = ? AND user_id = ?');
            $stmt->bind_param('ii', $category_id, $user_id);
            $stmt->execute();
            $stmt->bind_result($is_default);
            if ($stmt->fetch() && !$is_default) {
                $stmt->close();
                $stmt2 = $mysqli->prepare('UPDATE categories SET name = ? WHERE id = ? AND user_id = ?');
                $stmt2->bind_param('sii', $name, $category_id, $user_id);
                $stmt2->execute();
                $stmt2->close();
            } else {
                $stmt->close();
            }
        }
        header('Location: todo.php');
        exit;
    }

    if ($action === 'update_category_color') {
        $category_id = (int)($_POST['category_id'] ?? 0);
        $color = normalize_hex_color($_POST['color'] ?? '', '#5C6CFF');
        if ($category_id && $color !== '') {
            $stmt = $mysqli->prepare('SELECT is_default FROM categories WHERE id = ? AND user_id = ?');
            $stmt->bind_param('ii', $category_id, $user_id);
            $stmt->execute();
            $stmt->bind_result($is_default);
            if ($stmt->fetch() && !$is_default) {
                $stmt->close();
                $stmt2 = $mysqli->prepare('UPDATE categories SET color = ? WHERE id = ? AND user_id = ?');
                $stmt2->bind_param('sii', $color, $category_id, $user_id);
                $stmt2->execute();
                $stmt2->close();
            } else {
                $stmt->close();
            }
        }
        header('Location: todo.php');
        exit;
    }

    if ($action === 'delete_category') {
        $category_id = (int)($_POST['category_id'] ?? 0);
        $delete_mode = $_POST['delete_mode'] ?? 'delete_all';
        if ($delete_mode !== 'delete_all' && $delete_mode !== 'detach') {
            $delete_mode = 'delete_all';
        }
        if ($category_id) {
            // check default
            $stmt = $mysqli->prepare('SELECT is_default FROM categories WHERE id = ? AND user_id = ?');
            $stmt->bind_param('ii', $category_id, $user_id);
            $stmt->execute();
            $stmt->bind_result($is_default);
            if ($stmt->fetch() && !$is_default) {
                $stmt->close();
                if ($delete_mode === 'detach') {
                    // Move tasks to default/None category
                    $default_id = null;
                    $stmtDefault = $mysqli->prepare('SELECT id FROM categories WHERE user_id = ? AND is_default = 1 LIMIT 1');
                    $stmtDefault->bind_param('i', $user_id);
                    $stmtDefault->execute();
                    $stmtDefault->bind_result($defaultIdValue);
                    if ($stmtDefault->fetch()) {
                        $default_id = $defaultIdValue;
                    }
                    $stmtDefault->close();

                    if ($default_id !== null) {
                        $stmtMove = $mysqli->prepare('UPDATE tasks SET category_id = ? WHERE user_id = ? AND category_id = ?');
                        $stmtMove->bind_param('iii', $default_id, $user_id, $category_id);
                        $stmtMove->execute();
                        $stmtMove->close();
                    } else {
                        $stmtMove = $mysqli->prepare('UPDATE tasks SET category_id = NULL WHERE user_id = ? AND category_id = ?');
                        $stmtMove->bind_param('ii', $user_id, $category_id);
                        $stmtMove->execute();
                        $stmtMove->close();
                    }
                } else {
                    // delete tasks in this category
                    $stmt2 = $mysqli->prepare('DELETE FROM tasks WHERE user_id = ? AND category_id = ?');
                    $stmt2->bind_param('ii', $user_id, $category_id);
                    $stmt2->execute();
                    $stmt2->close();
                }
                // delete category
                $stmt3 = $mysqli->prepare('DELETE FROM categories WHERE id = ? AND user_id = ?');
                $stmt3->bind_param('ii', $category_id, $user_id);
                $stmt3->execute();
                $stmt3->close();
            } else {
                $stmt->close();
            }
        }
        header('Location: todo.php');
        exit;
    }
}

// Load categories
$categories = [];
$stmt = $mysqli->prepare('SELECT id, name, is_default, color FROM categories WHERE user_id = ? ORDER BY is_default DESC, name ASC');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$stmt->bind_result($cid, $cname, $is_default, $ccolor);
while ($stmt->fetch()) {
    $categories[] = [
        'id' => $cid,
        'name' => format_category_label($cname),
        'is_default' => $is_default,
        'color' => normalize_hex_color($ccolor ?: '#5C6CFF', '#5C6CFF'),
    ];
}
$stmt->close();

// Load tasks
$tasks = [];
$query = 'SELECT t.id, t.title, t.deadline, t.is_done, t.notes, c.name, c.id, c.color
          FROM tasks t
          LEFT JOIN categories c ON t.category_id = c.id
          WHERE t.user_id = ?
          ORDER BY t.deadline IS NULL, t.deadline ASC, t.id DESC';
$stmt = $mysqli->prepare($query);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$stmt->bind_result($tid, $ttitle, $tdeadline, $tdone, $tnotes, $cname, $cid, $ccolor);
while ($stmt->fetch()) {
    $deadline_obj = $tdeadline ? new DateTime($tdeadline) : null;
    if ($deadline_obj) {
        $deadline_obj->setTime(0, 0, 0);
    }
    $is_done = (bool)$tdone;
    $is_overdue = false;
    $is_due_today = false;
    $deadline_formatted = null;
    $deadline_relative = null;

    $stats['total']++;
    if ($is_done) {
        $stats['completed']++;
    } else {
        $stats['active']++;
    }

    if ($deadline_obj) {
        $deadline_formatted = $deadline_obj->format('M j, Y');
        $is_due_today = $deadline_obj->format('Y-m-d') === $today_key;
        $today_timestamp = $today->getTimestamp();
        $deadline_timestamp = $deadline_obj->getTimestamp();
        $days_diff = (int)(($deadline_timestamp - $today_timestamp) / 86400);

        if ($days_diff < 0) {
            $days = abs($days_diff);
            if (!$is_done) {
                $is_overdue = true;
                $stats['overdue']++;
            }
            $deadline_relative = 'Due ' . $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
        } else {
            if (!$is_done) {
                $stats['upcoming']++;
                if ($next_deadline === null || $deadline_obj < $next_deadline) {
                    $next_deadline = clone $deadline_obj;
                }
            }
            if ($days_diff === 0) {
                $deadline_relative = 'Due today';
            } elseif ($days_diff === 1) {
                $deadline_relative = 'Due tomorrow';
            } else {
                $deadline_relative = 'Due in ' . $days_diff . ' days';
            }
        }
    }

    $status_slug = $is_done ? 'completed' : ($is_overdue ? 'overdue' : ($is_due_today ? 'today' : 'active'));
    $deadline_key = $deadline_obj ? $deadline_obj->format('Y-m-d') : null;

    if ($cid) {
        $category_counts[$cid] = ($category_counts[$cid] ?? 0) + 1;
    }

    if ($deadline_key) {
        $calendar_events[$deadline_key][] = [
            'title' => $ttitle,
            'task_id' => $tid,
            'category' => format_category_label($cname),
            'status' => $status_slug,
            'deadline' => $deadline_formatted ?? $deadline_key,
            'deadline_relative' => $deadline_relative ?? 'Flexible',
            'notes' => $tnotes ?? '',
        ];
    }

    $tasks[] = [
        'id' => $tid,
        'title' => $ttitle,
        'deadline' => $tdeadline,
        'deadline_formatted' => $deadline_formatted,
        'deadline_relative' => $deadline_relative,
        'is_due_today' => $is_due_today,
        'is_overdue' => $is_overdue,
        'is_done' => $tdone,
        'category_name' => format_category_label($cname),
        'category_id' => $cid,
        'category_color' => normalize_hex_color($ccolor ?: '#5C6CFF', '#5C6CFF'),
        'deadline_key' => $deadline_key,
        'status_slug' => $status_slug,
        'notes' => $tnotes ?? '',
    ];
}
$stmt->close();

$today_tasks = array_values(array_filter($tasks, function ($task) use ($today_key) {
    return $task['deadline_key'] === $today_key;
}));
$today_total_count = count($today_tasks);
$today_active_count = count(array_filter($today_tasks, function ($task) {
    return !$task['is_done'];
}));
$today_completed_count = $today_total_count - $today_active_count;

$stats['completion_percent'] = $stats['total'] > 0 ? round(($stats['completed'] / $stats['total']) * 100) : 0;
$stats['health_text'] = $stats['overdue'] > 0
    ? 'Needs attention - tackle overdue work first.'
    : ($stats['total'] === 0 ? 'Add tasks to start tracking progress.' : 'Great pace - keep shipping!');
$stats['next_deadline'] = $next_deadline ? $next_deadline->format('M j, Y') : null;
if ($next_deadline) {
    $diff = $today->diff($next_deadline);
    if ($diff->days === 0) {
        $stats['next_deadline_relative'] = 'Today';
    } elseif ($diff->days === 1) {
        $stats['next_deadline_relative'] = 'Tomorrow';
    } else {
        $stats['next_deadline_relative'] = 'In ' . $diff->days . ' days';
    }
} else {
    $stats['next_deadline_relative'] = null;
}

$calendar_events_json = htmlspecialchars(json_encode($calendar_events), ENT_QUOTES, 'UTF-8');
$timeline_all = $tasks;
usort($timeline_all, function ($a, $b) {
    $aKey = $a['deadline_key'] ?? '9999-12-31';
    $bKey = $b['deadline_key'] ?? '9999-12-31';
    if ($aKey === $bKey) {
        if ($a['id'] === $b['id']) {
            return 0;
        }
        return ($a['id'] < $b['id']) ? -1 : 1;
    }
    return strcmp($aKey, $bKey);
});
$timeline_upcoming = array_values(array_filter($timeline_all, function ($task) {
    return !$task['is_done'] && !$task['is_overdue'];
}));
$timeline_overdue = array_values(array_filter($timeline_all, function ($task) {
    return $task['is_overdue'];
}));
$timeline_category_map = [];
foreach ($timeline_all as $task) {
    $catName = format_category_label($task['category_name'] ?? null);
    $timeline_category_map[$catName][] = $task;
}
ksort($timeline_category_map, SORT_NATURAL | SORT_FLAG_CASE);
$timeline_categories = [];
foreach ($timeline_category_map as $name => $taskList) {
    $timeline_categories[] = [
        'category' => format_category_label($name),
        'tasks' => format_timeline_tasks($taskList),
    ];
}

$timeline_payload = [
    'total' => format_timeline_tasks($timeline_all),
    'upcoming' => format_timeline_tasks($timeline_upcoming),
    'overdue' => format_timeline_tasks($timeline_overdue),
    'categories' => $timeline_categories,
];
$stat_overlay_json = htmlspecialchars(json_encode($timeline_payload), ENT_QUOTES, 'UTF-8');
$plan_tasks_data = array_map(function ($task) {
    return [
        'id' => $task['id'],
        'title' => $task['title'],
        'category' => format_category_label($task['category_name'] ?? null),
        'category_color' => $task['category_color'] ?? '#5C6CFF',
        'deadline' => $task['deadline_formatted'] ?? 'No deadline',
        'deadline_relative' => $task['deadline_relative'] ?? '',
        'is_done' => (bool)$task['is_done'],
        'deadline_key' => $task['deadline_key'] ?? null,
        'notes' => $task['notes'] ?? '',
    ];
}, $tasks);
$plan_tasks_json = htmlspecialchars(json_encode($plan_tasks_data), ENT_QUOTES, 'UTF-8');

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TODO Tasks Scheduler</title>
    <link rel="stylesheet" href="assets/style.css">
    <script src="assets/script.js" defer></script>
</head>

<body class="app-body" data-user-id="<?php echo htmlspecialchars($user_id, ENT_QUOTES, 'UTF-8'); ?>"
    data-csrf-token="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
    <header class="app-topbar">
        <div class="brand">
            <h1>TODO Tasks Scheduler</h1>
        </div>
        <div class="topbar-actions">
            <button type="button" class="gear-button" data-gear-button aria-label="Settings">
                <span class="gear-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3"></circle>
                        <path
                            d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9c.17.52.17 1.08 0 1.6a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z">
                        </path>
                    </svg>
                </span>
            </button>
            <div class="theme-switch" data-theme-switch>
                <div class="theme-switch-track">
                    <span class="theme-switch-indicator" data-theme-indicator></span>
                    <button type="button" data-theme-option="light" aria-pressed="false">Light</button>
                    <button type="button" data-theme-option="dark" aria-pressed="false">Dark</button>
                </div>
            </div>
            <form action="logout.php" method="post" class="topbar-logout">
                <input type="hidden" name="csrf_token"
                    value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="btn-logout">Logout</button>
            </form>
        </div>
    </header>
    <main class="app-shell">
        <?php
            $today_heading = $today->format('M j') . ' Deadlines';
            $todo_heading = $today->format('M j') . ' To-do List';
        ?>
        <header class="dashboard-hero">
            <div class="hero-left">
                <h1>Focus Dashboard</h1>
                <p class="meta-time"><?php echo date('l, F j'); ?></p>
                <div class="progress-stack">
                    <div class="progress-label">
                        <span>Completion</span>
                        <strong><?php echo $stats['completion_percent']; ?>%</strong>
                    </div>
                    <div class="progress-track progress-half">
                        <div class="progress-fill" style="width: <?php echo $stats['completion_percent']; ?>%;"></div>
                    </div>
                    <?php if ($stats['next_deadline']): ?>
                    <p class="progress-note">
                        Next deadline <strong><?php echo htmlspecialchars($stats['next_deadline']); ?></strong>
                        <span>(<?php echo htmlspecialchars($stats['next_deadline_relative']); ?>)</span>
                    </p>
                    <?php else: ?>
                    <p class="progress-note">No planned deadlines yet.</p>
                    <?php endif; ?>
                </div>

                <!-- Today's Tasks Section -->
                <section class="today-tasks-section">
                    <div class="today-card <?php echo empty($today_tasks) ? 'is-empty' : ''; ?>" data-today-card>
                        <div class="schedule-switch" data-schedule-switch>
                            <div class="schedule-switch-track">
                                <span class="schedule-switch-indicator" data-schedule-indicator></span>
                                <button type="button" class="schedule-option" data-schedule-option="mission"
                                    aria-pressed="true">Show Today's Mission</button>
                                <button type="button" class="schedule-option" data-schedule-option="todo"
                                    aria-pressed="false">Show Todo List</button>
                            </div>
                        </div>
                        <header class="today-card-head">
                            <div>
                                <p class="eyebrow" data-today-card-subtitle data-default-text="Today's Mission"
                                    data-alt-text="Todo Schedule">Today's Mission</p>
                                <h3 data-today-card-title
                                    data-default-text="<?php echo htmlspecialchars($today_heading); ?>"
                                    data-alt-text="<?php echo htmlspecialchars($todo_heading); ?>">
                                    <?php echo htmlspecialchars($today_heading); ?></h3>
                            </div>
                            <ul class="today-card-stats">
                                <li>
                                    <span>Total</span>
                                    <strong><?php echo $today_total_count; ?></strong>
                                </li>
                                <li>
                                    <span>Active</span>
                                    <strong><?php echo $today_active_count; ?></strong>
                                </li>
                                <li>
                                    <span>Done</span>
                                    <strong><?php echo $today_completed_count; ?></strong>
                                </li>
                            </ul>
                        </header>
                        <div class="today-card-body" data-mission-body>
                            <?php if (empty($today_tasks)): ?>
                            <div class="today-empty-state">
                                <p class="today-empty-title">Clear skies</p>
                                <p class="hint">Add a deadline above or enjoy a head start on tomorrow.</p>
                            </div>
                            <?php else: ?>
                            <div class="today-tasks-list">
                                <?php foreach ($today_tasks as $task): ?>
                                <?php
                                    $task_category = format_category_label($task['category_name'] ?? null);
                                    $deadline_text = $task['deadline_relative'] ?? 'Due today';
                                    $category_color = $task['category_color'] ?? '#5C6CFF';
                                    $category_color_css = htmlspecialchars($category_color, ENT_QUOTES, 'UTF-8');
                                ?>
                                <article
                                    class="today-task-item <?php echo $task['is_done'] ? 'is-complete' : 'is-active'; ?>"
                                    style="--today-category-color: <?php echo $category_color_css; ?>;"
                                    data-task-note-trigger data-task-id="<?php echo $task['id']; ?>"
                                    data-task-title="<?php echo escape_data_value($task['title']); ?>"
                                    data-task-notes="<?php echo escape_data_value($task['notes'] ?? ''); ?>"
                                    data-task-category="<?php echo escape_data_value($task_category); ?>"
                                    data-task-deadline="<?php echo escape_data_value($task['deadline_formatted'] ?? 'No deadline'); ?>"
                                    data-task-deadline-relative="<?php echo escape_data_value($deadline_text); ?>">
                                    <form method="post" class="today-toggle" data-preserve-scroll>
                                        <input type="hidden" name="action" value="toggle_done">
                                        <input type="hidden" name="csrf_token"
                                            value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                        <label class="today-checkbox">
                                            <input type="checkbox" name="is_done"
                                                <?php echo $task['is_done'] ? 'checked' : ''; ?>
                                                onchange="this.form.submit()" data-preserve-scroll-trigger>
                                            <span></span>
                                        </label>
                                    </form>
                                    <div class="today-task-content">
                                        <p class="today-task-title"><?php echo htmlspecialchars($task['title']); ?></p>
                                        <div class="today-task-meta">
                                            <span class="chip chip-category"
                                                style="--cat-color: <?php echo $category_color_css; ?>"><?php echo htmlspecialchars($task_category); ?></span>
                                            <?php if ($task['is_done']): ?>
                                            <span class="chip chip-success">Completed</span>
                                            <?php else: ?>
                                            <span class="chip chip-warning">In progress</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <p class="today-task-deadline"><?php echo htmlspecialchars($deadline_text); ?></p>
                                </article>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="todo-schedule-placeholder" data-todo-body hidden>
                            <p class="placeholder-title">Todo schedule</p>
                            <button type="button" class="plan-action" data-plan-trigger
                                data-plan-tasks="<?php echo $plan_tasks_json; ?>"
                                data-plan-storage-key="plan-schedule-<?php echo $user_id; ?>">Plan your tasks to
                                schedule</button>
                            <div class="todo-schedule-results" data-todo-results hidden>
                                <p class="placeholder-title">Planned order</p>
                                <ol class="todo-schedule-list" data-todo-result-list></ol>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
            <div class="hero-calendar card calendar-card" data-calendar-root
                data-events="<?php echo $calendar_events_json; ?>">
                <div class="calendar-heading">
                    <div>
                        <h3>Deadline calendar</h3>
                        <p class="hint">
                            Tap a day to check its details and notes
                        </p>
                    </div>
                    <div class="calendar-nav">
                        <button type="button" class="btn-ghost small" data-calendar-prev
                            aria-label="Previous month">&lsaquo;</button>
                        <div class="calendar-label" data-calendar-label></div>
                        <button type="button" class="btn-ghost small" data-calendar-next
                            aria-label="Next month">&rsaquo;</button>
                    </div>
                </div>
                <div class="calendar-grid" data-calendar-grid></div>
                <div class="calendar-details">
                    <h4 data-calendar-selected>Choose a day</h4>
                    <ul class="calendar-list" data-calendar-list></ul>
                    <p class="hint" data-calendar-empty>No scheduled tasks for this selection.</p>
                </div>
            </div>
        </header>

        <section class="insights-grid">
            <article class="stat-card accent" data-stat-trigger="total" role="button" tabindex="0">
                <p class="stat-label">Total tasks</p>
                <p class="stat-value"><?php echo $stats['total']; ?></p>
                <p class="stat-meta"><?php echo $stats['active']; ?> active &middot; <?php echo $stats['completed']; ?>
                    done</p>
            </article>
            <article class="stat-card warning" data-stat-trigger="upcoming" role="button" tabindex="0">
                <p class="stat-label">Upcoming</p>
                <p class="stat-value"><?php echo $stats['upcoming']; ?></p>
                <p class="stat-meta">due soon and not completed</p>
            </article>
            <article class="stat-card danger" data-stat-trigger="overdue" role="button" tabindex="0">
                <p class="stat-label">Overdue</p>
                <p class="stat-value"><?php echo $stats['overdue']; ?></p>
                <p class="stat-meta">needs prioritization</p>
            </article>
            <article class="stat-card purple" data-stat-trigger="categories" role="button" tabindex="0">
                <p class="stat-label">Categories</p>
                <p class="stat-value"><?php echo count($categories); ?></p>
                <p class="stat-meta">curated focus areas</p>
            </article>
        </section>

        <div class="section-divider"></div>

        <section class="workspace">
            <div class="dual-grid">
                <article class="card collapsible" data-collapsible="tasks">
                    <header class="collapsible-head">
                        <div>
                            <h2>Tasks</h2>
                        </div>
                    </header>
                    <div class="collapsible-body" data-collapsible-body>
                        <div class="subgrid">
                            <section class="subcard">
                                <h3>Add Task</h3>
                                <form method="post" class="stack-form">
                                    <input type="hidden" name="action" value="add_task">
                                    <input type="hidden" name="csrf_token"
                                        value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                    <label class="input-group">
                                        <span>Task name</span>
                                        <input type="text" name="title" placeholder="e.g. Submit Scheduler report" required>
                                    </label>
                                    <label class="input-group">
                                        <span>Deadline</span>
                                        <input type="date" name="deadline">
                                    </label>
                                    <label class="input-group">
                                        <span>Category</span>
                                        <select name="category_id" data-placeholder-value="0">
                                            <option value="0">(default: None)</option>
                                            <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo $cat['id']; ?>">
                                                <?php echo htmlspecialchars($cat['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <button type="submit" class="btn-primary">Add task</button>
                                </form>
                            </section>
                            <section class="subcard">
                                <h3>Search Task</h3>
                                <div class="filter-stack">
                                    <label class="input-group search-group">
                                        <span>Search</span>
                                        <input type="text" placeholder="Filter by task or category" data-task-filter>
                                    </label>
                                    <label class="input-group filter-group">
                                        <span>Status</span>
                                        <select data-status-filter>
                                            <option value="all">All statuses</option>
                                            <option value="active">Active</option>
                                            <option value="overdue">Overdue</option>
                                            <option value="completed">Completed</option>
                                        </select>
                                    </label>
                                    <label class="input-group filter-group">
                                        <span>Category</span>
                                        <select data-category-filter>
                                            <option value="all">All categories</option>
                                            <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo $cat['id']; ?>">
                                                <?php echo htmlspecialchars($cat['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                </div>
                            </section>
                            <section class="task-list-panel">
                                <div class="panel-head">
                                    <h3>Tasks List</h3>
                                    <span class="chip chip-info"><?php echo count($tasks); ?> Tasks</span>
                                </div>
                                <div class="task-card-wrapper">
                                    <?php if (empty($tasks)): ?>
                                    <div class="empty-state">
                                        <h3>No tasks yet</h3>
                                        <p>Start by adding your first task above. Every detail will show up here with
                                            live status.</p>
                                    </div>
                                    <?php else: ?>
                                    <div class="task-collection" data-task-collection>
                                        <?php foreach ($tasks as $task): ?>
                                        <?php
                                                    $data_title = htmlspecialchars(strtolower($task['title']), ENT_QUOTES);
                                                    $category_name = format_category_label($task['category_name'] ?? null);
                                                    $category_color = $task['category_color'] ?? '#5C6CFF';
                                                    $is_none = $category_name === 'None';
                                                    $data_category_name = htmlspecialchars(strtolower($category_name), ENT_QUOTES);
                                                    $category_id_attr = $task['category_id'] ?: 0;
                                                    $status_tag = htmlspecialchars($task['status_slug'], ENT_QUOTES);
                                                    $deadline_key_attr = htmlspecialchars($task['deadline_key'] ?? '', ENT_QUOTES);
                                                    $edit_form_id = 'task-edit-' . $task['id'];
                                                ?>
                                        <article
                                            class="task-card <?php echo $task['is_done'] ? 'is-complete' : 'is-active'; ?> <?php echo $task['is_overdue'] ? 'is-overdue' : ''; ?>"
                                            data-task-row data-task-note-trigger data-title="<?php echo $data_title; ?>"
                                            data-category-name="<?php echo $data_category_name; ?>"
                                            data-category-id="<?php echo $category_id_attr; ?>"
                                            data-status="<?php echo $status_tag; ?>"
                                            data-deadline="<?php echo $deadline_key_attr; ?>"
                                            data-task-id="<?php echo $task['id']; ?>"
                                            data-task-title="<?php echo escape_data_value($task['title']); ?>"
                                            data-task-notes="<?php echo escape_data_value($task['notes'] ?? ''); ?>"
                                            data-task-category="<?php echo escape_data_value($category_name); ?>"
                                            data-task-deadline="<?php echo escape_data_value($task['deadline_formatted'] ?? 'No deadline'); ?>"
                                            data-task-deadline-relative="<?php echo escape_data_value($task['deadline_relative'] ?? 'Flexible'); ?>">
                                            <div class="task-main">
                                                <form method="post" class="toggle-form"
                                                    aria-label="Toggle task completion" data-preserve-scroll>
                                                    <input type="hidden" name="action" value="toggle_done">
                                                    <input type="hidden" name="csrf_token"
                                                        value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="task_id"
                                                        value="<?php echo $task['id']; ?>">
                                                    <label class="checkbox-pill">
                                                        <input type="checkbox" name="is_done"
                                                            onchange="this.form.submit()"
                                                            <?php echo $task['is_done'] ? 'checked' : ''; ?>
                                                            data-preserve-scroll-trigger>
                                                        <span></span>
                                                    </label>
                                                </form>
                                                <div class="task-body">
                                                    <form method="post" class="inline-edit"
                                                        id="<?php echo $edit_form_id; ?>" data-preserve-scroll>
                                                        <input type="hidden" name="action" value="update_task_name">
                                                        <input type="hidden" name="csrf_token"
                                                            value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="task_id"
                                                            value="<?php echo $task['id']; ?>">
                                                        <input type="text" name="title"
                                                            value="<?php echo htmlspecialchars($task['title']); ?>">
                                                    </form>
                                                    <form method="post" class="inline-form task-category-select"
                                                        data-preserve-scroll>
                                                        <input type="hidden" name="action" value="update_task_category">
                                                        <input type="hidden" name="csrf_token"
                                                            value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="task_id"
                                                            value="<?php echo $task['id']; ?>">
                                                        <label class="sr-only"
                                                            for="task-cat-<?php echo $task['id']; ?>">Category</label>
                                                        <select id="task-cat-<?php echo $task['id']; ?>"
                                                            name="category_id" onchange="this.form.submit()">
                                                            <?php foreach ($categories as $cat): ?>
                                                            <option value="<?php echo $cat['id']; ?>"
                                                                <?php echo ($cat['id'] == $task['category_id']) ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($cat['name']); ?>
                                                            </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </form>
                                                    <div class="chip-row">
                                                        <?php if ($task['deadline_formatted']): ?>
                                                        <span
                                                            class="chip <?php echo $task['is_overdue'] ? 'chip-danger' : 'chip-info'; ?>">
                                                            <?php echo htmlspecialchars($task['deadline_formatted']); ?>
                                                        </span>
                                                        <?php endif; ?>
                                                        <?php if ($task['deadline_relative']): ?>
                                                        <span class="chip chip-ghost">
                                                            <?php echo htmlspecialchars($task['deadline_relative']); ?>
                                                        </span>
                                                        <?php endif; ?>
                                                        <?php if ($task['is_done']): ?>
                                                        <span class="chip chip-success">Completed</span>
                                                        <?php elseif ($task['is_overdue']): ?>
                                                        <span class="chip chip-danger">Overdue</span>
                                                        <?php elseif ($task['is_due_today']): ?>
                                                        <span class="chip chip-warning">Due today</span>
                                                        <?php else: ?>
                                                        <span class="chip chip-neutral">In progress</span>
                                                        <?php endif; ?>
                                                        <span
                                                            class="chip chip-category <?php echo $is_none ? 'cat-none' : ''; ?>"
                                                            style="--cat-color: <?php echo htmlspecialchars($category_color, ENT_QUOTES, 'UTF-8'); ?>;">
                                                            <?php echo htmlspecialchars($category_name); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <div class="task-actions">
                                                    <button type="submit" form="<?php echo $edit_form_id; ?>"
                                                        class="btn-ghost small" data-preserve-scroll>Save</button>
                                                    <form method="post"
                                                        data-confirm="Delete this task? This cannot be undone."
                                                        data-preserve-scroll>
                                                        <input type="hidden" name="action" value="delete_task">
                                                        <input type="hidden" name="csrf_token"
                                                            value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="task_id"
                                                            value="<?php echo $task['id']; ?>">
                                                        <button type="submit"
                                                            class="btn-ghost danger small">Delete</button>
                                                    </form>
                                                </div>
                                            </div>
                                        </article>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="empty-state" data-empty-filter hidden>
                                        <h3>No tasks match this search</h3>
                                        <p>Try adjusting your keywords or clearing the field to see everything again.
                                        </p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </section>
                        </div>
                    </div>
                </article>

                <article class="card collapsible" data-collapsible="categories">
                    <header class="collapsible-head">
                        <div>
                            <h2>Categories</h2>
                        </div>
                    </header>
                    <div class="collapsible-body" data-collapsible-body>
                        <div class="subgrid">
                            <section class="subcard add-category-card">
                                <div class="category-color-wrapper add-category-color">
                                    <div class="category-color-dot" id="new-category-dot" data-category-dot="new"
                                        data-current-color="#5C6CFF" style="background-color: #5C6CFF;"
                                        onclick="togglePalette('new', event)"></div>
                                    <div class="color-palette-popup" id="palette-new" hidden>
                                        <p class="palette-title">Pick a tone</p>
                                        <div class="palette-preview-row">
                                            <span class="palette-preview-dot" data-wheel-preview></span>
                                            <div class="palette-preview-meta">
                                                <span>Selected color</span>
                                                <strong data-wheel-value>#5C6CFF</strong>
                                            </div>
                                        </div>
                                        <div class="color-wheel" data-color-wheel data-target="new"
                                            data-current-color="#5C6CFF">
                                            <div class="color-wheel-ring" data-wheel-ring>
                                                <div class="color-wheel-handle" data-wheel-handle></div>
                                            </div>
                                        </div>
                                        <div class="palette-actions">
                                            <button type="button" class="btn-primary full" data-wheel-apply
                                                data-target="new">Use this color</button>
                                        </div>
                                    </div>
                                </div>
                                <h3>Add Category</h3>
                                <form method="post" class="stack-form" id="add-category-form">
                                    <input type="hidden" name="action" value="add_category">
                                    <input type="hidden" name="csrf_token"
                                        value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="color" id="new-category-color" value="#5C6CFF">
                                    <label class="input-group">
                                        <span>Category name</span>
                                        <input type="text" name="name" placeholder="e.g. Research" required>
                                    </label>
                                    <button type="submit" class="btn-primary">Add category</button>
                                </form>
                            </section>
                            <section class="subcard">
                                <h3>Search Category</h3>
                                <label class="input-group">
                                    <span>Keyword</span>
                                    <input type="text" placeholder="Search categories" data-category-filter-input>
                                </label>
                                <p class="hint"> Type to filter the list below.</p>
                            </section>
                            <section class="subcard stretch">
                                <h3>Categories Distribution</h3>
                                <?php
                                    $total_task_count = array_sum($category_counts);
                                    $distribution_data = [];
                                    foreach ($categories as $cat) {
                                        $count = $category_counts[$cat['id']] ?? 0;
                                        $ratio = $total_task_count ? ($count / $total_task_count) : 0;
                                        $distribution_data[] = [
                                            'category' => $cat,
                                            'count' => $count,
                                            'percent' => round($ratio * 100),
                                            'ratio' => $ratio,
                                            'is_default' => (bool) $cat['is_default'],
                                        ];
                                    }
                                    usort($distribution_data, function ($a, $b) {
                                        if ($a['ratio'] === $b['ratio']) {
                                            return strcasecmp($a['category']['name'], $b['category']['name']);
                                        }
                                        return $b['ratio'] <=> $a['ratio'];
                                    });
                                ?>
                                <div class="distribution-card" aria-live="polite">
                                    <?php foreach ($distribution_data as $entry): ?>
                                    <?php
                                            $cat = $entry['category'];
                                            $count = $entry['count'];
                                            $percent = $entry['percent'];
                                            $color = $cat['color'];
                                            $is_default_category = !empty($entry['is_default']);
                                            $distribution_classes = 'distribution-item' . ($is_default_category ? ' is-none-category' : '');
                                            $distribution_style = $is_default_category ? '' : ' style="--distribution-color: ' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . ';"';
                                        ?>
                                    <article class="<?php echo $distribution_classes; ?>"
                                        <?php echo $distribution_style; ?>>
                                        <div class="distribution-strip">
                                            <div class="distribution-fill" style="width: <?php echo $percent; ?>%;">
                                            </div>
                                            <div class="distribution-overlay">
                                                <strong
                                                    class="distribution-name"><?php echo htmlspecialchars(format_category_label($cat['name'])); ?></strong>
                                                <span class="distribution-meta-pill">
                                                    <?php echo $count; ?> <?php echo $count === 1 ? 'task' : 'tasks'; ?>
                                                    <span>&middot;</span>
                                                    <?php echo $percent; ?>%
                                                </span>
                                            </div>
                                        </div>
                                    </article>
                                    <?php endforeach; ?>
                                    <?php if (empty($distribution_data)): ?>
                                    <p class="hint">Add categories to see their breakdown.</p>
                                    <?php endif; ?>
                                </div>
                            </section>
                            <section class="category-list-panel">
                                <div class="panel-head">
                                    <h3>Categories List</h3>
                                    <span class="chip chip-category"><?php echo count($categories); ?> Categories</span>
                                </div>
                                <?php if (empty($categories)): ?>
                                <div class="empty-state">
                                    <h3>No categories yet</h3>
                                    <p>Create your first focus area above—every task can be grouped instantly.</p>
                                </div>
                                <?php else: ?>
                                <div class="category-card-wrapper">
                                    <div class="category-collection" data-category-collection>
                                        <?php foreach ($categories as $cat): ?>
                                        <?php
                                            $cat_count = $category_counts[$cat['id']] ?? 0;
                                            $cat_name_low = htmlspecialchars(strtolower($cat['name']), ENT_QUOTES);
                                            $rename_form_id = 'cat-rename-' . $cat['id'];
                                            ?>
                                        <article
                                            class="category-card <?php echo $cat['is_default'] ? 'is-default' : ''; ?>"
                                            data-category-card data-category-id="<?php echo $cat['id']; ?>"
                                            data-category-name="<?php echo $cat_name_low; ?>">
                                            <div class="category-row">
                                                <div class="category-body">
                                                    <header>
                                                        <div>
                                                            <h3><?php echo htmlspecialchars(format_category_label($cat['name'])); ?>
                                                            </h3>
                                                            <p><?php echo $cat_count; ?>
                                                                task<?php echo $cat_count === 1 ? '' : 's'; ?></p>
                                                        </div>
                                                        <?php if ($cat['is_default']): ?>
                                                        <span class="chip chip-neutral">Default</span>
                                                        <?php endif; ?>
                                                    </header>
                                                    <?php if ($cat['is_default']): ?>
                                                    <p class="hint">Default categories keep your unassigned work safe.
                                                    </p>
                                                    <?php else: ?>
                                                    <form method="post" class="category-rename"
                                                        id="<?php echo $rename_form_id; ?>" data-preserve-scroll>
                                                        <input type="hidden" name="action" value="update_category_name">
                                                        <input type="hidden" name="csrf_token"
                                                            value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="category_id"
                                                            value="<?php echo $cat['id']; ?>">
                                                        <input type="text" name="name"
                                                            value="<?php echo htmlspecialchars($cat['name']); ?>">
                                                    </form>
                                                    <?php endif; ?>
                                                </div>
                                                <div
                                                    class="category-actions <?php echo $cat['is_default'] ? 'is-default' : ''; ?>">
                                                    <?php if (!$cat['is_default']): ?>
                                                    <div class="category-color-wrapper">
                                                        <div class="category-color-dot"
                                                            data-category-dot="<?php echo $cat['id']; ?>"
                                                            data-current-color="<?php echo htmlspecialchars($cat['color'], ENT_QUOTES, 'UTF-8'); ?>"
                                                            style="background-color: <?php echo htmlspecialchars($cat['color'], ENT_QUOTES, 'UTF-8'); ?>;"
                                                            onclick="togglePalette(<?php echo $cat['id']; ?>, event)">
                                                        </div>
                                                        <div class="color-palette-popup"
                                                            id="palette-<?php echo $cat['id']; ?>" hidden>
                                                            <p class="palette-title">Refresh color</p>
                                                            <div class="palette-preview-row">
                                                                <span class="palette-preview-dot"
                                                                    data-wheel-preview></span>
                                                                <div class="palette-preview-meta">
                                                                    <span>Selected color</span>
                                                                    <strong
                                                                        data-wheel-value><?php echo htmlspecialchars($cat['color'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                                </div>
                                                            </div>
                                                            <div class="color-wheel" data-color-wheel
                                                                data-category-id="<?php echo $cat['id']; ?>"
                                                                data-current-color="<?php echo htmlspecialchars($cat['color'], ENT_QUOTES, 'UTF-8'); ?>">
                                                                <div class="color-wheel-ring" data-wheel-ring>
                                                                    <div class="color-wheel-handle" data-wheel-handle>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="palette-actions">
                                                                <button type="button" class="btn-primary full"
                                                                    data-wheel-apply
                                                                    data-category-id="<?php echo $cat['id']; ?>">Apply
                                                                    color</button>
                                                            </div>
                                                        </div>
                                                        <form id="color-form-<?php echo $cat['id']; ?>" method="post"
                                                            style="display:none;">
                                                            <input type="hidden" name="action"
                                                                value="update_category_color">
                                                            <input type="hidden" name="csrf_token"
                                                                value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                                            <input type="hidden" name="category_id"
                                                                value="<?php echo $cat['id']; ?>">
                                                            <input type="hidden" name="color"
                                                                id="input-color-<?php echo $cat['id']; ?>">
                                                        </form>
                                                    </div>
                                                    <button type="submit" form="<?php echo $rename_form_id; ?>"
                                                        class="btn-ghost small" data-preserve-scroll>Save</button>
                                                    <form method="post" data-confirm="Delete this category?"
                                                        data-confirm-primary="Delete category & tasks"
                                                        data-confirm-primary-target="delete_mode"
                                                        data-confirm-primary-value="delete_all"
                                                        data-confirm-alt="Save tasks to None & delete"
                                                        data-confirm-alt-target="delete_mode"
                                                        data-confirm-alt-value="detach" data-preserve-scroll>
                                                        <input type="hidden" name="action" value="delete_category">
                                                        <input type="hidden" name="csrf_token"
                                                            value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="category_id"
                                                            value="<?php echo $cat['id']; ?>">
                                                        <input type="hidden" name="delete_mode" value="delete_all">
                                                        <button type="submit"
                                                            class="btn-ghost danger small">Delete</button>
                                                    </form>
                                                    <?php else: ?>
                                                    <span class="chip chip-neutral">Locked</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </article>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="hint" data-category-filter-empty hidden>No categories match this search.
                                    </p>
                                </div>
                                <?php endif; ?>
                            </section>
                        </div>
                    </div>
                </article>
            </div>
        </section>
    </main>
    <div class="task-note-overlay" data-task-note-overlay hidden>
        <div class="task-note-panel">
            <button type="button" class="task-note-close" data-task-note-close aria-label="Close task notes">✕</button>
            <div class="task-note-header">
                <p class="eyebrow">Task notes</p>
                <h3 data-task-note-title>Untitled task</h3>
                <p class="hint" data-task-note-meta>Keep important context close.</p>
            </div>
            <form method="post" class="task-note-form" data-task-note-form>
                <input type="hidden" name="action" value="update_task_notes">
                <input type="hidden" name="csrf_token"
                    value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="task_id" value="" data-task-note-id>
                <input type="hidden" name="ajax" value="1">
                <label class="input-group">
                    <span>Notes</span>
                    <textarea name="notes" rows="8" placeholder="Add reminders, checklists, or decisions here..."
                        data-task-note-textarea></textarea>
                </label>
                <div class="task-note-actions">
                    <button type="button" class="btn-ghost" data-task-note-cancel>Cancel</button>
                    <button type="submit" class="btn-primary">Save notes</button>
                </div>
            </form>
        </div>
    </div>
    <div class="note-toast" data-note-toast aria-live="polite"></div>
    <div class="stat-overlay" data-stat-overlay data-datasets="<?php echo $stat_overlay_json; ?>" hidden>
        <div class="overlay-panel">
            <div class="overlay-header">
                <div class="overlay-heading">
                    <h3 data-overlay-title>Timeline</h3>
                    <p data-overlay-subtitle class="hint">Sorted from past to future.</p>
                </div>
                <button type="button" class="overlay-close" data-overlay-close aria-label="Close insight">✕</button>
            </div>
            <div class="overlay-list" data-overlay-list></div>
            <p class="hint overlay-empty" data-overlay-empty>No tasks to show yet.</p>
        </div>
    </div>
    <div class="confirm-overlay" data-confirm-overlay hidden>
        <div class="confirm-panel">
            <h3>Confirm action</h3>
            <p data-confirm-message>Are you sure?</p>
            <div class="confirm-actions">
                <button type="button" class="btn-ghost" data-confirm-cancel>Cancel</button>
                <button type="button" class="btn-ghost" data-confirm-alt hidden>Save tasks to None</button>
                <button type="button" class="btn-primary" data-confirm-approve>Confirm</button>
            </div>
        </div>
    </div>
    <div class="settings-overlay" data-settings-overlay hidden>
        <div class="settings-panel">
            <header class="settings-header">
                <div>
                    <p class="eyebrow">Preferences</p>
                    <h3>Settings</h3>
                    <p class="hint">Configure quick automations and behaviors.</p>
                </div>
                <button type="button" class="settings-close" data-settings-close aria-label="Close settings">✕</button>
            </header>
            <div class="settings-body">
                <div class="settings-row settings-column settings-auto-block">
                    <div class="settings-row-head">
                        <div>
                            <p class="settings-title">Auto send email notification</p>
                            <p class="hint">Send your tasks to your inbox automatically when enabled.</p>
                        </div>
                        <label class="switch-control">
                            <input type="checkbox" data-settings-auto-email>
                            <span class="switch-handle" aria-hidden="true"></span>
                            <span class="sr-only">Auto send email notification</span>
                        </label>
                    </div>
                    <div class="settings-email-collapse is-collapsed" data-email-row>
                        <div>
                            <p class="settings-title">Notification email</p>
                            <p class="hint">We’ll use this address for the automatic summary.</p>
                        </div>
                        <label class="input-group">
                            <span>Email</span>
                            <input type="email" data-settings-email placeholder="you@example.com">
                        </label>
                        <label class="input-group">
                            <span>Time</span>
                            <input type="time" data-settings-email-time value="00:00">
                            <p class="hint">Defaults to 00:00 (UTC+8) if left empty.</p>
                        </label>
                        <label class="input-group">
                            <span>Topic</span>
                            <input type="text" data-settings-email-topic placeholder="TODO notification">
                        </label>
                        <div class="settings-actions-row">
                            <div class="settings-loading" data-settings-loading hidden>
                                <span class="settings-spinner" aria-hidden="true"></span>
                                <span>Sending...</span>
                            </div>
                            <button type="button" class="btn-primary" data-settings-send-email>Send now</button>
                            <button type="button" class="btn-ghost" data-settings-save>Confirm</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="plan-overlay" data-plan-overlay hidden>
        <div class="plan-panel" data-plan-panel>
            <header class="plan-header">
                <div>
                    <p class="eyebrow">Schedule helper</p>
                    <h3>Plan your tasks</h3>
                </div>
                <button type="button" class="plan-close" data-plan-close aria-label="Close plan overlay">✕</button>
            </header>
            <div class="plan-body">
                <p class="plan-hint">Select tasks, then assign their order for the schedule.</p>
                <div class="plan-list" data-plan-list></div>
            </div>
            <footer class="plan-footer">
                <button type="button" class="btn-ghost" data-plan-cancel>Cancel</button>
                <button type="button" class="btn-primary" data-plan-confirm disabled>Confirm selection</button>
            </footer>
        </div>
    </div>
    <div class="spotlight-overlay" data-spotlight-overlay hidden aria-modal="true" role="dialog">
        <div class="spotlight-panel" data-spotlight-panel>
            <div class="spotlight-bar">
                <div class="spotlight-tabs" data-spotlight-tabs>
                    <button type="button" class="spotlight-tab is-active" data-spotlight-tab="add">Quick add</button>
                    <button type="button" class="spotlight-tab" data-spotlight-tab="search">Search</button>
                    <span class="spotlight-tab-indicator" data-spotlight-tab-indicator></span>
                </div>
                <div class="spotlight-type" data-spotlight-type>
                    <button type="button" class="spotlight-chip is-active"
                        data-spotlight-type-option="task">Tasks</button>
                    <button type="button" class="spotlight-chip"
                        data-spotlight-type-option="category">Categories</button>
                </div>
            </div>
            <form class="spotlight-form" data-spotlight-form>
                <input type="hidden" name="action" value="add_task">
                <input type="hidden" name="deadline" value="">
                <input type="hidden" name="category_id" value="0">
                <input type="text" name="title" autocomplete="off" placeholder="Add a quick task..."
                    data-spotlight-input>
                <button type="submit" class="spotlight-submit" data-spotlight-submit
                    aria-label="Submit quick add">↵</button>
            </form>
            <p class="spotlight-hint" data-spotlight-hint>Shift + T to add, Shift + F to search</p>
            <div class="spotlight-results" data-spotlight-results hidden>
                <ul class="spotlight-list" data-spotlight-list></ul>
            </div>
        </div>
    </div>
</body>

</html>
