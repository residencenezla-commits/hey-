-- Mois clôturés et transmis à la comptabilité (plus aucune modification possible)
CREATE TABLE llx_prime_cloture (
  rowid        integer AUTO_INCREMENT PRIMARY KEY,
  entity       integer DEFAULT 1 NOT NULL,
  mois         char(7) NOT NULL,          -- AAAA-MM
  date_cloture datetime NOT NULL,
  fk_user      integer NULL,
  total        decimal(14,2) DEFAULT 0 NOT NULL
) ENGINE=innodb;
