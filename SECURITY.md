# Security Notice / 安全提醒

⚠️ **Important: Before uploading to GitHub**

## Files to Review

1. **`.env.local`** - Contains sensitive credentials
   - ✅ Already in `.gitignore`
   - ⚠️ Verify it's not tracked: `git status`
   - 🔍 Check history: `git log --all --full-history -- .env.local`

2. **`db.php`** - Contains database credentials
   - Current credentials: `cvml` / `dwpcvml2025`
   - ⚠️ Change to example values or add to `.gitignore`
   - 💡 Recommended: Use environment variables instead

3. **`vendor/`** - Third-party dependencies
   - ✅ Already in `.gitignore`
   - Should be installed via `composer install`

4. **`.DS_Store`** - macOS system file
   - ✅ Already in `.gitignore`

## Before Push Checklist

- [ ] Remove or replace real database credentials in `db.php`
- [ ] Verify `.env.local` is not committed
- [ ] Review commit history for sensitive data
- [ ] Consider using `.env` + environment variables for all credentials
- [ ] Update Google OAuth credentials (if using)
- [ ] Replace demo account password if it's a real one

## Recommended: Use Environment Variables

Modify `db.php` to use environment variables:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

// Load environment variables
if (file_exists(__DIR__ . '/.env.local')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__, '.env.local');
    $dotenv->load();
}

$DB_HOST = getenv('DB_HOST') ?: 'localhost';
$DB_USER = getenv('DB_USER') ?: 'your_username';
$DB_PASS = getenv('DB_PASS') ?: 'your_password';
$DB_NAME = getenv('DB_NAME') ?: 'Scheduler';

$appTimezone = getenv('APP_TIMEZONE') ?: 'Asia/Taipei';
date_default_timezone_set($appTimezone);

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($mysqli->connect_errno) {
    die('Failed to connect to MySQL: (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error);
}
$mysqli->set_charset('utf8mb4');
?>
```

Then add to `.env.local`:
```
DB_HOST=localhost
DB_USER=your_username
DB_PASS=your_password
DB_NAME=Scheduler
```
