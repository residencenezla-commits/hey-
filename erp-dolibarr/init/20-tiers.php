<?php
/*
 * Import des clients et fournisseurs de SARL DPR AXXAM (liste selon le plan comptable SCF).
 *
 * Lit init/tiers-dpr-axxam.csv (produit par outils/convertir_tiers.py).
 * Exécuté au premier démarrage ; peut être relancé sans doublons (un tiers déjà
 * présent avec le même code est mis à jour, pas recréé) :
 *   docker compose exec web php /var/www/scripts/docker-init.d/20-tiers.php
 *
 * Chaque tiers garde son code PC Compta comme code client ou fournisseur, et
 * comme compte auxiliaire ; le compte général (41100, 40101…) est renseigné.
 * Les soldes comptables au 08/10/2026 sont recopiés dans la note privée, pour information.
 */

require_once '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

$fichier = __DIR__.'/tiers-dpr-axxam.csv';
if (!is_readable($fichier)) { print "Tiers : fichier $fichier absent, import ignoré.\n"; exit(0); }

$user = new User($db);
if ($user->fetch(0, getenv('DOLI_ADMIN_LOGIN') ?: 'admin') <= 0) { print "Tiers : administrateur introuvable.\n"; exit(1); }
$user->loadRights();

// Codes clients/fournisseurs libres : on garde les codes de PC Compta
dolibarr_set_const($db, 'SOCIETE_CODECLIENT_ADDON', 'mod_codeclient_leopard', 'chaine', 0, '', $conf->entity);
$conf->global->SOCIETE_CODECLIENT_ADDON = 'mod_codeclient_leopard';

// Une catégorie par compte du plan comptable
$libelles = array(
	'41100' => array(Categorie::TYPE_CUSTOMER, '41100 · Clients'),
	'41900' => array(Categorie::TYPE_CUSTOMER, '41900 · Clients créditeurs (avances reçues)'),
	'41901' => array(Categorie::TYPE_CUSTOMER, '41901 · Clients créditeurs (avances reçues)'),
	'40100' => array(Categorie::TYPE_SUPPLIER, '40100 · Fournisseurs étrangers'),
	'40101' => array(Categorie::TYPE_SUPPLIER, '40101 · Fournisseurs locaux'),
	'40130' => array(Categorie::TYPE_SUPPLIER, '40130 · Fournisseurs de services'),
	'40400' => array(Categorie::TYPE_SUPPLIER, "40400 · Fournisseurs d'immobilisations"),
	'40801' => array(Categorie::TYPE_SUPPLIER, '40801 · Fournisseurs, factures non parvenues'),
	'40900' => array(Categorie::TYPE_SUPPLIER, '40900 · Fournisseurs débiteurs (avances versées)'),
	'40901' => array(Categorie::TYPE_SUPPLIER, '40901 · Fournisseurs débiteurs (avances versées)'),
);
$categorie = array();
foreach ($libelles as $compte => list($type, $libelle)) {
	$c = new Categorie($db);
	if ($c->fetch(0, $libelle, $type) <= 0) {
		$c->label = $libelle; $c->type = $type;
		if ($c->create($user) <= 0) { print "Tiers : ERREUR catégorie $libelle : ".$c->error."\n"; continue; }
	}
	$categorie[$compte] = $c;
}

$h = fopen($fichier, 'r');
$entete = fgetcsv($h, 0, ';', '"', '\\');
$crees = $maj = $erreurs = 0;
while (($ligne = fgetcsv($h, 0, ';', '"', '\\')) !== false) {
	if (count($ligne) != count($entete)) continue;
	$t = array_combine($entete, $ligne);

	$s = new Societe($db);
	$code = $db->escape($t['code']);
	$res = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE entity IN (".getEntity('societe').") AND (code_client = '".$code."' OR code_fournisseur = '".$code."')");
	$obj = $res ? $db->fetch_object($res) : null;
	$existe = $obj && $s->fetch($obj->rowid) > 0;

	$s->name = $t['nom'];
	$s->client = (int) $t['client'];
	$s->fournisseur = (int) $t['fournisseur'];
	if ($t['client']) {
		$s->code_client = $t['code'];
		$s->code_compta_client = $t['code'];
		$s->accountancy_code_customer_general = $t['compte_client'];
	}
	if ($t['fournisseur']) {
		$s->code_fournisseur = $t['code'];
		$s->code_compta_fournisseur = $t['code'];
		$s->accountancy_code_supplier_general = $t['compte_fournisseur'];
	}
	$s->address = $t['adresse'];
	$s->town = $t['ville'];
	$s->country_id = $t['etranger'] ? 0 : 13;
	$s->idprof1 = $t['rc'];   // Algérie : RC
	$s->idprof2 = $t['nif'];  // NIF
	$s->idprof3 = $t['ai'];   // Article d'imposition
	$s->tva_assuj = 1;
	$s->status = 1;
	$s->note_private = "Repris de la comptabilité (PC Compta), situation au 08/10/2026 :\n".str_replace(' | ', "\n", $t['soldes']);
	$s->import_key = 'PCCOMPTA2026';

	$res = $existe ? $s->update($s->id, $user, 1, 0, 0, 'update', 1) : $s->create($user);
	if ($res < 0) { $erreurs++; print "Tiers : ERREUR {$t['code']} {$t['nom']} : ".$s->error.' '.implode(' ', $s->errors)."\n"; continue; }
	$existe ? $maj++ : $crees++;

	foreach (explode(',', $t['comptes']) as $compte) {
		if (isset($categorie[$compte])) {
			$categorie[$compte]->add_type($s, $libelles[$compte][0]);
		}
	}
}
fclose($h);
print "Tiers : $crees créés, $maj mis à jour, $erreurs erreurs.\n";
