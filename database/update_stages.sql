-- Update stages to use full names
USE micron_tracking;

-- Clear existing stages and insert with full names
TRUNCATE TABLE stages;

INSERT INTO stages (stage_code, stage_name, stage_order) VALUES
('INCOMING', 'Incoming Materials', 1),
('CNC_1', 'CNC Machine 1', 2),
('CNC_2', 'CNC Machine 2', 3),
('BACK_CHAMPER', 'Back Champer', 4),
('BROACH', 'Broach Operations', 5),
('EAR_DRILL', 'Ear Drill', 6),
('EAR_BORE', 'Ear Bore', 7),
('PIN_DRILL', 'Pin Drill', 8),
('SPM_OPERATIONS', 'SPM Operations', 9),
('DRILLING', 'Drilling Operations', 10),
('QUALITY_CHECK', 'Quality Check', 11),
('FINISHED_GOODS', 'Finished Goods', 12);