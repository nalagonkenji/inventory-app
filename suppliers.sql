-- Run once on the VM: sudo mariadb inventory_db < suppliers.sql
CREATE TABLE IF NOT EXISTS suppliers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  contact_person VARCHAR(100) DEFAULT '',
  contact_number VARCHAR(30) DEFAULT '',
  email VARCHAR(120) DEFAULT '',
  address VARCHAR(255) DEFAULT '',
  categories VARCHAR(255) DEFAULT '',
  status VARCHAR(10) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
