-- Membres présents sur un poste
CREATE TABLE llx_prime_presence (
  rowid       integer AUTO_INCREMENT PRIMARY KEY,
  fk_poste    integer NOT NULL,
  fk_employe  integer NOT NULL,
  injustifie  tinyint DEFAULT 0 NOT NULL,   -- absence partielle ou retard injustifié : pas de part
  part        decimal(12,2) DEFAULT 0 NOT NULL
) ENGINE=innodb;
