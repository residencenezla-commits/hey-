-- Pannes, interventions préventives et améliorations
CREATE TABLE llx_gmao_intervention (
  rowid        integer AUTO_INCREMENT PRIMARY KEY,
  entity       integer DEFAULT 1 NOT NULL,
  fk_equipement integer NOT NULL,
  type         varchar(16) DEFAULT 'panne' NOT NULL, -- panne, preventif, amelioration
  date_debut   datetime NOT NULL,
  date_fin     datetime NULL,
  arret_min    integer DEFAULT 0,               -- minutes d'arrêt de production
  symptome     text NULL,
  cause        text NULL,
  action       text NULL,
  pieces       text NULL,                       -- pièces utilisées (texte libre)
  code_alarme  varchar(64) NULL,
  technicien   varchar(128) NULL,
  statut       varchar(16) DEFAULT 'ouverte' NOT NULL, -- ouverte, en_cours, cloturee
  fk_plan      integer NULL,
  fk_user      integer NULL,
  tms          timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
