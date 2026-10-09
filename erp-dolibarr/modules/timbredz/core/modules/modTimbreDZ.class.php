<?php
/*
 * Module « Timbre DZ » — droit de timbre algérien sur les factures payées en espèces.
 * SARL DPR AXXAM.
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modTimbreDZ extends DolibarrModules
{
	public function __construct($db)
	{
		$this->db = $db;
		$this->numero = 500210;
		$this->rights_class = 'timbredz';
		$this->family = 'financial';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "Droit de timbre algérien calculé automatiquement à la validation des factures payées en espèces (1 % jusqu'à 30 000 DA, 1,5 % jusqu'à 100 000 DA, 2 % au-delà, sur le TTC, arrondi au dinar supérieur), et montant en lettres en dinars algériens sur les factures et devis.";
		$this->version = '1.0.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'bill';
		$this->module_parts = array('triggers' => 1, 'substitutions' => 1);
		$this->depends = array('modFacture');
		$this->config_page_url = array();
		$this->langfiles = array();
		$this->const = array();
		$this->rights = array();
		$this->menu = array();
	}
}
