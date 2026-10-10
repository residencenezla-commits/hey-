-- Alarmes de l'automate (programme de l'usine) reliées aux équipements
CREATE TABLE llx_gmao_alarme (
  rowid        integer AUTO_INCREMENT PRIMARY KEY,
  entity       integer DEFAULT 1 NOT NULL,
  code         varchar(64) NOT NULL,             -- n° ou mnémonique de l'alarme / bit automate
  texte        varchar(255) NOT NULL,
  fk_equipement integer NULL,
  causes       text NULL,                        -- causes probables
  remede       text NULL,                        -- que faire
  tms          timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
