-- Micron Enterprise Tracking Database Schema
-- Created: October 29, 2025

CREATE DATABASE IF NOT EXISTS micron_tracking;
USE micron_tracking;

-- Users table for authentication
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'po_creator', 'operator', 'client') NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    machine_line VARCHAR(50) NULL, -- For operators
    stage_access VARCHAR(100) NULL, -- Comma separated stages for operators
    client_company VARCHAR(100) NULL, -- For clients
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Manufacturing stages
CREATE TABLE stages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    stage_code VARCHAR(10) UNIQUE NOT NULL,
    stage_name VARCHAR(50) NOT NULL,
    stage_order INT NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default stages
INSERT INTO stages (stage_code, stage_name, stage_order) VALUES
('IN', 'Incoming', 1),
('C1', 'CNC-1', 2),
('C2', 'CNC-2', 3),
('BC', 'Back Champer', 4),
('B1', 'Broach', 5),
('ED', 'Ear Drill', 6),
('EB', 'Ear Bore', 7),
('PD', 'Pin Drill', 8),
('SPM', 'SPM Operations', 9),
('DRILL', 'Drilling', 10),
('QC', 'Quality Check', 11),
('FG', 'Finished Goods', 12);

-- Parts master
CREATE TABLE parts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    part_number VARCHAR(50) UNIQUE NOT NULL,
    part_name VARCHAR(100) NOT NULL,
    series VARCHAR(20) NOT NULL,
    material VARCHAR(50) NULL,
    description TEXT NULL,
    weight_grams DECIMAL(8,2) NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Clients
CREATE TABLE clients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_code VARCHAR(20) UNIQUE NOT NULL,
    company_name VARCHAR(100) NOT NULL,
    contact_person VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NULL,
    address TEXT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Purchase Orders
CREATE TABLE purchase_orders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    po_number VARCHAR(50) UNIQUE NOT NULL,
    client_id INT NOT NULL,
    po_date DATE NOT NULL,
    delivery_date DATE NULL,
    status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
    total_value DECIMAL(12,2) DEFAULT 0,
    notes TEXT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- PO Items
CREATE TABLE po_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    po_id INT NOT NULL,
    part_id INT NOT NULL,
    quantity_ordered INT NOT NULL,
    quantity_completed INT DEFAULT 0,
    unit_price DECIMAL(10,2) DEFAULT 0,
    total_price DECIMAL(12,2) DEFAULT 0,
    status ENUM('pending', 'in_progress', 'completed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (part_id) REFERENCES parts(id)
);

-- Bins
CREATE TABLE bins (
    id INT PRIMARY KEY AUTO_INCREMENT,
    bin_code VARCHAR(20) UNIQUE NOT NULL,
    barcode VARCHAR(50) UNIQUE NOT NULL,
    max_capacity INT DEFAULT 250,
    current_quantity INT DEFAULT 0,
    current_part_id INT NULL,
    current_stage_id INT NULL,
    status ENUM('empty', 'in_use', 'maintenance') DEFAULT 'empty',
    last_updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (current_part_id) REFERENCES parts(id),
    FOREIGN KEY (current_stage_id) REFERENCES stages(id),
    FOREIGN KEY (last_updated_by) REFERENCES users(id)
);

-- Bin Movements/History
CREATE TABLE bin_movements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    bin_id INT NOT NULL,
    po_item_id INT NOT NULL,
    part_id INT NOT NULL,
    from_stage_id INT NULL,
    to_stage_id INT NOT NULL,
    quantity_in INT NOT NULL,
    quantity_out INT DEFAULT 0,
    quantity_rejected INT DEFAULT 0,
    quantity_rework INT DEFAULT 0,
    rejection_reason VARCHAR(100) NULL,
    rework_reason VARCHAR(100) NULL,
    operator_id INT NOT NULL,
    movement_type ENUM('incoming', 'transfer', 'completion') NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bin_id) REFERENCES bins(id),
    FOREIGN KEY (po_item_id) REFERENCES po_items(id),
    FOREIGN KEY (part_id) REFERENCES parts(id),
    FOREIGN KEY (from_stage_id) REFERENCES stages(id),
    FOREIGN KEY (to_stage_id) REFERENCES stages(id),
    FOREIGN KEY (operator_id) REFERENCES users(id)
);

-- Rejection reasons master
CREATE TABLE rejection_reasons (
    id INT PRIMARY KEY AUTO_INCREMENT,
    reason_code VARCHAR(10) UNIQUE NOT NULL,
    reason_description VARCHAR(100) NOT NULL,
    stage_id INT NULL, -- NULL means applicable to all stages
    is_active BOOL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (stage_id) REFERENCES stages(id)
);

-- Insert common rejection reasons
INSERT INTO rejection_reasons (reason_code, reason_description) VALUES
('DIM', 'Dimensional Issue'),
('SURF', 'Surface Defect'),
('CRACK', 'Crack/Damage'),
('TOOL', 'Tool Mark'),
('MAT', 'Material Issue'),
('OPER', 'Operator Error'),
('MACH', 'Machine Issue'),
('OTHER', 'Other - See Notes');

-- Production logs for detailed tracking
CREATE TABLE production_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    po_item_id INT NOT NULL,
    stage_id INT NOT NULL,
    operator_id INT NOT NULL,
    start_time TIMESTAMP NULL,
    end_time TIMESTAMP NULL,
    quantity_processed INT NOT NULL,
    quantity_passed INT NOT NULL,
    quantity_rejected INT DEFAULT 0,
    quantity_rework INT DEFAULT 0,
    machine_time_hours DECIMAL(5,2) DEFAULT 0,
    efficiency_percentage DECIMAL(5,2) DEFAULT 0,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (po_item_id) REFERENCES po_items(id),
    FOREIGN KEY (stage_id) REFERENCES stages(id),
    FOREIGN KEY (operator_id) REFERENCES users(id)
);

-- Generate 1500 bins
DELIMITER //
CREATE PROCEDURE GenerateBins()
BEGIN
    DECLARE i INT DEFAULT 1;
    WHILE i <= 1500 DO
        INSERT INTO bins (bin_code, barcode) 
        VALUES (
            CONCAT('BIN', LPAD(i, 4, '0')), 
            CONCAT('MCN', LPAD(i, 6, '0'))
        );
        SET i = i + 1;
    END WHILE;
END//
DELIMITER ;

CALL GenerateBins();
DROP PROCEDURE GenerateBins;

-- Create indexes for better performance
CREATE INDEX idx_bins_barcode ON bins(barcode);
CREATE INDEX idx_bins_status ON bins(status);
CREATE INDEX idx_movements_bin_stage ON bin_movements(bin_id, to_stage_id);
CREATE INDEX idx_movements_operator ON bin_movements(operator_id, created_at);
CREATE INDEX idx_po_items_status ON po_items(status);
CREATE INDEX idx_production_logs_stage_operator ON production_logs(stage_id, operator_id, created_at);

-- Default admin user (password: admin123)
INSERT INTO users (username, email, password_hash, role, full_name) VALUES 
('admin', 'admin@micronenterprise.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'System Administrator');

-- Sample client
INSERT INTO clients (client_code, company_name, contact_person, email, phone) VALUES 
('CLT001', 'AgriTech Solutions', 'John Smith', 'john@agritech.com', '9876543210');