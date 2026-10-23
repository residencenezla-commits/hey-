<?php
/*
 * Module « Maintenance » (GMAO) : activation et chargement de l'atelier de préparation
 * réel (schémas électriques Marcheluzzo, catalogue MS500 : machines, moteurs, plans, pièces),
 * seulement si la base est vide. Régénérer les fichiers : python3 gmao-horizon/referentiel_reel.py
 * Relancer à la main :  docker compose exec web php /var/www/scripts/docker-init.d/40-maintenance.php
 * Pour importer ensuite le schéma électrique et les alarmes automate : menu Maintenance > Import.
 */
require_once '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

function etape($t) { print "Maintenance : $t\n"; }
$user = new User($db);
if ($user->fetch(0, getenv('DOLI_ADMIN_LOGIN') ?: 'admin') <= 0) { etape('administrateur introuvable'); exit(1); }
$user->loadRights();

$r = activateModule('modGmao');
if (!empty($r['errors'])) { etape('ERREUR activation : '.implode(' ', $r['errors'])); exit(1); }
etape('module activé (menu « Maintenance »)');

$P = MAIN_DB_PREFIX; $E = (int) $conf->entity;
$o = $db->fetch_object($db->query("SELECT COUNT(*) AS n FROM {$P}gmao_equipement WHERE entity = $E"));
if ($o && $o->n > 0) { etape("base déjà remplie ({$o->n} équipements) : rien à charger"); exit(0); }

$dir = '/var/www/html/custom/gmao/referentiel/';
require_once '/var/www/html/custom/gmao/lib/import.lib.php';
$r = gmao_importer_equipements($db, file_get_contents($dir.'equipements_preparation.csv'), $E);
etape("{$r['crees']} équipements chargés (schémas électriques E5, E5A, E5B, E4A + cercleuse MS500)".($r['erreurs'] ? ' ; '.implode(' ; ', $r['erreurs']) : ''));

$id = function ($tag) use ($db, $P, $E) {
	$o = $db->fetch_object($db->query("SELECT rowid FROM {$P}gmao_equipement WHERE tag = '".$db->escape($tag)."' AND entity = $E"));
	return $o ? (int) $o->rowid : 0;
};
$n = 0;
foreach (gmao_lire_csv(file_get_contents($dir.'plans_preparation.csv')) as $l) {
	if (!($eq = $id($l['tag']))) continue;
	$db->query("INSERT INTO {$P}gmao_plan (entity, fk_equipement, nom, frequence_j, duree_min, checklist, securite, derniere, actif) VALUES ($E, $eq, '"
		.$db->escape($l['nom'])."', ".max(1, (int) $l['frequence_j']).", ".((int) $l['duree_min'] ?: 'NULL').", '".$db->escape($l['checklist'])."', '".$db->escape($l['securite'])."', CURDATE(), 1)");
	$n++;
}
etape("$n plans préventifs chargés (première échéance calculée à partir d'aujourd'hui)");
$n = 0;
foreach (gmao_lire_csv(file_get_contents($dir.'pieces_preparation.csv')) as $l) {
	$seuil = preg_match('/^\d+/', $l['seuil_conseille'], $m) ? (int) $m[0] : 0;
	$db->query("INSERT INTO {$P}gmao_piece (entity, nom, reference, fk_equipement, unite, stock, seuil) VALUES ($E, '".$db->escape($l['nom'])."', "
		.($l['reference'] === '' ? 'NULL' : "'".$db->escape($l['reference'])."'").", ".($id($l['tag']) ?: 'NULL').", '".$db->escape($l['unite'])."', 0, $seuil)");
	$n++;
}
etape("$n pièces de rechange chargées (stock à saisir après inventaire)");
