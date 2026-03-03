
-- inserts 10 records
-- 1 user + 3 categories + 6 tasks = 10 records

CREATE DATABASE IF NOT EXISTS Scheduler
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE Scheduler;

DROP TABLE IF EXISTS tasks;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NULL,
    google_id VARCHAR(255) UNIQUE DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    color VARCHAR(9) NOT NULL DEFAULT '#5C6CFF',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    deadline DATE NULL,
    is_done TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- demo
INSERT INTO users (id, username, password) VALUES
(1, 'demo', 'demo123');

-- 4 categories (default "None")
INSERT INTO categories (id, user_id, name, is_default, color) VALUES
(1, 1, 'None', 1, '#94a3b8'),
(2, 1, 'School', 0, '#5C6CFF'),
(3, 1, 'Personal', 0, '#760bd3ff'),
(4, 1, 'Shopping', 0, '#f02800ff');

-- 6 tasks
INSERT INTO tasks (id, user_id, category_id, title, deadline, is_done, notes) VALUES
(1, 1, 2, 'Finish Scheduler description reading', '2025-11-28', 0, 'Oh no this is so much work!'),
(2, 1, 2, 'Design database schema', '2025-12-03', 0, 'Double-check foreign keys.'),
(3, 1, 2, 'Implement ML Homework', '2025-12-07', 0, 'Do it sooner.'),
(4, 1, 3, 'Buy groceries', NULL, 0, 'Milk, eggs, pasta, and fruit.'),
(5, 1, 3, 'Weekend movie with friends', '2025-12-16', 0, 'Check new releases on Friday.'),
(6, 1, 1, 'Random uncategorized task', NULL, 1, NULL);
