ALTER TABLE llx_gmao_equipement ADD UNIQUE INDEX uk_gmao_equipement_tag (tag, entity);
ALTER TABLE llx_gmao_equipement ADD INDEX idx_gmao_equipement_parent (fk_parent);
