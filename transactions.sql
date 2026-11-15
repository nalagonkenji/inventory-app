-- Run once on the VM: sudo mariadb inventory_db < transactions.sql
CREATE TABLE IF NOT EXISTS transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  part_id INT NOT NULL,
  type VARCHAR(10) NOT NULL,
  quantity INT NOT NULL,
  performed_by VARCHAR(30) NOT NULL,
  note VARCHAR(255) DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE CASCADE
);
