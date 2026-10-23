-- Personnel de production concerné par la prime
CREATE TABLE llx_prime_employe (
  rowid     integer AUTO_INCREMENT PRIMARY KEY,
  entity    integer DEFAULT 1 NOT NULL,
  matricule varchar(32) NULL,
  nom       varchar(128) NOT NULL,
  poste     varchar(64) NULL,          -- dépileur, cariste, emballeur…
  fk_equipe integer NULL,              -- équipe habituelle
  actif     tinyint DEFAULT 1 NOT NULL
) ENGINE=innodb;
