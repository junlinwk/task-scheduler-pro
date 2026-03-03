<?php
// Send today's tasks for a single user to a configured email using PHPMailer (SMTP if provided).
// Usage (CLI):
//   RECIPIENT_EMAIL=you@example.com USER_ID=1 php send_tasks_email.php
// Usage (HTTP POST for AJAX):
//   email, user_id, topic (optional), ajax=1

require_once __DIR__ . '/db.php';

// PHPMailer (if installed via composer)
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;

    // load .env if available (vlucas/phpdotenv)
    if (class_exists('\Dotenv\Dotenv')) {
        try {
            $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
            $dotenv->safeLoad();
        } catch (Throwable $e) {
            // non-fatal; we'll fall back to getenv() values if present
            error_log('Dotenv load error: ' . $e->getMessage());
        }
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$isCli = PHP_SAPI === 'cli';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$ajax = $isPost && isset($_POST['ajax']);

// env helper: prefer $_ENV (loaded by Dotenv) then getenv(), then default
function env($name, $default = null) {
    if (array_key_exists($name, $_ENV)) return $_ENV[$name];
    $v = getenv($name);
    return ($v === false) ? $default : $v;
}

if ($isPost) {
    $recipient = trim($_POST['email'] ?? '');
    $userId = (int)($_POST['user_id'] ?? 0) ?: 1;
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        $message = 'Invalid email.';
        if ($ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }
        echo $message . PHP_EOL;
        exit(1);
    }
} else {
    $recipient = env('RECIPIENT_EMAIL', 'you@example.com');
    $userId = (int)env('USER_ID', 1);
}

// Helper: respond JSON if ajax
function respond_json($ok, $message, $extra = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $ok, 'message' => $message], $extra));
    exit;
}

// Pull all incomplete tasks (including those without deadlines)
// Priority: overdue > today > no deadline > future
$stmt = $mysqli->prepare('
    SELECT id, title, deadline, notes, is_done 
    FROM tasks 
    WHERE user_id = ? 
      AND is_done = 0
    ORDER BY 
      CASE 
        WHEN deadline IS NULL THEN 3
        WHEN deadline < CURDATE() THEN 1
        WHEN deadline = CURDATE() THEN 2
        ELSE 4
      END,
      deadline ASC
');
$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();
$tasks = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($tasks)) {
    $output = "No incomplete tasks found (user_id={$userId}).";
    if ($ajax) {
        respond_json(true, $output, ['sent' => false, 'count' => 0]);
    }
    echo $output . PHP_EOL;
    exit(0);
}

$todayLabel = date('M j, Y');
$taskCount = count($tasks);

// Count overdue vs today vs no deadline vs future
$overdueCount = 0;
$todayCount = 0;
$noDeadlineCount = 0;
$futureCount = 0;
$today = date('Y-m-d');

foreach ($tasks as $t) {
    if ($t['deadline'] === null) {
        $noDeadlineCount++;
    } elseif ($t['deadline'] < $today) {
        $overdueCount++;
    } elseif ($t['deadline'] === $today) {
        $todayCount++;
    } else {
        $futureCount++;
    }
}

// Build colorful HTML email + plain fallback
$plain = "Your Tasks ({$todayLabel}) for user id={$userId}\n";
if ($overdueCount > 0) {
    $plain .= "⚠️ {$overdueCount} overdue task(s)\n";
}
if ($todayCount > 0) {
    $plain .= "📅 {$todayCount} task(s) due today\n";
}
if ($noDeadlineCount > 0) {
    $plain .= "📋 {$noDeadlineCount} task(s) without deadline\n";
}
if ($futureCount > 0) {
    $plain .= "🔜 {$futureCount} upcoming task(s)\n";
}
$plain .= "\n";

