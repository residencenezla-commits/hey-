-- Plans de maintenance préventive
CREATE TABLE llx_gmao_plan (
  rowid        integer AUTO_INCREMENT PRIMARY KEY,
  entity       integer DEFAULT 1 NOT NULL,
  fk_equipement integer NOT NULL,
  nom          varchar(255) NOT NULL,
  frequence_j  integer DEFAULT 7 NOT NULL,      -- périodicité en jours
  duree_min    integer NULL,
  checklist    text NULL,                       -- une étape par ligne
  securite     text NULL,
  technicien   varchar(128) NULL,
  derniere     date NULL,                       -- dernière réalisation
  actif        tinyint DEFAULT 1 NOT NULL,
  tms          timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
