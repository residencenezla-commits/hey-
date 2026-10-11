<?php
/*
 * Module « Prime de rendement » : activation, équipes de départ et groupes d'utilisateurs.
 *   Chefs d'équipe : saisir la fiche du poste
 *   Contrôleurs    : saisir, contrôler et valider les fiches
 *   Comptabilité   : état mensuel des primes (impression, Excel)
 *   Direction      : tout, plus personnel, règle et clôture du mois
 * Il suffit ensuite de mettre chaque utilisateur dans son groupe (Utilisateurs & groupes).
 * Relancer à la main :  docker compose exec web php /var/www/scripts/docker-init.d/50-primes.php
 */
require_once '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/usergroup.class.php';

function etape($t) { print "Primes : $t\n"; }
$user = new User($db);
if ($user->fetch(0, getenv('DOLI_ADMIN_LOGIN') ?: 'admin') <= 0) { etape('administrateur introuvable'); exit(1); }
$user->loadRights();

$r = activateModule('modPrime');
if (!empty($r['errors'])) { etape('ERREUR activation : '.implode(' ', $r['errors'])); exit(1); }
etape('module activé (menu « Primes »)');

$P = MAIN_DB_PREFIX; $E = (int) $conf->entity;
$o = $db->fetch_object($db->query("SELECT COUNT(*) AS n FROM {$P}prime_equipe WHERE entity = $E"));
if ($o && !$o->n) {
	foreach (array('Équipe A', 'Équipe B', 'Équipe C') as $n) $db->query("INSERT INTO {$P}prime_equipe (entity, nom) VALUES ($E, '".$db->escape($n)."')");
	etape('équipes A, B, C créées (à renommer au besoin dans l\'onglet Personnel)');
}

// 500231 lire, 500232 saisir, 500233 valider, 500234 etat, 500235 gerer
$groupes = array(
	"Chefs d'équipe" => array(500231, 500232),
	'Contrôleurs' => array(500231, 500232, 500233),
	'Comptabilité' => array(500234),
	'Direction' => array(500231, 500232, 500233, 500234, 500235),
);
foreach ($groupes as $nom => $droits) {
	$g = new UserGroup($db);
	if ($g->fetch(0, $nom) <= 0) {
		$g->name = $nom; $g->nom = $nom; $g->entity = $E;
		$g->note = 'Prime de rendement';
		if ($g->create($user) <= 0) { etape("ERREUR groupe $nom : ".$g->error); continue; }
	}
	foreach ($droits as $d) $g->addrights($d, '', '', $E);
	etape("groupe « $nom » prêt");
}
