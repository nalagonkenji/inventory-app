-- Run once on the VM: sudo mariadb inventory_db < categories.sql
CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE,
  description VARCHAR(255) DEFAULT '',
  status VARCHAR(10) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
-- Load the categories already used by parts
INSERT IGNORE INTO categories (name)
  SELECT DISTINCT category FROM parts WHERE category <> '';
