ALTER TABLE support_tickets
    ADD COLUMN reply TEXT NULL AFTER message,
    ADD COLUMN replied_by INT UNSIGNED NULL AFTER reply,
    ADD COLUMN replied_at TIMESTAMP NULL DEFAULT NULL AFTER replied_by,
    ADD CONSTRAINT fk_ticket_reply_user FOREIGN KEY (replied_by) REFERENCES users(id) ON DELETE SET NULL;
