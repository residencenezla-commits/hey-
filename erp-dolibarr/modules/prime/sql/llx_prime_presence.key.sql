ALTER TABLE llx_prime_presence ADD UNIQUE INDEX uk_prime_presence (fk_poste, fk_employe);
ALTER TABLE llx_prime_presence ADD INDEX idx_prime_presence_employe (fk_employe);
