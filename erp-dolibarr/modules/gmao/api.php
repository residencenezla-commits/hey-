<?php
/*
 * GMAO DPR AXXAM — API JSON utilisée par index.php.
 * Connexion obligatoire (session Dolibarr) ; droits : gmao lire / ecrire / gerer.
 */

if (!defined('NOREQUIREMENU')) define('NOREQUIREMENU', 1);
if (!defined('NOREQUIREHTML')) define('NOREQUIREHTML', 1);
if (!defined('NOREQUIREAJAX')) define('NOREQUIREAJAX', 1);
if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', 1); // le jeton de la page reste valable pour tous les appels

$res = 0;
foreach (array('../../main.inc.php', '../../../main.inc.php', '../../../../main.inc.php') as $f) {
	if (!$res && file_exists(__DIR__.'/'.$f)) $res = @include __DIR__.'/'.$f;
}
if (!$res) { http_response_code(500); die('main.inc.php introuvable'); }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function repondre($donnees, $code = 200)
{
	http_response_code($code);
	echo json_encode($donnees, JSON_UNESCAPED_UNICODE);
	exit;
}
function erreur($message, $code = 400) { repondre(array('erreur' => $message), $code); }
function exiger($droit)
{
	global $user;
	if (!$user->hasRight('gmao', $droit) && !$user->admin) erreur('Droit insuffisant : '.$droit, 403);
}
function txt($k, $max = 65000)
{
	$v = GETPOST($k, 'restricthtml');
	$v = is_string($v) ? trim($v) : '';
	return mb_substr($v, 0, $max);
}
function sqlv($db, $v) { return ($v === '' || $v === null) ? 'NULL' : "'".$db->escape($v)."'"; }
function sqln($v) { return ($v === '' || $v === null || !is_numeric(str_replace(',', '.', $v))) ? 'NULL' : (float) str_replace(',', '.', $v); }
function lignes($db, $sql)
{
	$res = $db->query($sql);
	if (!$res) erreur('Erreur base : '.$db->lasterror(), 500);
	$t = array();
	while ($o = $db->fetch_array($res)) {
		foreach ($o as $k => $v) if (is_int($k)) unset($o[$k]);
		$t[] = $o;
	}
	return $t;
}

exiger('lire');
$P = MAIN_DB_PREFIX;
$E = (int) $conf->entity;
$action = GETPOST('op', 'aZ09');  // « op » et non « action » : la protection CSRF de Dolibarr bloque les GET avec « action »
$methode = $_SERVER['REQUEST_METHOD'];

if ($methode === 'POST') {
	// jeton anti-CSRF de Dolibarr
	$jeton = GETPOST('token', 'alpha');
	if (empty($jeton) || empty($_SESSION['token']) || !hash_equals((string) $_SESSION['token'], (string) $jeton)) {
		if (empty($_SESSION['newtoken']) || !hash_equals((string) $_SESSION['newtoken'], (string) $jeton)) erreur('Session expirée : rechargez la page.', 403);
	}
}

