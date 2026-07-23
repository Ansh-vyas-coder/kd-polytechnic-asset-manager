-- 1. USERS TABLE (The foundation for the Login Page)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'staff') DEFAULT 'staff',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. INSERT THE TWO SPECIFIC USERS
-- Note: We are inserting plain text for the password right now just to test the connection.
-- When we write the PHP API, we will upgrade these to securely encrypted hashes.
-- UPDATE: The login.php script uses password_verify(), so we MUST insert hashed passwords.
-- The following hashes are for the password 'password123'.
INSERT INTO users (full_name, email, password_hash, role) VALUES 
('Head of Department', 'hod@example.com', '$2y$10$nys6AerBbkB55pLN/w/6TOvntua69gptIIqrhnVVO0m.UrHxafoh.', 'admin'),
('Lab Incharge Mam', 'incharge@example.com', '$2y$10$nys6AerBbkB55pLN/w/6TOvntua69gptIIqrhnVVO0m.UrHxafoh.', 'staff');

-- 3. ASSETS TABLE (For the Add Item & Dashboard pages)
CREATE TABLE IF NOT EXISTS assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_name VARCHAR(255) NOT NULL,
    category_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    item_no INT NOT NULL,
    asset_no VARCHAR(255) NULL,
    page_no VARCHAR(100),
    gem_order_no VARCHAR(100),
    gpr_no VARCHAR(100),
    pr_page_no VARCHAR(100),
    gpr_item_no VARCHAR(100),
    batch_id VARCHAR(255),
    gem_invoice_no VARCHAR(100),
    cost DECIMAL(10, 2) NOT NULL,
    location VARCHAR(100),
    date_of_issue DATE NOT NULL,
    assigned_to VARCHAR(100),
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- added 5 
-- Run these once if you already have an older assets table.
ALTER TABLE assets
    ADD COLUMN IF NOT EXISTS asset_no VARCHAR(255) NULL AFTER item_no;

ALTER TABLE assets
    MODIFY COLUMN item_no INT NOT NULL;

-- Optional: add a unique index for the generated asset number if your database does not already have one.
-- Uncomment the next line if you want strict uniqueness on asset_no.
ALTER TABLE assets
    ADD COLUMN IF NOT EXISTS product_no INT NULL AFTER item_no,
    ADD COLUMN IF NOT EXISTS total_quantity INT NULL AFTER product_no;
