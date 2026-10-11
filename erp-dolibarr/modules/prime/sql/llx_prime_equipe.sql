-- Équipes de production (A, B, C…)
CREATE TABLE llx_prime_equipe (
  rowid   integer AUTO_INCREMENT PRIMARY KEY,
  entity  integer DEFAULT 1 NOT NULL,
  nom     varchar(64) NOT NULL,
  actif   tinyint DEFAULT 1 NOT NULL
) ENGINE=innodb;
