-- Add google_id column
ALTER TABLE users ADD COLUMN google_id VARCHAR(255) UNIQUE DEFAULT NULL;

-- Make password nullable (modify column to allow NULL)
ALTER TABLE users MODIFY COLUMN password VARCHAR(255) NULL;
