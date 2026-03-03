<?php
require_once 'db.php';

try {
    // Check if column exists
    $result = $mysqli->query("SHOW COLUMNS FROM categories LIKE 'color'");
    if ($result->num_rows === 0) {
        // Add column
                $sql = "ALTER TABLE categories ADD COLUMN color VARCHAR(9) NOT NULL DEFAULT '#5C6CFF'";
        if ($mysqli->query($sql)) {
            echo "Successfully added 'color' column to 'categories' table.\n";
        } else {
            echo "Error adding column: " . $mysqli->error . "\n";
        }
    } else {
        echo "Column 'color' already exists.\n";
    }
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
?>