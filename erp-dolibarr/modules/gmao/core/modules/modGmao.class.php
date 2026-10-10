<?php
/*
 * Module « Maintenance DPR AXXAM » (GMAO) : machines et moteurs de l'usine avec leurs repères
 * du schéma électrique, pannes, maintenance préventive, pièces de rechange, alarmes automate.
 * SARL DPR AXXAM.
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modGmao extends DolibarrModules
{
	public function __construct($db)
	{
		$this->db = $db;
		$this->numero = 500220;
		$this->rights_class = 'gmao';
		$this->family = 'products';
		$this->module_position = '91';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "Maintenance de l'usine : machines, moteurs et repères du schéma électrique, pannes, préventif, pièces, alarmes automate.";
		$this->version = '1.0.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'technic';
		$this->module_parts = array();
		$this->depends = array();
		$this->config_page_url = array();
		$this->langfiles = array();
		$this->const = array();

		$this->rights = array();
		$this->rights[] = array(500221, 'Consulter la maintenance', 'r', 1, 'lire');
		$this->rights[] = array(500222, 'Déclarer pannes et interventions', 'w', 1, 'ecrire');
		$this->rights[] = array(500223, 'Gérer machines, plans, pièces et alarmes', 'w', 0, 'gerer');

		$this->menu = array();
		$this->menu[] = array(
			'fk_menu' => '', 'type' => 'top', 'titre' => 'Maintenance', 'prefix' => img_picto('', 'technic', 'class="pictofixedwidth"'),
			'mainmenu' => 'gmao', 'leftmenu' => '', 'url' => '/gmao/index.php',
			'langs' => '', 'position' => 1000, 'enabled' => 'isModEnabled("gmao")', 'perms' => '$user->hasRight("gmao", "lire")',
			'target' => '', 'user' => 2,
		);
	}

	public function init($options = '')
	{
		$result = $this->_load_tables('/gmao/sql/');
		if ($result < 0) return -1;
		return $this->_init(array(), $options);
	}
}