switch ($action) {
	// ------------------------------------------------------------ lecture complète
	case 'tout':
		$eq = lignes($db, "SELECT rowid AS id, tag, nom, type, fk_parent AS parent, armoire, repere, puissance_kw, vitesse, folio, criticite, fabricant, modele, numserie, statut, notes FROM {$P}gmao_equipement WHERE entity = $E ORDER BY tag");
		$plans = lignes($db, "SELECT rowid AS id, fk_equipement AS equipement, nom, frequence_j, duree_min, checklist, securite, technicien, derniere, actif,
			DATE_ADD(COALESCE(derniere, CURDATE() - INTERVAL frequence_j DAY), INTERVAL frequence_j DAY) AS prochaine
			FROM {$P}gmao_plan WHERE entity = $E ORDER BY prochaine");
		$pieces = lignes($db, "SELECT rowid AS id, reference, nom, fk_equipement AS equipement, unite, stock, seuil, emplacement, fournisseur, cout FROM {$P}gmao_piece WHERE entity = $E ORDER BY nom");
		$alarmes = lignes($db, "SELECT rowid AS id, code, texte, fk_equipement AS equipement, causes, remede FROM {$P}gmao_alarme WHERE entity = $E ORDER BY code");
		$ouvertes = lignes($db, "SELECT rowid AS id, fk_equipement AS equipement, type, date_debut, symptome, code_alarme, technicien, statut FROM {$P}gmao_intervention WHERE entity = $E AND statut <> 'cloturee' ORDER BY date_debut DESC");
		$stats = lignes($db, "SELECT COUNT(*) AS pannes, COALESCE(SUM(arret_min), 0) AS arret_min FROM {$P}gmao_intervention WHERE entity = $E AND type = 'panne' AND date_debut >= CURDATE() - INTERVAL 30 DAY");
		$top = lignes($db, "SELECT fk_equipement AS equipement, COUNT(*) AS pannes, COALESCE(SUM(arret_min), 0) AS arret_min FROM {$P}gmao_intervention
			WHERE entity = $E AND type = 'panne' AND date_debut >= CURDATE() - INTERVAL 90 DAY GROUP BY fk_equipement ORDER BY arret_min DESC, pannes DESC LIMIT 8");
		$recentes = lignes($db, "SELECT rowid AS id, fk_equipement AS equipement, type, date_debut, date_fin, arret_min, symptome, cause, action, technicien, statut FROM {$P}gmao_intervention WHERE entity = $E ORDER BY date_debut DESC LIMIT 30");
		repondre(array(
			'equipements' => $eq, 'plans' => $plans, 'pieces' => $pieces, 'alarmes' => $alarmes,
			'ouvertes' => $ouvertes, 'stats30' => $stats[0], 'top90' => $top, 'recentes' => $recentes,
			'droits' => array('ecrire' => $user->admin || $user->hasRight('gmao', 'ecrire'), 'gerer' => $user->admin || $user->hasRight('gmao', 'gerer')),
			'utilisateur' => trim($user->firstname.' '.$user->lastname) ?: $user->login,
			'jeton' => newToken(),
		));
		break;

	case 'historique':
		$id = GETPOSTINT('id');
		repondre(lignes($db, "SELECT rowid AS id, type, date_debut, date_fin, arret_min, symptome, cause, action, pieces, code_alarme, technicien, statut
			FROM {$P}gmao_intervention WHERE entity = $E AND fk_equipement = $id ORDER BY date_debut DESC LIMIT 200"));
		break;

	// ------------------------------------------------------------ pannes et interventions
	case 'intervention':
		exiger('ecrire');
		$id = GETPOSTINT('id');
		$eq = GETPOSTINT('equipement');
		if (!$eq) erreur('Choisissez la machine ou le moteur.');
		$type = in_array(GETPOST('type', 'aZ09'), array('panne', 'preventif', 'amelioration')) ? GETPOST('type', 'aZ09') : 'panne';
		$statut = in_array(GETPOST('statut', 'aZ09'), array('ouverte', 'en_cours', 'cloturee')) ? GETPOST('statut', 'aZ09') : 'ouverte';
		$deb = txt('date_debut', 19) ?: dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S');
		$fin = txt('date_fin', 19);
		if ($statut === 'cloturee' && $fin === '') $fin = dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S');
		$champs = array(
			'fk_equipement' => $eq, 'type' => "'$type'", 'statut' => "'$statut'",
			'date_debut' => sqlv($db, str_replace('T', ' ', $deb)), 'date_fin' => sqlv($db, str_replace('T', ' ', $fin)),
			'arret_min' => (int) GETPOSTINT('arret_min'),
			'symptome' => sqlv($db, txt('symptome')), 'cause' => sqlv($db, txt('cause')), 'action' => sqlv($db, txt('action')),
			'pieces' => sqlv($db, txt('pieces')), 'code_alarme' => sqlv($db, txt('code_alarme', 64)), 'technicien' => sqlv($db, txt('technicien', 128)),
		);
		if ($id) {
			$set = array(); foreach ($champs as $k => $v) $set[] = "$k = $v";
			$ok = $db->query("UPDATE {$P}gmao_intervention SET ".implode(', ', $set)." WHERE rowid = $id AND entity = $E");
		} else {
			$champs['entity'] = $E; $champs['fk_user'] = (int) $user->id;
			$ok = $db->query("INSERT INTO {$P}gmao_intervention (".implode(', ', array_keys($champs)).") VALUES (".implode(', ', $champs).")");
			$id = $db->last_insert_id("{$P}gmao_intervention");
		}
		if (!$ok) erreur('Erreur base : '.$db->lasterror(), 500);
		// statut de la machine : en panne tant qu'une panne est ouverte
		$n = lignes($db, "SELECT COUNT(*) AS n FROM {$P}gmao_intervention WHERE fk_equipement = $eq AND type = 'panne' AND statut <> 'cloturee'");
		$db->query("UPDATE {$P}gmao_equipement SET statut = '".($n[0]['n'] > 0 ? 'panne' : 'marche')."' WHERE rowid = $eq AND statut IN ('marche', 'panne')");
		repondre(array('ok' => 1, 'id' => (int) $id));
		break;

	case 'plan_fait':
		exiger('ecrire');
		$id = GETPOSTINT('id');
		$p = lignes($db, "SELECT fk_equipement, nom FROM {$P}gmao_plan WHERE rowid = $id AND entity = $E");
		if (!$p) erreur('Plan introuvable.', 404);
		$now = dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S');
		$db->query("INSERT INTO {$P}gmao_intervention (entity, fk_equipement, type, date_debut, date_fin, statut, action, technicien, fk_plan, fk_user)
			VALUES ($E, ".(int) $p[0]['fk_equipement'].", 'preventif', '$now', '$now', 'cloturee', ".sqlv($db, 'Préventif réalisé : '.$p[0]['nom'].(txt('note') ? "\n".txt('note') : '')).", ".sqlv($db, txt('technicien', 128)).", $id, ".(int) $user->id.")");
		$db->query("UPDATE {$P}gmao_plan SET derniere = CURDATE() WHERE rowid = $id");
		repondre(array('ok' => 1));
		break;

	// ------------------------------------------------------------ référentiel (droit « gerer »)
	case 'equipement':
		exiger('gerer');
		$id = GETPOSTINT('id');
		$tag = strtoupper(preg_replace('/\s+/', '', txt('tag', 64)));
		if ($tag === '' || txt('nom') === '') erreur('Tag et nom obligatoires.');
		$type = in_array(GETPOST('type', 'aZ09'), array('site', 'atelier', 'zone', 'machine', 'moteur', 'organe', 'armoire')) ? GETPOST('type', 'aZ09') : 'machine';
		$parent = GETPOSTINT('parent');
		if ($id && $parent == $id) erreur('Une machine ne peut pas être son propre parent.');
		$crit = in_array(GETPOST('criticite', 'alpha'), array('A', 'B', 'C')) ? GETPOST('criticite', 'alpha') : 'B';
		$statut = in_array(GETPOST('statut', 'aZ09'), array('marche', 'panne', 'arret', 'rebut')) ? GETPOST('statut', 'aZ09') : 'marche';
		$champs = array(
			'tag' => sqlv($db, $tag), 'nom' => sqlv($db, txt('nom', 255)), 'type' => "'$type'", 'fk_parent' => $parent ?: 'NULL',
			'armoire' => sqlv($db, txt('armoire', 64)), 'repere' => sqlv($db, txt('repere', 128)), 'puissance_kw' => sqln(txt('puissance_kw')),
			'vitesse' => sqlv($db, txt('vitesse', 32)), 'folio' => sqlv($db, txt('folio', 64)), 'criticite' => "'$crit'",
			'fabricant' => sqlv($db, txt('fabricant', 128)), 'modele' => sqlv($db, txt('modele', 128)), 'numserie' => sqlv($db, txt('numserie', 128)),
			'statut' => "'$statut'", 'notes' => sqlv($db, txt('notes')),
		);
		if ($id) {
			$set = array(); foreach ($champs as $k => $v) $set[] = "$k = $v";
			$ok = $db->query("UPDATE {$P}gmao_equipement SET ".implode(', ', $set)." WHERE rowid = $id AND entity = $E");
		} else {
			$champs['entity'] = $E; $champs['date_creation'] = 'NOW()';
			$ok = $db->query("INSERT INTO {$P}gmao_equipement (".implode(', ', array_keys($champs)).") VALUES (".implode(', ', $champs).")");
			$id = $db->last_insert_id("{$P}gmao_equipement");
		}
		if (!$ok) erreur(strpos($db->lasterror(), 'Duplicate') !== false ? "Le tag $tag existe déjà." : 'Erreur base : '.$db->lasterror(), 400);
		repondre(array('ok' => 1, 'id' => (int) $id));
		break;

	case 'plan':
		exiger('gerer');
		$id = GETPOSTINT('id');
		$eq = GETPOSTINT('equipement');
		if (!$eq || txt('nom') === '') erreur('Machine et nom du plan obligatoires.');
		$champs = array(
			'fk_equipement' => $eq, 'nom' => sqlv($db, txt('nom', 255)), 'frequence_j' => max(1, GETPOSTINT('frequence_j')),
			'duree_min' => GETPOSTINT('duree_min') ?: 'NULL', 'checklist' => sqlv($db, txt('checklist')), 'securite' => sqlv($db, txt('securite')),
			'technicien' => sqlv($db, txt('technicien', 128)), 'derniere' => sqlv($db, txt('derniere', 10)), 'actif' => GETPOST('actif', 'int') === '0' ? 0 : 1,
		);
		if ($id) {
			$set = array(); foreach ($champs as $k => $v) $set[] = "$k = $v";
			$ok = $db->query("UPDATE {$P}gmao_plan SET ".implode(', ', $set)." WHERE rowid = $id AND entity = $E");
		} else {
			$champs['entity'] = $E;
			$ok = $db->query("INSERT INTO {$P}gmao_plan (".implode(', ', array_keys($champs)).") VALUES (".implode(', ', $champs).")");
		}
		if (!$ok) erreur('Erreur base : '.$db->lasterror(), 500);
		repondre(array('ok' => 1));
		break;

	case 'piece':
		exiger('gerer');
		$id = GETPOSTINT('id');
		if (txt('nom') === '') erreur('Nom de la pièce obligatoire.');
		$champs = array(
			'reference' => sqlv($db, txt('reference', 128)), 'nom' => sqlv($db, txt('nom', 255)), 'fk_equipement' => GETPOSTINT('equipement') ?: 'NULL',
			'unite' => sqlv($db, txt('unite', 16) ?: 'pièce'), 'stock' => sqln(txt('stock')) === 'NULL' ? 0 : sqln(txt('stock')), 'seuil' => sqln(txt('seuil')) === 'NULL' ? 0 : sqln(txt('seuil')),
			'emplacement' => sqlv($db, txt('emplacement', 128)), 'fournisseur' => sqlv($db, txt('fournisseur', 255)), 'cout' => sqln(txt('cout')),
		);
		if ($id) {
			$set = array(); foreach ($champs as $k => $v) $set[] = "$k = $v";
			$ok = $db->query("UPDATE {$P}gmao_piece SET ".implode(', ', $set)." WHERE rowid = $id AND entity = $E");
		} else {
			$champs['entity'] = $E;
			$ok = $db->query("INSERT INTO {$P}gmao_piece (".implode(', ', array_keys($champs)).") VALUES (".implode(', ', $champs).")");
		}
		if (!$ok) erreur('Erreur base : '.$db->lasterror(), 500);
		repondre(array('ok' => 1));
		break;

	case 'piece_mvt':
		exiger('ecrire');
		$id = GETPOSTINT('id');
		$q = sqln(txt('quantite'));
		if ($q === 'NULL') erreur('Quantité invalide.');
		$db->query("UPDATE {$P}gmao_piece SET stock = stock + ($q) WHERE rowid = $id AND entity = $E");
		repondre(array('ok' => 1));
		break;

	case 'alarme':
		exiger('gerer');
		$id = GETPOSTINT('id');
		if (txt('code') === '' || txt('texte') === '') erreur('Code et texte de l\'alarme obligatoires.');
		$champs = array('code' => sqlv($db, txt('code', 64)), 'texte' => sqlv($db, txt('texte', 255)), 'fk_equipement' => GETPOSTINT('equipement') ?: 'NULL',
			'causes' => sqlv($db, txt('causes')), 'remede' => sqlv($db, txt('remede')));
		if ($id) {
			$set = array(); foreach ($champs as $k => $v) $set[] = "$k = $v";
			$ok = $db->query("UPDATE {$P}gmao_alarme SET ".implode(', ', $set)." WHERE rowid = $id AND entity = $E");
		} else {
			$champs['entity'] = $E;
			$ok = $db->query("INSERT INTO {$P}gmao_alarme (".implode(', ', array_keys($champs)).") VALUES (".implode(', ', $champs).")");
		}
		if (!$ok) erreur(strpos($db->lasterror(), 'Duplicate') !== false ? 'Ce code d\'alarme existe déjà.' : 'Erreur base : '.$db->lasterror(), 400);
		repondre(array('ok' => 1));
		break;

	case 'supprimer':
		exiger('gerer');
		$tables = array('equipement' => 'gmao_equipement', 'plan' => 'gmao_plan', 'piece' => 'gmao_piece', 'alarme' => 'gmao_alarme', 'intervention' => 'gmao_intervention');
		$quoi = GETPOST('quoi', 'aZ09');
		$id = GETPOSTINT('id');
		if (!isset($tables[$quoi])) erreur('Objet inconnu.');
		if ($quoi === 'equipement') {
			$n = lignes($db, "SELECT (SELECT COUNT(*) FROM {$P}gmao_equipement WHERE fk_parent = $id) + (SELECT COUNT(*) FROM {$P}gmao_intervention WHERE fk_equipement = $id) AS n");
			if ($n[0]['n'] > 0) erreur('Impossible : cet équipement a des sous-ensembles ou un historique. Passez-le au statut « rebut ».');
			$db->query("DELETE FROM {$P}gmao_plan WHERE fk_equipement = $id");
		}
		$db->query("DELETE FROM {$P}{$tables[$quoi]} WHERE rowid = $id AND entity = $E");
		repondre(array('ok' => 1));
		break;

	// ------------------------------------------------------------ import du schéma électrique et des alarmes automate
	case 'import':
		exiger('gerer');
		require_once __DIR__.'/lib/import.lib.php';
		$quoi = GETPOST('quoi', 'aZ09');
		$texte = isset($_POST['csv']) ? (string) $_POST['csv'] : '';
		if (trim($texte) === '') erreur('Collez le contenu du fichier CSV.');
		$r = $quoi === 'alarmes' ? gmao_importer_alarmes($db, $texte, $E) : gmao_importer_equipements($db, $texte, $E);
		repondre($r);
		break;

	default:
		erreur('Action inconnue.', 404);
}