$taskItemsHtml = '';
foreach ($tasks as $t) {
    $isOverdue = !empty($t['deadline']) && $t['deadline'] < $today;
    $isToday = !empty($t['deadline']) && $t['deadline'] === $today;
    $noDeadline = empty($t['deadline']);
    
    if ($isOverdue) {
        $statusLabel = '⚠️ OVERDUE';
        $borderColor = 'rgba(239, 68, 68, 0.4)';
        $statusBadgeColor = '#ef4444';
        $statusText = 'OVERDUE';
    } elseif ($isToday) {
        $statusLabel = '📅 DUE TODAY';
        $borderColor = 'rgba(251, 191, 36, 0.4)';
        $statusBadgeColor = '#f59e0b';
        $statusText = 'DUE TODAY';
    } elseif ($noDeadline) {
        $statusLabel = '📋 NO DEADLINE';
        $borderColor = 'rgba(148, 163, 184, 0.3)';
        $statusBadgeColor = '#64748b';
        $statusText = 'NO DEADLINE';
    } else {
        $statusLabel = '🔜 UPCOMING';
        $borderColor = 'rgba(59, 130, 246, 0.3)';
        $statusBadgeColor = '#3b82f6';
        $statusText = 'UPCOMING';
    }
    
    $plain .= "- " . $statusLabel . " " . $t['title'] . " (deadline: " . ($t['deadline'] ?: 'none') . ")\n";
    if (!empty($t['notes'])) {
        $plain .= "  notes: " . str_replace(["\r\n", "\r"], "\n", $t['notes']) . "\n";
    }

    $avatar = strtoupper(substr($t['title'], 0, 1));
    $deadline = htmlspecialchars($t['deadline'] ?: 'No deadline');
    $title = htmlspecialchars($t['title']);
    
    $notesHtml = '';
    if (!empty($t['notes'])) {
        $notesHtml = '<div style="margin-top:10px;padding:12px;border-radius:12px;background:rgba(92,108,255,0.08);color:#0f172a;font-size:13px;line-height:1.6;border:1px solid rgba(92,108,255,0.18);">'
            . nl2br(htmlspecialchars($t['notes']))
            . '</div>';
    }

    // using table-based markup per item (more compatible with email clients)
    $taskItemsHtml .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:12px;border-collapse:separate;border-spacing:0;">'
        . '<tr>'
        . '<td style="padding:12px;border-radius:12px;border:2px solid ' . $borderColor . ';background:#ffffff;box-shadow:0 6px 18px rgba(15,23,42,0.04);">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            . '<tr>'
                . '<td width="56" valign="top" style="padding-right:12px;">'
                    . '<div style="width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#5c6cff,#ff8fb1);text-align:center;color:#fff;font-weight:800;font-size:16px;line-height:40px;">' . $avatar . '</div>'
                . '</td>'
                . '<td valign="top" style="vertical-align:top;">'
                    . '<div style="display:inline-block;padding:2px 8px;background:' . $statusBadgeColor . ';color:#fff;border-radius:4px;font-size:10px;font-weight:700;margin-bottom:6px;">' . $statusText . '</div>'
                    . '<div style="font-size:15px;font-weight:800;color:#0f172a;">' . $title . '</div>'
                    . '<div style="font-size:12px;color:#64748b;margin-top:6px;">Due ' . $deadline . '</div>'
                    . $notesHtml
                . '</td>'
            . '</tr>'
        . '</table>'
        . '</td>'
        . '</tr>'
        . '</table>';
}

