-- Fiche journalière d'un poste : saisie par le chef d'équipe, contrôlée et validée par le contrôleur
CREATE TABLE llx_prime_poste (
  rowid        integer AUTO_INCREMENT PRIMARY KEY,
  entity       integer DEFAULT 1 NOT NULL,
  date_poste   date NOT NULL,
  fk_equipe    integer NOT NULL,
  horaire      varchar(32) DEFAULT '' NOT NULL,   -- ex. 6h-14h
  chef         varchar(128) NULL,
  brique       varchar(32) NULL,
  wagons       integer DEFAULT 0 NOT NULL,        -- wagons dépilés (base de la prime)
  chariots     integer NULL,                      -- autres chiffres de la fiche (information)
  wagons_charges integer NULL,
  enfournes    integer NULL,
  defournes    integer NULL,
  paquets      integer NULL,
  casse_pct    decimal(5,2) NULL,                 -- casse et déclassés en %
  ctrl_paquets tinyint DEFAULT 0 NOT NULL,        -- paquets bien présentés et cerclés
  ctrl_securite tinyint DEFAULT 0 NOT NULL,
  ctrl_proprete tinyint DEFAULT 0 NOT NULL,
  statut       varchar(12) DEFAULT 'saisi' NOT NULL, -- saisi, valide, refuse
  motif        text NULL,
  objectif     integer DEFAULT 10 NOT NULL,       -- règle en vigueur le jour du poste
  prix_wagon   decimal(12,2) DEFAULT 1500 NOT NULL,
  seuil_casse  decimal(5,2) DEFAULT 5 NOT NULL,
  prime        decimal(12,2) DEFAULT 0 NOT NULL,  -- prime de l'équipe (calculée)
  presents     integer DEFAULT 0 NOT NULL,        -- présents ayant droit (calculé)
  part         decimal(12,2) DEFAULT 0 NOT NULL,  -- part par personne (calculée)
  fk_user_saisie integer NULL,
  fk_user_valid  integer NULL,
  date_valid   datetime NULL,
  tms          timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
