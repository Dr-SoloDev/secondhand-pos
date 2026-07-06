-- Migration 022: Add branch_id to categories table
-- Categories should be scoped per branch (each branch manages its own stock)

-- Step 1: Add branch_id column (nullable first for data migration)
ALTER TABLE categories ADD COLUMN branch_id INT NULL AFTER id;

-- Step 2: Add foreign key
ALTER TABLE categories ADD CONSTRAINT fk_categories_branch FOREIGN KEY (branch_id) REFERENCES branches(id);

-- Step 3: Migrate existing data - duplicate categories for each active branch
-- Keep original for branch 1, create copies for other branches
INSERT INTO categories (branch_id, name, description, status, stock_kg, price_tier1, price_tier2, price_tier3)
SELECT b.id, c.name, c.description, c.status, c.stock_kg / (SELECT COUNT(*) FROM branches WHERE status = 'active'),
       c.price_tier1, c.price_tier2, c.price_tier3
FROM categories c
CROSS JOIN branches b
WHERE c.branch_id IS NULL AND b.status = 'active';

-- Step 4: Delete old null branch_id rows
DELETE FROM categories WHERE branch_id IS NULL;

-- Step 5: Make branch_id NOT NULL
ALTER TABLE categories MODIFY branch_id INT NOT NULL;

-- Step 6: Add index for performance
CREATE INDEX idx_categories_branch ON categories(branch_id);
