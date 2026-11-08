-- Lot 4a, relecture : l'évènement « renommé » garde le nouveau nom (« X a renommé le groupe en … »).
ALTER TABLE chat_events ADD COLUMN detail VARCHAR(80) NULL AFTER target_user_id;
