ALTER TABLE llx_gmao_intervention ADD INDEX idx_gmao_intervention_equip (fk_equipement);
ALTER TABLE llx_gmao_intervention ADD INDEX idx_gmao_intervention_date (date_debut);