$html = <<<HTML
<!doctype html>
<html>
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    </head>
    <body style="margin:0;background:#eef1ff;color:#0f172a;font-family:'Inter','Segoe UI','Helvetica Neue',Arial,sans-serif;">
        <div style="background:radial-gradient(circle at 10% 15%, rgba(92,108,255,0.28), transparent 38%),radial-gradient(circle at 90% 10%, rgba(255,143,177,0.24), transparent 30%),linear-gradient(180deg,#eef1ff 0%,#f9fbff 100%);padding:24px 14px;">
            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:720px;margin:0 auto;">
                <tr>
                    <td style="padding:0 0 16px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:linear-gradient(135deg,#0f172a,#1f2348);border-radius:16px;padding:18px;color:#f8fafc;">
                            <tr>
                                <td style="padding:10px 12px;vertical-align:middle;">
                                    <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
                                        <tr>
                                            <td style="vertical-align:middle;">
                                                <table role="presentation" cellpadding="0" cellspacing="0">
                                                    <tr>
                                                        <td style="width:42px;height:42px;border-radius:10px;background:linear-gradient(135deg,#5c6cff,#ff8fb1);text-align:center;color:white;font-weight:800;font-size:18px;">✔</td>
                                                        <td style="width:10px;"></td>
                                                        <td style="vertical-align:middle;">
                                                            <div style="font-size:12px;letter-spacing:0.06em;text-transform:uppercase;color:#cbd5e1;font-weight:700;">Daily Notification</div>
                                                            <div style="font-size:20px;font-weight:800;line-height:1.1;margin-top:4px;">Today’s Tasks</div>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </td>
                                            <td style="text-align:right;vertical-align:middle;font-size:13px;color:#e2e8f0;padding-left:8px;">{$todayLabel}</td>
                                        </tr>
                                    </table>
                                    <div style="color:#dbeafe;font-size:13px;margin-top:8px;">You have <strong>{$taskCount}</strong> urgent task(s). {$overdueCount} overdue, {$todayCount} due today.</div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td>
                        <div style="background:#ffffff;border-radius:22px;padding:20px;border:1px solid rgba(15,23,42,0.08);box-shadow:0 14px 28px rgba(15,23,42,0.12);">
                            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:12px;border-collapse:separate;">
                                <tr>
                                    <td width="18" valign="middle" style="padding-right:8px;vertical-align:middle;">
                                        <div style="width:12px;height:12px;border-radius:50%;background:linear-gradient(135deg,#5c6cff,#ff8fb1);"></div>
                                    </td>
                                    <td style="vertical-align:middle;font-weight:800;font-size:14px;color:#0f172a;">Today’s line-up</td>
                                </tr>
                            </table>
                            {$taskItemsHtml}
                            <div style="margin-top:12px;font-size:12px;color:#6b7280;line-height:1.6;">
                                This update comes from your TODO Tasks Scheduler. Adjust the send time or email inside the app settings anytime.
                            </div>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td style="padding:14px 6px 0;text-align:center;color:#94a3b8;font-size:12px;line-height:1.6;">
                        If you don't want to receive this email, you can adjust your preferences in the TODO tasks scheduler.
                    </td>
                </tr>
            </table>
        </div>
    </body>
</html>
HTML;

$subject = trim($_POST['topic'] ?? '') ?: ('Tasks for today - ' . date('Y-m-d'));

$fromEmail = env('SMTP_FROM', 'noreply@todo.example.com');
$fromName = env('SMTP_FROM_NAME', 'TODO Scheduler');
$smtpHost = env('SMTP_HOST', '');
$smtpPort = (int)env('SMTP_PORT', 587);
$smtpUser = env('SMTP_USER', '');
$smtpPass = env('SMTP_PASS', '');
$smtpSecure = env('SMTP_SECURE', 'tls'); // tls, ssl, or '' for none
$smtpTimeout = (int)env('SMTP_TIMEOUT', 20); // socket timeout in seconds
$useSmtp = !empty($smtpHost);
// debug level for PHPMailer SMTP (0 = off, 1..4 levels). Controlled by SMTP_DEBUG env.
$smtpDebug = (int)env('SMTP_DEBUG', 0);

