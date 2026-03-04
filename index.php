<?php
require_once 'db.php';
require_once 'auth.php';

// Placeholder for Google Auth - will be implemented in next step
$google_login_url = 'google_login.php';

$error = '';
$success = '';
$username_val = '';
$action = 'login';
$csrf_token = csrf_token();

if (is_logged_in()) {
    header('Location: todo.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_valid_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request token. Please refresh and try again.';
    }

    $action = $_POST['action'] ?? 'login';
    $username_val = sanitize_single_line($_POST['username'] ?? '', 255);
    $password = (string)($_POST['password'] ?? '');

    if ($error === '' && $action === 'login') {
        // --- LOGIN LOGIC ---
        if (rate_limit_is_blocked('login_attempts', 8, 300)) {
            $retryAfter = rate_limit_retry_after('login_attempts', 300);
            $error = 'Too many login attempts. Try again in ' . max($retryAfter, 1) . ' seconds.';
        }

        if ($error === '' && ($username_val === '' || $password === '')) {
            $error = 'Please enter both username and password.';
        }

        if ($error === '') {
            $stmt = $mysqli->prepare('SELECT id, username, password FROM users WHERE username = ?');
            $stmt->bind_param('s', $username_val);
            $stmt->execute();
            $stmt->bind_result($uid, $uname, $pw);
            if ($stmt->fetch()) {
                // Check if password is NULL (Google user trying to login with password)
                if ($pw === null) {
                    $error = 'Please log in with Google.';
                } else {
                    $isPasswordValid = false;
                    if (is_string($pw) && password_get_info($pw)['algo'] !== 0) {
                        $isPasswordValid = password_verify($password, $pw);
                        if ($isPasswordValid && password_needs_rehash($pw, PASSWORD_DEFAULT)) {
                            $newHash = password_hash($password, PASSWORD_DEFAULT);
                            $rehashStmt = $mysqli->prepare('UPDATE users SET password = ? WHERE id = ?');
                            if ($rehashStmt) {
                                $rehashStmt->bind_param('si', $newHash, $uid);
                                $rehashStmt->execute();
                                $rehashStmt->close();
                            }
                        }
                    } else {
                        // Legacy plaintext password compatibility; auto-upgrade on successful login.
                        $isPasswordValid = hash_equals((string)$pw, $password);
                        if ($isPasswordValid) {
                            $newHash = password_hash($password, PASSWORD_DEFAULT);
                            $upgradeStmt = $mysqli->prepare('UPDATE users SET password = ? WHERE id = ?');
                            if ($upgradeStmt) {
                                $upgradeStmt->bind_param('si', $newHash, $uid);
                                $upgradeStmt->execute();
                                $upgradeStmt->close();
                            }
                        }
                    }

                    if ($isPasswordValid) {
                        login_user($uid, $uname);
                        rate_limit_clear('login_attempts');
                        header('Location: todo.php');
                        exit;
                    }
                    $error = 'Incorrect password.';
                }
            } else {
                $error = 'User not found.';
            }
            $stmt->close();

            if ($error !== '') {
                rate_limit_register_hit('login_attempts', 300);
            }
        }
    } elseif ($error === '' && $action === 'register') {
        // --- REGISTER LOGIC ---
        $confirm = (string)($_POST['confirm'] ?? '');

        if ($username_val === '' || $password === '' || $confirm === '') {
            $error = 'All fields are required.';
        } elseif (!preg_match('/^[a-zA-Z0-9_.@-]{3,64}$/', $username_val)) {
            $error = 'Username must be 3-64 chars and only use letters, numbers, ., _, @, -';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Password confirmation does not match.';
        } else {
            // Check if username exists
            $stmt = $mysqli->prepare('SELECT id FROM users WHERE username = ?');
            $stmt->bind_param('s', $username_val);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $error = 'Username already taken.';
                $stmt->close();
            } else {
                $stmt->close();
                // Insert user with hashed password
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $mysqli->prepare('INSERT INTO users (username, password) VALUES (?, ?)');
                $stmt->bind_param('ss', $username_val, $passwordHash);
                if ($stmt->execute()) {
                    $user_id = $stmt->insert_id;
                    $stmt->close();

                    // Create default category
                    $name = 'None';
                    $is_default = 1;
                    $defaultColor = '#94A3B8';
                    $stmt2 = $mysqli->prepare('INSERT INTO categories (user_id, name, is_default, color) VALUES (?, ?, ?, ?)');
                    $stmt2->bind_param('isis', $user_id, $name, $is_default, $defaultColor);
                    $stmt2->execute();
                    $stmt2->close();

                    $success = 'Registration successful! Please log in.';
                    $action = 'login';
                    $username_val = '';
                } else {
                    $error = 'Failed to register user.';
                    $stmt->close();
                }
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
    <title>Scheduler Todo List - Access</title>
    <link rel="stylesheet" href="assets/style_login.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>

    <!-- Animated Background -->
    <div class="background-fx">
        <div class="orb orb-1"></div>
        <div class="orb orb-2"></div>
        <div class="orb orb-3"></div>
    </div>

    <main class="auth-container">

        <!-- Initial Split View -->
        <div class="split-view" id="splitView">
            <div class="split-card" onclick="openMode('login')">
                <i class="fas fa-sign-in-alt icon"></i>
                <h2>Login</h2>
                <p>Access your workspace</p>
            </div>
            <div class="split-card" onclick="openMode('register')">
                <i class="fas fa-user-plus icon"></i>
                <h2>Register</h2>
                <p>Start your journey</p>
            </div>
        </div>

        <!-- Forms Wrapper -->
        <div class="forms-wrapper">

            <!-- Login Form -->
            <div class="auth-form-container form-login <?php echo ($error && $action === 'login') ? 'card-error' : ''; ?>"
                id="loginForm">
                <button class="btn-back" onclick="closeMode()"><i class="fas fa-arrow-left"></i></button>

                <div class="form-header">
                    <h2>Welcome Back</h2>
                    <p>Enter your credentials to continue.</p>
                </div>

                <?php if ($error && $action === 'login'): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="action" value="login">
                    <input type="hidden" name="csrf_token"
                        value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="input-group">
                        <label>Username</label>
                        <input type="text" name="username" required
                            value="<?php echo htmlspecialchars($username_val, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="input-group">
                        <label>Password</label>
                        <div class="password-wrapper">
                            <input type="password" name="password" id="loginPassword" required>
                            <i class="fas fa-eye toggle-password" onclick="togglePassword('loginPassword', this)"></i>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary">Login</button>

                    <div class="divider"><span>OR</span></div>

                    <a href="<?php echo htmlspecialchars($google_login_url, ENT_QUOTES, 'UTF-8'); ?>" class="btn-google">
                        <img src="https://www.google.com/favicon.ico" alt="Google" width="20">
                        Continue with Google
                    </a>
                </form>
            </div>

            <!-- Register Form -->
            <div class="auth-form-container form-register <?php echo ($error && $action === 'register') ? 'card-error' : ''; ?>"
                id="registerForm">
                <button class="btn-back" onclick="closeMode()"><i class="fas fa-arrow-left"></i></button>

                <div class="form-header">
                    <h2>Create Account</h2>
                    <p>Join us and get organized.</p>
                </div>

                <?php if ($error && $action === 'register'): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="action" value="register">
                    <input type="hidden" name="csrf_token"
                        value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="input-group">
                        <label>Username</label>
                        <input type="text" name="username" required
                            value="<?php echo htmlspecialchars($username_val, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="input-group">
                        <label>Password</label>
                        <div class="password-wrapper">
                            <input type="password" name="password" id="regPassword" required>
                            <i class="fas fa-eye toggle-password" onclick="togglePassword('regPassword', this)"></i>
                        </div>
                    </div>
                    <div class="input-group">
                        <label>Confirm Password</label>
                        <div class="password-wrapper">
                            <input type="password" name="confirm" id="regConfirm" required>
                            <i class="fas fa-eye toggle-password" onclick="togglePassword('regConfirm', this)"></i>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary">Register</button>

                    <div class="divider"><span>OR</span></div>

                    <a href="<?php echo htmlspecialchars($google_login_url, ENT_QUOTES, 'UTF-8'); ?>" class="btn-google">
                        <img src="https://www.google.com/favicon.ico" alt="Google" width="20">
                        Sign up with Google
                    </a>
                </form>
            </div>

        </div>
    </main>

    <script>
    function openMode(mode) {
        document.body.classList.remove('mode-login', 'mode-register');
        document.body.classList.add('mode-' + mode);
    }

    function closeMode() {
        document.body.classList.remove('mode-login', 'mode-register');
    }

    function togglePassword(inputId, icon) {
        const input = document.getElementById(inputId);
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }

    // If PHP reported an error, reopen the relevant form
    <?php if ($error || $success): ?>
    <?php if ($action === 'login' || $success): ?>
    openMode('login');
    <?php elseif ($action === 'register'): ?>
    openMode('register');
    <?php endif; ?>
    <?php endif; ?>
    </script>
</body>

</html>
