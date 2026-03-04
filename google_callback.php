<?php
require_once 'db.php';
require_once 'auth.php';
require_once 'google_auth.php';

if (isset($_GET['code'])) {
    $receivedState = $_GET['state'] ?? '';
    $expectedState = $_SESSION['google_oauth_state'] ?? '';
    unset($_SESSION['google_oauth_state']);

    if (!is_string($receivedState) || $receivedState === '' || !hash_equals((string)$expectedState, $receivedState)) {
        http_response_code(400);
        die('Invalid OAuth state.');
    }

    $token_data = get_google_token((string)$_GET['code']);

    if (isset($token_data['access_token'])) {
        $user_info = get_google_user_info($token_data['access_token']);

        if (isset($user_info['sub'])) { // 'sub' is the unique Google ID
            $google_id = $user_info['sub'];
            $email = sanitize_single_line($user_info['email'] ?? '', 255);

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                die('Invalid email returned from Google.');
            }

            // 1. Check if user exists with this google_id
            $stmt = $mysqli->prepare('SELECT id, username FROM users WHERE google_id = ?');
            $stmt->bind_param('s', $google_id);
            $stmt->execute();
            $stmt->bind_result($uid, $uname);

            if ($stmt->fetch()) {
                // User exists, log them in
                login_user($uid, $uname);
                $stmt->close();
                header('Location: todo.php');
                exit;
            }
            $stmt->close();

            // 2. Check if user exists with this email (link accounts)
            // We'll treat the email as the username for Google users if they don't exist
            $stmt = $mysqli->prepare('SELECT id FROM users WHERE username = ?');
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $stmt->bind_result($uid);

            if ($stmt->fetch()) {
                // User exists by email, link the google_id
                $stmt->close();
                $stmt = $mysqli->prepare('UPDATE users SET google_id = ? WHERE id = ?');
                $stmt->bind_param('si', $google_id, $uid);
                $stmt->execute();
                $stmt->close();

                login_user($uid, $email);
                header('Location: todo.php');
                exit;
            }
            $stmt->close();

            // 3. Create new user
            // Username = email, Password = NULL, google_id = ...
            $stmt = $mysqli->prepare('INSERT INTO users (username, password, google_id) VALUES (?, NULL, ?)');
            $stmt->bind_param('ss', $email, $google_id);

            if ($stmt->execute()) {
                $new_uid = $stmt->insert_id;
                $stmt->close();

                // Create default category
                $cat_name = 'None';
                $is_default = 1;
                $stmt2 = $mysqli->prepare('INSERT INTO categories (user_id, name, is_default) VALUES (?, ?, ?)');
                $stmt2->bind_param('isi', $new_uid, $cat_name, $is_default);
                $stmt2->execute();
                $stmt2->close();

                login_user($new_uid, $email);
                header('Location: todo.php');
                exit;
            } else {
                error_log('Error creating user in google_callback.php: ' . $mysqli->error);
                http_response_code(500);
                die('Unable to create user.');
            }

        } else {
            die('Could not retrieve user info from Google.');
        }
    } else {
        error_log('Could not retrieve access token from Google: ' . json_encode($token_data));
        http_response_code(400);
        die('Could not retrieve access token.');
    }
} else {
    // No code returned, redirect back to login
    header('Location: index.php');
    exit;
}
?>
