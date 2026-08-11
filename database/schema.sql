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
    unit VARCHAR(20) NOT NULL DEFAULT 'pcs',
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
--4
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

-- 4. NOTIFICATIONS TABLE (For the new notification system)
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message VARCHAR(255) NOT NULL,
    link VARCHAR(255) NULL,
    is_read BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX user_id_index (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
ALTER TABLE assets 
    ADD COLUMN status varchar(50) DEFAULT 'active' AFTER cost,
    ADD COLUMN retire_at timestamp NULL DEFAULT NULL AFTER location;
    
-- 5. ASSET STATUS REPORTS TABLE (Standalone staff issue reporting flow)
ALTER TABLE assets
    ADD COLUMN IF NOT EXISTS status_marked_by VARCHAR(100) NULL AFTER status,
    ADD COLUMN IF NOT EXISTS status_marked_role ENUM('admin', 'staff') NULL AFTER status_marked_by,
    ADD COLUMN IF NOT EXISTS status_marked_at TIMESTAMP NULL DEFAULT NULL AFTER status_marked_role,
    ADD COLUMN IF NOT EXISTS status_marked_note TEXT NULL AFTER status_marked_at;


CREATE TABLE IF NOT EXISTS asset_status_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    category_id INT NOT NULL,
    asset_name VARCHAR(255) NOT NULL,
    batch_id VARCHAR(255) NOT NULL,
    reported_by_user_id INT NOT NULL,
    reported_by_name VARCHAR(100) NOT NULL,
    reported_by_role ENUM('admin', 'staff') NOT NULL,
    reported_assigned_to VARCHAR(100) NULL,
    reported_status ENUM('Not Working', 'Missing', 'Under Maintenance') NOT NULL,
    note TEXT NULL,
    reported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by_user_id INT NULL,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    review_status ENUM('pending', 'reviewed', 'resolved') NOT NULL DEFAULT 'pending',
    review_note TEXT NULL,
    INDEX asset_id_idx (asset_id),
    INDEX batch_id_idx (batch_id),
    INDEX review_status_idx (review_status),
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    FOREIGN KEY (reported_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);
--6
ALTER TABLE assets
    ADD COLUMN IF NOT EXISTS transfer_to VARCHAR(100) NULL AFTER assigned_to,
    ADD COLUMN IF NOT EXISTS transferred BOOLEAN NOT NULL DEFAULT FALSE AFTER transfer_to,
    ADD COLUMN IF NOT EXISTS transfer_date TIMESTAMP NULL DEFAULT NULL AFTER transferred;

-- 7. LOAN COLUMNS
ALTER TABLE assets
    ADD COLUMN IF NOT EXISTS loan_to VARCHAR(100) NULL AFTER transfer_to,
    ADD COLUMN IF NOT EXISTS loan_date TIMESTAMP NULL DEFAULT NULL AFTER loan_to,
    ADD COLUMN IF NOT EXISTS return_date TIMESTAMP NULL DEFAULT NULL AFTER loan_date;

-- 8. AUDIT TABLES
-- Table to store main audit session details
CREATE TABLE IF NOT EXISTS audits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    location_id VARCHAR(100) NOT NULL,
    audited_by_user_id INT NOT NULL,
    audit_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('In Progress', 'Completed') NOT NULL DEFAULT 'In Progress',
    FOREIGN KEY (audited_by_user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Table to store the status of each item within an audit
CREATE TABLE IF NOT EXISTS audit_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    audit_id INT NOT NULL,
    asset_id INT NOT NULL,
    expected_location_id VARCHAR(100) NOT NULL,
    scanned_location_id VARCHAR(100) NULL,
    verification_status ENUM('Present', 'Missing', 'Misplaced') NOT NULL,
    `condition` ENUM('Good', 'Needs Repair', 'Broken', 'Scrap') NULL DEFAULT 'Good',
    note TEXT NULL,
    FOREIGN KEY (audit_id) REFERENCES audits(id) ON DELETE CASCADE,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
);

-- 9. UNIT COLUMN (for quantity measurement)
ALTER TABLE assets
    ADD COLUMN IF NOT EXISTS unit VARCHAR(20) NOT NULL DEFAULT 'pcs' AFTER quantity;