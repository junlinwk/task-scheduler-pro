<?php
require_once 'db.php';
require_once 'auth.php';

$error = '';
$success = '';
$username = '';
$csrf_token = csrf_token();

if (is_logged_in()) {
    header('Location: todo.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_valid_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request token. Please refresh and try again.';
    }

    $username = sanitize_single_line($_POST['username'] ?? '', 255);
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm'] ?? '');

    if ($username === '' || $password === '' || $confirm === '') {
        $error = 'All fields are required.';
    } elseif ($error === '' && !preg_match('/^[a-zA-Z0-9_.@-]{3,64}$/', $username)) {
        $error = 'Username must be 3-64 chars and only use letters, numbers, ., _, @, -';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Password confirmation does not match.';
    } else {
        // check if username exists
        $stmt = $mysqli->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $error = 'Username already taken.';
            $stmt->close();
        } else {
            $stmt->close();
            // insert user (hashed password)
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $mysqli->prepare('INSERT INTO users (username, password) VALUES (?, ?)');
            $stmt->bind_param('ss', $username, $passwordHash);
            if ($stmt->execute()) {
                $user_id = $stmt->insert_id;
                $stmt->close();
                // create default "None" category for this user
                $name = 'None';
                $is_default = 1;
                $defaultColor = '#94A3B8';
                $stmt2 = $mysqli->prepare('INSERT INTO categories (user_id, name, is_default, color) VALUES (?, ?, ?, ?)');
                $stmt2->bind_param('isis', $user_id, $name, $is_default, $defaultColor);
                $stmt2->execute();
                $stmt2->close();

                $success = 'Registration successful. You can now login.';
                $username = '';
            } else {
                $error = 'Failed to register user.';
                $stmt->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scheduler Todo List - Register</title>
    <link rel="stylesheet" href="assets/style.css">
    <script src="assets/script.js" defer></script>
</head>

<body class="auth-body">
    <main class="auth-shell reverse">
        <section class="auth-intro">
            <p class="eyebrow">Craft Your Momentum</p>
            <h1>Join Scheduler Todo Studio</h1>
            <p class="intro-copy">
                Unlock a meticulous workspace for categorizing projects, tracking deadlines, and
                monitoring progress through a dashboard crafted for clarity.
            </p>
            <ul class="feature-list">
                <li>🧩 Smart categories with instant rename &amp; delete controls</li>
                <li>📊 Live stats that highlight focus, wins, and overdue work</li>
                <li>🛡️ Session-aware design &amp; graceful error handling</li>
            </ul>
        </section>

        <section class="auth-panel">
            <div class="glass-card">
                <div class="card-heading">
                    <h2>Create Your Account</h2>
                    <p>Only takes a minute to prepare the perfect canvas.</p>
                </div>
                <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                <form method="post" class="stack-form">
                    <input type="hidden" name="csrf_token"
                        value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="input-group">
                        <label for="username">Username</label>
                        <input id="username" type="text" name="username"
                            value="<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="username"
                            required>
                    </div>
                    <div class="input-group input-password">
                        <label for="password">Password</label>
                        <div class="input-with-action">
                            <input id="password" type="password" name="password" autocomplete="new-password" required
                                data-password-field>
                            <button type="button" class="btn-ghost" data-toggle-password aria-pressed="false">
                                Show
                            </button>
                        </div>
                    </div>
                    <div class="input-group input-password">
                        <label for="confirm">Confirm Password</label>
                        <div class="input-with-action">
                            <input id="confirm" type="password" name="confirm" autocomplete="new-password" required
                                data-password-field>
                            <button type="button" class="btn-ghost" data-toggle-password aria-pressed="false">
                                Show
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="btn-primary full-width">Register</button>
                </form>

                <p class="auth-hint">
                    Already part of the us? <a href="index.php">Return to login</a>.
                </p>
            </div>
        </section>
    </main>
</body>

</html>
