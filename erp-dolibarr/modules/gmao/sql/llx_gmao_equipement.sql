-- GMAO DPR AXXAM : arborescence site > atelier > zone > machine > moteur / organe
CREATE TABLE llx_gmao_equipement (
  rowid        integer AUTO_INCREMENT PRIMARY KEY,
  entity       integer DEFAULT 1 NOT NULL,
  tag          varchar(64) NOT NULL,           -- identifiant unique (QR) : DPR-PRE-LAM-02-MOT
  nom          varchar(255) NOT NULL,
  type         varchar(16) DEFAULT 'machine' NOT NULL, -- site, atelier, zone, machine, moteur, organe, armoire
  fk_parent    integer NULL,
  armoire      varchar(64) NULL,                -- armoire / coffret électrique
  repere       varchar(128) NULL,               -- repères du schéma : QM051, KM12, variateur DP57 / INM033
  puissance_kw double(10,2) NULL,
  vitesse      varchar(32) NULL,                -- tr/min, ou réglage variateur (Hz)
  folio        varchar(64) NULL,                -- page / folio du schéma électrique
  criticite    char(1) DEFAULT 'B',             -- A arrêt ligne, B gêne, C sans impact
  fabricant    varchar(128) NULL,
  modele       varchar(128) NULL,
  numserie     varchar(128) NULL,
  statut       varchar(16) DEFAULT 'marche' NOT NULL, -- marche, panne, arret, rebut
  notes        text NULL,
  date_creation datetime NULL,
  tms          timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
