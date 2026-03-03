# Scheduler - Task Management Application

**Language / 語言:** [English](README.md) | [繁體中文](README_ZH.md)

> A feature-rich task scheduler with Google OAuth, email notifications, Spotlight-style quick search, and real-time statistics dashboard. Built with PHP & MySQL.

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.4-blue)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-8.0-orange)](https://www.mysql.com/)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

## Visit the Web App
[![Visit Web App](https://img.shields.io/badge/Visit_Web_App-orange?style=for-the-badge&logo=google-chrome)](https://task-scheduler-pro.infinityfreeapp.com/index.php)

## Table of Contents
1. [Project Overview](#project-overview)
2. [Key Features](#key-features)
3. [Getting Started](#getting-started)
4. [Features at a Glance](#features-at-a-glance)
5. [Project Structure](#project-structure)
6. [Database Design](#database-design)
7. [Core Functionality](#core-functionality)
8. [Frontend Features](#frontend-features)
9. [Security Considerations](#security-considerations)
10. [Data Flow](#data-flow)


## Project Overview

A fully-featured task management application built with PHP, MySQL, HTML, CSS, and JavaScript. Provides user account management, task categorization, note system, and real-time interactive interface, integrated with Google OAuth login and email notification functionality.

---

## Key Features

- **Multiple Authentication Methods**: Support for traditional login and Google OAuth 2.0
- **Spotlight Quick Search**: macOS Spotlight-inspired interface - press `Shift+T` to quickly add tasks, `Shift+F` for global search
- **Email Notification System**: Integrated with PHPMailer, supports scheduled task reminders
- **Real-time Statistics Dashboard**: Completion rate, task distribution, and overdue reminders at a glance
- **Multiple View Modes**: Calendar view, timeline view, category view
- **Custom Category Colors**: Support for hexadecimal color selection for visual task management
- **Dark Mode**: Support for light/dark theme switching
- **AJAX Real-time Updates**: Complete most operations without page refresh

---

## Getting Started

### Prerequisites
- PHP >= 7.4
- MySQL Server (or MariaDB)
- Web Server (Apache or PHP built-in server for testing)
- Composer (for installing PHPMailer and phpdotenv): Run `composer install` in project root
- Mailer Configuration: To use email notification features, configure email sending (SMTP recommended) and related environment variables

- Basic Mailer Setup:
  - This project uses PHPMailer (declared in `composer.json`) and attempts to read parameters from `.env` or system environment variables in `send_tasks_email.php`
  - Recommended to place configuration in project root `.env` file (to avoid committing passwords to repository); if using `.env`, ensure `vlucas/phpdotenv` is installed

  Example `.env`: (please refer to .env.example)
  ```env
  # SMTP server
  SMTP_HOST=smtp.example.com
  SMTP_PORT=587
  SMTP_USER=you@example.com
  SMTP_PASS=your-smtp-password
  SMTP_SECURE=tls    # tls or ssl, or leave empty for no TLS/SSL
  SMTP_TIMEOUT=20
  SMTP_DEBUG=0

  # Sender Information
  SMTP_FROM=noreply@todo.example.com
  SMTP_FROM_NAME="TODO Scheduler"

  # Direct Testing (CLI)
  RECIPIENT_EMAIL=you@example.com
  USER_ID=1
  ```

  - Gmail SMTP Configuration Tips:
    - SMTP_HOST: `smtp.gmail.com`
    - SMTP_PORT: `587` (TLS) or `465` (SSL)
    - SMTP_USER: Your Gmail account
    - SMTP_PASS: Use "App Password" (requires 2FA enabled)
    - App Password Setup: Google Account → Security → 2-Step Verification → App Passwords


  Testing Mailer Functionality:
  - CLI Test:
    ```bash
    RECIPIENT_EMAIL=you@example.com USER_ID=1 php send_tasks_email.php
    ```
  - HTTP POST Test (local):
    ```bash
    curl -X POST -d "email=you@example.com&user_id=1&topic=Daily" http://127.0.0.1:8080/send_tasks_email.php
    ```

  - Falls back to `mail()` (PHP built-in function) on failure; use `SMTP_DEBUG=1` to show details

### Installation Steps

1. **Clone the Project**:
```bash
git clone https://github.com/yourusername/scheduler.git
cd scheduler
```

2. **Install PHP Dependencies**:
```bash
composer install
```

3. **Configure Database**:
```bash
# Login to MySQL
mysql -u your_username -p

# Import database structure and sample data
SOURCE /path/to/scheduler.sql;
```

4. **Configure Environment Variables** (optional, for email functionality):
```bash
# Copy example configuration file
cp .env.example .env.local

# Edit .env.local with your SMTP settings
```

5. **Configure Database Connection**:
Edit `db.php` and modify the following parameters:
```php
$DB_HOST = 'localhost';
$DB_USER = 'your_username';
$DB_PASS = 'your_password';
$DB_NAME = 'Scheduler';
```

6. **Start Development Server**:
```bash
# Run from project root directory
php -S 127.0.0.1:8080

# Open in browser
# http://127.0.0.1:8080/index.php
```

7. **Test Login** (using default account):
- Username: `demo`
- Password: `demo123`

---

## Features at a Glance

### Task Management
- **Add Task**: Support for title, deadline, category selection
- **Inline Editing**: Click task title to edit directly, auto-save
- **Change Category**: Quick category switching via dropdown menu, instant visual update
- **Note System**: Support for long-text notes with AJAX asynchronous saving
- **Mark Complete**: Toggle status via checkbox, statistics auto-update
- **Delete Task**: Confirmation dialog to prevent accidental deletion
- **Task Statistics**: Real-time calculation of total, completed, overdue, and upcoming tasks

### Category Management
- **Custom Categories**: Support for custom names and hexadecimal colors (#RRGGBBAA)
- **Default Category**: Each user automatically gets an undeletable "None" category
- **Rename Category**: Inline editing of category names (except default category)
- **Smart Delete**: Two deletion modes available
  - **Delete All**: Remove category and all its tasks
  - **Detach**: Remove category, move tasks to "None"
- **Color Sync**: All related task tags update when category color changes
- **Category Filter**: Click category to filter and display all tasks in that category

### User System
- **User Registration**: Create new account, auto-generate default category
- **Traditional Login**: Username/Password authentication with Session management
- **Google OAuth**: Support for quick login with Google account
- **Secure Logout**: Clear Session and redirect to login page

### Advanced Features
- **Spotlight Quick Search**: 
  - `Shift+T`: Quick add task
  - `Shift+F`: Global task search
- **Email Notifications**: Integrated PHPMailer, support for scheduled or instant task reminders
- **Today's Mission**: Dashboard automatically displays tasks due today
- **Priority Sorting**: Manually arrange todo list priority
- **Multiple View Switching**:
  - **Calendar View**: Monthly calendar display, click date to view tasks for that day
  - **Timeline View**: Sorted by deadline (overdue → today → future)
  - **Statistics View**: Completion rate pie chart, task distribution (sorted high to low)

### Interface Features
- **Dark Mode**: Support for light/dark theme switching, preference stored in localStorage
- **AJAX Interaction**: Note saving, completion toggling without page refresh
- **Responsive Design**: Support for desktop, tablet, and mobile devices
- **Visual Tags**: Category tags display custom colors at a glance


---

## Project Structure

```
.
├── index.php                    # Login/Home page
├── register.php                 # User registration page
├── todo.php                     # Main dashboard (login required)
├── logout.php                   # Logout functionality
├── auth.php                     # Session management helper functions
├── db.php                       # MySQL connection configuration
├── scheduler.sql                # Database initialization script
├── update_schema.php            # Database structure upgrade tool
├── send_tasks_email.php         # Email sending module
├── google_auth.php              # Google authentication configuration
├── google_callback.php          # Google OAuth callback
├── google_login.php             # Google login route
├── assets/
│   ├── style.css                # Main stylesheet
│   ├── style_login.css          # Login page styles
│   └── script.js                # Frontend logic (3128+ lines)
├── vendor/                      # Third-party libraries (PHPMailer, etc.)
└── README.md                    # This document
```

---

## Database Design

### Database Name: `Scheduler`

### Schema

#### users Table - User Information
| Field | Type | Description |
|------|------|------|
| id | INT (PK) | User primary key |
| username | VARCHAR(255) | Username (unique) |
| password | VARCHAR(255) | Password (nullable for Google auth) |
| google_id | VARCHAR(255) | Google Account ID (nullable) |

#### categories Table - Task Categories
| Field | Type | Description |
|------|------|------|
| id | INT (PK) | Category primary key |
| user_id | INT (FK) | User ID |
| name | VARCHAR(255) | Category name |
| is_default | TINYINT | 1 = Default "None" category |
| color | VARCHAR(9) | Tag color (hexadecimal: #RRGGBBAA) |

#### tasks Table - Todo Tasks
| Field | Type | Description |
|------|------|------|
| id | INT (PK) | Task primary key |
| user_id | INT (FK) | User ID |
| category_id | INT (FK) | Category ID |
| title | VARCHAR(255) | Task title |
| deadline | DATE | Deadline (nullable) |
| is_done | TINYINT | 0 = incomplete, 1 = complete |
| notes | LONGTEXT | Task notes (nullable) |

---

## Core Functionality

### 1. Authentication System

#### User Registration (`register.php`)
- **Function**: Create new user account
- **Required Fields**: Username, password, confirm password
- **Validation**:
  - Check username uniqueness
  - Password length validation
  - Password confirmation match check
- **Auto Operation**: Automatically create default "None" category after successful registration
- **Data Persistence**: User information stored in `users` table

#### User Login (`index.php`)
- **Function**: User authentication
- **Login Form**: Username and password fields
- **Validation Flow**:
  1. Check if username exists
  2. Verify password correctness
  3. Set `$_SESSION['user_id']` and `$_SESSION['username']` on success
  4. Redirect to main dashboard (`todo.php`)
- **Demo Account**: Use demo/demo123 for testing

#### User Logout (`logout.php`)
- **Function**: End user session
- **Flow**:
  1. Destroy `$_SESSION`
  2. Clear all session variables
  3. Redirect back to login page (`index.php`)
- **Session Management**: Protected by `is_logged_in()` and `require_login()` functions in `auth.php`

### 2. Task Management

#### Add Task (`action=add_task`)
- **Submit Location**: Task form in todo.php
- **Input Fields**:
  - Task title (required)
  - Deadline (optional, date format)
  - Category selection (default = "None")
- **Database Operation**:
  ```sql
  INSERT INTO tasks (user_id, category_id, title, deadline, is_done, notes)
  VALUES (?, ?, ?, ?, 0, '')
  ```
- **Logic**:
  1. Check if title is empty
  2. If category ID = 0, auto-set to user's default "None" category
  3. Save to tasks table, is_done initialized to 0

#### Edit Task Name (`action=update_task_name`)
- **Submit Method**: Inline editing form (contenteditable field)
- **Operation Flow**:
  1. Task title on card is editable
  2. User edits and presses Enter or click save button
  3. Backend updates `tasks.title` and returns success status
- **Data Persistence**: Changes immediately saved to MySQL

#### Change Task Category (`action=update_task_category`)
- **Submit Method**: Dropdown menu selection
- **Operation Flow**:
  1. Select new category in task card
  2. POST submit task_id and new category_id
  3. Update `tasks.category_id`
- **Instant Reflection**: Page refresh or AJAX update displays new category tag

#### Deadline Management
- **Setting Method**: Select date when adding/editing task
- **Statistics Feature**: Main dashboard displays:
  - Number of overdue tasks
  - Number of upcoming tasks
  - Next deadline reminder

#### Mark Task Complete (`action=toggle_done`)
- **Submit Method**: Checkbox on task card
- **Operation Flow**:
  1. Click checkbox to toggle task completion status
  2. Supports AJAX method (no page refresh needed)
  3. Backend updates `tasks.is_done` (0 ↔ 1)
- **Instant Statistics Update**: If using AJAX, returns updated statistics data
- **Visual Feedback**: Completed tasks display different style (strikethrough, gray)

#### Delete Task (`action=delete_task`)
- **Submit Method**: Delete button on task card
- **Confirmation Mechanism**: Display confirmation dialog to prevent accidental deletion
- **Operation**:
  1. User clicks delete button
  2. Confirmation prompt appears
  3. After confirmation: DELETE FROM tasks WHERE id = ?
- **Cascade Consideration**: Statistics automatically updated after deletion

#### Manage Task Notes (`action=update_task_notes`)
- **Submit Method**: 
  - Click task card to open notes overlay
  - Submit after completing notes input
- **Feature Characteristics**:
  - Supports AJAX asynchronous submission (returns JSON format)
  - Arbitrary length text (LONGTEXT type)
  - Immediately updates display in UI after saving
- **Multiple Displays**: Notes content displayed in:
  - Main task card summary
  - Calendar view task list
  - Statistics panel task overlay
  - Plan view task list

#### Task Statistics Data
Application calculates and displays:
- **Total Tasks**: Count of all user tasks
- **Completed**: Number of tasks where is_done = 1
- **Active Tasks**: Number of tasks where is_done = 0
- **Overdue**: Number of incomplete tasks past deadline
- **Upcoming**: Number of incomplete tasks with future deadline
- **Completion Rate**: (Completed / Total) × 100%
- **Next Deadline**: Earliest upcoming task deadline date

### 3. Category Management

#### Add Category (`action=add_category`)
- **Submit Location**: "Add Category" form in todo.php
- **Input Fields**:
  - Category name (required)
  - Color selection (hexadecimal color picker, optional)
- **Database Operation**:
  ```sql
  INSERT INTO categories (user_id, name, is_default, color)
  VALUES (?, ?, 0, ?)
  ```
- **Default Color**: Uses system default color if not selected

#### Rename Category (`action=update_category_name`)
- **Submit Method**: Inline editing in category list
- **Restriction**: Cannot rename default "None" category (is_default = 1)
- **Operation**:
  1. Click category name to enter edit mode
  2. Enter new name and press Enter or save
  3. Backend updates `categories.name`

#### Change Category Color (`action=update_category_color`)
- **Submit Method**: Color picker on category card
- **Color Format**: Hexadecimal (#RRGGBB or #RRGGBBAA, supports transparency)
- **Operation**:
  1. Select new color
  2. Submit category_id and new color value
  3. Update `categories.color` and instantly reflect on tags
- **Visual Update**: All task tags using this category update simultaneously

#### Delete Category (`action=delete_category`)
- **Submit Method**: Delete button on category card
- **Delete Modes**:
  - **delete_all**: Delete category and all its tasks
  - **detach**: Delete category, move its tasks to default "None" category
- **Restriction**: Cannot delete default "None" category
- **Confirmation Mechanism**: Display delete mode selection dialog
- **Data Operation**:
  - delete_all mode: `DELETE FROM tasks WHERE category_id = ?` then `DELETE FROM categories WHERE id = ?`
  - detach mode: `UPDATE tasks SET category_id = <default_id> WHERE category_id = ?` then delete category

#### Default Category ("None")
- **Auto Creation**: Automatically created when each user registers
- **Purpose**: Fallback category for tasks without specified category
- **Characteristics**:
  - is_default = 1
  - Cannot be renamed or deleted
  - All new tasks automatically assigned to this category if category selection invalid

### 4. Notes & Overlay System

#### Task Notes Feature
- **Storage Location**: `tasks.notes` field (LONGTEXT)
- **Edit Method**:
  1. Click task card to open global notes overlay
  2. Edit long text in overlay
  3. Click save or Ctrl+S to submit
- **Submit Method**: AJAX (no page refresh), supports instant save
- **Character Limit**: Unlimited (LONGTEXT max 4GB)

#### Overlay System
Application includes multiple overlay modules:

**Global Task Notes Overlay**
- Opens when clicking task in main task list
- Contains task summary and full notes editing area
- Supports Markdown preview (optional)
- Save and cancel buttons

**Statistics Panel Overlay**
- Displays application statistics
- Central card shows:
  - Completion rate circular progress chart
  - Total, completed, active, overdue, upcoming counts
  - Next deadline reminder

**Plan View Overlay**
- Displays all tasks in timeline
- Sorted by deadline (overdue → today → tomorrow → future)
- Supports quick task operations (complete/delete/edit notes)
- All overlays have background lock

**Calendar View**
- Monthly calendar display
- Dates with tasks marked with visual indicators
- Click date to view all tasks for that day
- Supports month navigation (previous/next month)

**Settings Overlay** (additional feature)
- Theme switching (light/dark mode)
- Email notification settings (stored in localStorage)

**Confirmation Dialogs**
- Confirmation before delete operations
- Category delete mode selection
- Custom prompt text

#### Overlay Management
Frontend uses JS to manage overlay lifecycle:
- `lockBodyScroll()`: Lock background scrolling when opening overlay
- `unlockBodyScroll()`: Unlock when closing overlay
- Supports multiple stacked overlays
- ESC key for quick close
- Background click to close (settings dependent)

---

## Frontend Features

### User Interface Components

#### Main Dashboard (todo.php)
- **Top Navigation**: Username, theme toggle, logout button
- **Statistics Panel**: Four cards displaying key metrics
- **Quick Actions Area**: Add task form, add category form
- **Task List**: Grouped by category, supports inline editing
- **Sidebar**: Category selection, calendar view

#### Task Card Design
- **Card Information**:
  - Task title (clickable for inline editing)
  - Completion status checkbox
  - Category tag (with color)
  - Deadline (relative time: "today", "tomorrow", "in 3 days", etc.)
  - Notes summary (click to open full notes)
- **Interaction Methods**:
  - Click card to open notes overlay
  - Click title to edit name
  - Dropdown menu to change category
  - Checkbox to mark complete
  - Right-click menu or button to delete

#### Category Management UI
- **Category List**: Display all user categories
- **Category Card**: Contains:
  - Category name (editable, except default "None")
  - Category color block (clickable to select new color)
  - Category count (number of tasks in this category)
  - Delete button (with delete mode selection)

#### Responsive Design
- **Mobile Optimization**: Touch-friendly buttons and inputs
- **Breakpoint Adaptation**: Tablet and desktop views
- **CSS Grid**: Card arrangement auto-adapts

### JavaScript Functionality (script.js - 3128+ lines)

#### Event Handling
- Form submission events
- Checkbox change events
- Click delegation handling
- Keyboard shortcuts (Enter to save, Esc to cancel, Ctrl+S to submit)

#### AJAX Operations
- Task notes saving (asynchronous)
- Task completion toggle (asynchronous)
- Statistics data update (no refresh)
- Category color update (instant reflection)

#### Calendar Module
- Month view rendering
- Date event marking
- Month navigation control
- Date click events

#### Theme System
- Light/dark mode switching
- Dynamic CSS variable switching
- localStorage stores user preference

#### Email Notifications (send_tasks_email.php)
- Scheduled task email reminders
- Based on PHPMailer library
- SMTP configuration support

---

## Security Considerations

### Current Implementation
- Login Protection: `require_login()` protects all restricted pages
- Session Management: Based on PHP SESSION
- Database Connection: MySQLi prepared statements (prevent SQL injection)
- HTML Escaping: `htmlspecialchars()` prevents XSS

### Known Limitations & Improvement Suggestions
- **Password Storage**: Currently uses plaintext storage (demo only)
  - **Improvement**: Use `password_hash()` and `password_verify()`
  ```php
  // Improved approach
  $hashed = password_hash($_POST['password'], PASSWORD_BCRYPT, ['cost' => 12]);
  ```

- **CSRF Protection**: No form token implementation
  - **Improvement**: Add CSRF token validation to each form
  ```php
  // Generate token
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  // Verify token
  if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) die('CSRF');
  ```

- **Rate Limiting**: No login attempt limits
  - Improvement: Implement login failure counter and IP blacklist

- **Input Validation**: Basic validation, can be strengthened
  - Improvement: Add server-side length, format, character set validation

---

## Data Flow

### Task Creation Flow
```
User fills out add task form
  ↓
JavaScript validates form
  ↓
POST request to todo.php (action=add_task)
  ↓
PHP backend validates and sanitizes input
  ↓
Check category validity, set default category
  ↓
Execute INSERT statement to tasks table
  ↓
Reload page or AJAX update
  ↓
New task appears in UI
```

### Task Notes Update Flow
```
User edits notes in overlay
  ↓
Click save or Ctrl+S
  ↓
AJAX POST request (action=update_task_notes)
  ↓
PHP backend updates tasks.notes field
  ↓
Returns JSON response { success: true, notes: '...' }
  ↓
JavaScript updates DOM (no refresh)
  ↓
Display success message
```

### Session Protection Flow
```
User accesses todo.php
  ↓
require_once 'auth.php'
  ↓
Call require_login()
  ↓
Check if $_SESSION['user_id'] exists
  ↓
If not exists, redirect to index.php
  ↓
If exists, load user-specific data
  ↓
Display protected dashboard
```

---

## Tech Stack

### Backend
- **PHP** >= 7.4 - Server-side logic
- **MySQL** 8.0 - Relational database
- **MySQLi** - Database driver with prepared statements
- **Composer** - Dependency management

### Frontend
- **Vanilla JavaScript** - 3000+ lines of custom code
- **HTML5** & **CSS3** - Semantic markup and modern styling
- **AJAX** - Asynchronous data updates

### Libraries
- **PHPMailer** - SMTP email functionality
- **vlucas/phpdotenv** - Environment variable management
- **Google OAuth 2.0** - Third-party authentication

---

## Contributing

Issues and Pull Requests are welcome!

1. Fork this project
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

---

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details

---

## Author

Developer: **Your Name**  
Feel free to contact or open an issue if you have questions or suggestions!

---

## Acknowledgments

- PHPMailer team for excellent email functionality
- Google OAuth 2.0 integration references
- All open-source community contributors

---

## Screenshots

> Recommended to add application screenshots showcasing main features
