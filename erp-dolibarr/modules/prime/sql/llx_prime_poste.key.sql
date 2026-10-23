ALTER TABLE llx_prime_poste ADD UNIQUE INDEX uk_prime_poste (entity, date_poste, fk_equipe, horaire);
ALTER TABLE llx_prime_poste ADD INDEX idx_prime_poste_date (date_poste);
