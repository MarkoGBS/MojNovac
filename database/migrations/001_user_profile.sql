
ALTER TABLE users
    MODIFY COLUMN name VARCHAR(201) NOT NULL,
    ADD COLUMN first_name VARCHAR(100) NULL AFTER name,
    ADD COLUMN last_name VARCHAR(100) NULL AFTER first_name,
    ADD COLUMN date_of_birth DATE NULL AFTER last_name;