// We'll capture PHPMailer error message for clearer debug output
$phpMailerError = '';
$sendWithPhpMailer = function () use ($recipient, $subject, $html, $plain, $fromEmail, $fromName, $smtpHost, $smtpPort, $smtpUser, $smtpPass, $smtpSecure, $useSmtp, &$phpMailerError, $smtpDebug, $isCli) {
    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        return false;
    }
    // tighten default socket timeout to avoid hanging the request
    $timeoutSeconds = max(5, $GLOBALS['smtpTimeout'] ?? 20);
    @ini_set('default_socket_timeout', (string)$timeoutSeconds);
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        // enable debug output if requested
        if ($smtpDebug && property_exists($mail, 'SMTPDebug')) {
            $mail->SMTPDebug = $smtpDebug;
            // write debug to stdout so CLI shows conversation; in web, it will echo too
            $mail->Debugoutput = 'echo';
        }
        // timing
        $t_total_start = microtime(true);
        $t_connect_start = null; $t_connect_end = null;
        $t_send_start = null; $t_send_end = null;

        if ($useSmtp) {
            $mail->isSMTP();
            $mail->Timeout = $timeoutSeconds;
            if (property_exists($mail, 'Timelimit')) {
                $mail->Timelimit = $timeoutSeconds + 5;
            }
            $mail->SMTPAutoTLS = true;
            $mail->SMTPKeepAlive = false;
            $mail->Host = $smtpHost;
            $mail->Port = $smtpPort;
            if ($smtpSecure) {
                $mail->SMTPSecure = $smtpSecure;
            }
            if ($smtpUser && $smtpPass) {
                $mail->SMTPAuth = true;
                $mail->Username = $smtpUser;
                $mail->Password = $smtpPass;
            } else {
                $mail->SMTPAuth = false;
            }
            // attempt an explicit connect so we can measure connect time separately
            if (method_exists($mail, 'smtpConnect')) {
                $t_connect_start = microtime(true);
                $mail->smtpConnect();
                $t_connect_end = microtime(true);
            }
        } else {
            $mail->isMail();
        }
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($recipient);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = $plain;
        $t_send_start = microtime(true);
        $mail->send();
        $t_send_end = microtime(true);
        $t_total_end = microtime(true);
        $connect_ms = $t_connect_start && $t_connect_end ? round(($t_connect_end - $t_connect_start) * 1000, 1) : 0;
        $send_ms = $t_send_start && $t_send_end ? round(($t_send_end - $t_send_start) * 1000, 1) : 0;
        $total_ms = round(($t_total_end - $t_total_start) * 1000, 1);
        $msg = sprintf('PHPMailer timings (connect=%sms send=%sms total=%sms)', $connect_ms, $send_ms, $total_ms);
        error_log($msg);
        if ($isCli) echo $msg . PHP_EOL;
        return true;
    } catch (\Throwable $e) {
        $phpMailerError = $e->getMessage();
        error_log('PHPMailer error: ' . $phpMailerError);
        return false;
    }
};

$sent = false;
if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
    $sent = $sendWithPhpMailer();
}

if (!$sent) {
    // Fallback to mail()
    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-type: text/html; charset=utf-8';
    $headers[] = 'From: ' . $fromEmail;
    $t_mail_start = microtime(true);
    $sent = @mail($recipient, $subject, $html, implode("\r\n", $headers));
    $t_mail_end = microtime(true);
    $mail_ms = round(($t_mail_end - $t_mail_start) * 1000, 1);
    $mailMsg = sprintf('mail() fallback timing: %sms', $mail_ms);
    error_log($mailMsg);
    if ($isCli) echo $mailMsg . PHP_EOL;
}

if ($sent) {
    $msg = "Sent tasks (" . count($tasks) . ") to {$recipient}";
    if ($isPost) {
        respond_json(true, $msg, ['sent' => true, 'count' => count($tasks)]);
    }
    echo $msg . "\n";
    exit(0);
} else {
    // Provide helpful debug output when using PHPMailer + SMTP
    if (!empty($phpMailerError)) {
        $msg = "PHPMailer reported an error: " . $phpMailerError;
        // If CLI, show it; if AJAX, include in JSON response
        if ($isPost) {
            respond_json(false, 'Mail failed; see debug.', ['debug' => $phpMailerError]);
        }
        echo "PHPMailer error: " . $phpMailerError . "\n";
    }

}

$fallback = "Mail send failed — dumping message to stdout for inspection.\n\n";
$dump = "To: {$recipient}\nSubject: {$subject}\n\n{$plain}";
if ($isPost) {
    respond_json(false, 'Mail failed; see server logs.', ['debug' => $dump]);
}
echo $fallback;
echo $dump;
exit(1);
