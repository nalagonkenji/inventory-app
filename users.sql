-- Run once on the VM: sudo mariadb inventory_db < users.sql
ALTER TABLE users ADD COLUMN IF NOT EXISTS full_name VARCHAR(100) DEFAULT '';
ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(120) DEFAULT '';
ALTER TABLE users ADD COLUMN IF NOT EXISTS role VARCHAR(10) NOT NULL DEFAULT 'staff';
ALTER TABLE users ADD COLUMN IF NOT EXISTS status VARCHAR(10) NOT NULL DEFAULT 'active';
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login DATETIME NULL;
-- Make your own account an administrator. Replace kenjiboy with the username you log in with.
UPDATE users SET role = 'admin' WHERE username = 'kenjiboy';
