-- Pièces de rechange de maintenance
CREATE TABLE llx_gmao_piece (
  rowid        integer AUTO_INCREMENT PRIMARY KEY,
  entity       integer DEFAULT 1 NOT NULL,
  reference    varchar(128) NULL,
  nom          varchar(255) NOT NULL,
  fk_equipement integer NULL,
  unite        varchar(16) DEFAULT 'pièce',
  stock        double(12,2) DEFAULT 0,
  seuil        double(12,2) DEFAULT 0,
  emplacement  varchar(128) NULL,                -- casier du magasin
  fournisseur  varchar(255) NULL,
  cout         double(14,2) NULL,
  tms          timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
