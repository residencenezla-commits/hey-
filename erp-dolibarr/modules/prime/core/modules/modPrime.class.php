<?php
/*
 * Module « Prime de rendement DPR AXXAM » : fiche journalière du poste saisie par le chef d'équipe,
 * contrôlée et validée par le contrôleur, prime calculée automatiquement
 * (objectif 10 wagons dépilés, 1 500 DA par wagon au-delà, partagés entre les présents),
 * état mensuel par employé pour la comptabilité et la paie.
 * SARL DPR AXXAM.
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modPrime extends DolibarrModules
{
	public function __construct($db)
	{
		$this->db = $db;
		$this->numero = 500230;
		$this->rights_class = 'prime';
		$this->family = 'hr';
		$this->module_position = '92';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "Prime de rendement des équipes de production : fiches journalières, contrôle, calcul automatique, état mensuel pour la paie.";
		$this->version = '1.0.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'salary';
		$this->module_parts = array();
		$this->depends = array();
		$this->config_page_url = array();
		$this->langfiles = array();
		// règle de la prime (modifiable dans l'onglet Paramètres)
		$this->const = array(
			array('PRIME_OBJECTIF', 'chaine', '10', 'Wagons dépilés à atteindre par équipe et par poste', 0, 'current', 0),
			array('PRIME_PAR_WAGON', 'chaine', '1500', 'Prime en DA par wagon au-delà de l\'objectif', 0, 'current', 0),
			array('PRIME_SEUIL_CASSE', 'chaine', '5', 'Seuil de casse et déclassés en %', 0, 'current', 0),
		);

		$this->rights = array();
		$this->rights[] = array(500231, 'Consulter les fiches de prime', 'r', 0, 'lire');
		$this->rights[] = array(500232, 'Saisir la fiche du poste (chef d\'équipe)', 'w', 0, 'saisir');
		$this->rights[] = array(500233, 'Contrôler et valider les fiches (contrôleur)', 'w', 0, 'valider');
		$this->rights[] = array(500234, 'Voir et exporter l\'état mensuel (comptabilité)', 'r', 0, 'etat');
		$this->rights[] = array(500235, 'Gérer personnel, équipes, règle et clôture du mois', 'w', 0, 'gerer');

		$this->menu = array();
		$this->menu[] = array(
			'fk_menu' => '', 'type' => 'top', 'titre' => 'Primes', 'prefix' => img_picto('', 'salary', 'class="pictofixedwidth"'),
			'mainmenu' => 'prime', 'leftmenu' => '', 'url' => '/prime/index.php',
			'langs' => '', 'position' => 1010, 'enabled' => 'isModEnabled("prime")',
			'perms' => '$user->hasRight("prime", "lire") || $user->hasRight("prime", "etat")',
			'target' => '', 'user' => 2,
		);
	}

	public function init($options = '')
	{
		$result = $this->_load_tables('/prime/sql/');
		if ($result < 0) return -1;
		return $this->_init(array(), $options);
	}
}
