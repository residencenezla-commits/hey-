<?php
/*
 * Prime de rendement DPR AXXAM — API JSON utilisée par index.php.
 * Connexion obligatoire (session Dolibarr) ; droits : prime lire / saisir / valider / etat / gerer.
 *
 * Règle (document « Prime de rendement des équipes de production ») :
 *   prime de l'équipe = (wagons dépilés − objectif) × prime par wagon, si la fiche est validée par le contrôleur,
 *   la casse sous le seuil, les paquets bien présentés, la sécurité et la propreté respectées ;
 *   part par personne = prime ÷ présents ayant droit (sans absence ou retard injustifié), arrondie au dinar.
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
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

header('Cache-Control: no-store');

function repondre($donnees, $code = 200)
{
	http_response_code($code);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($donnees, JSON_UNESCAPED_UNICODE);
	exit;
}
function erreur($message, $code = 400) { repondre(array('erreur' => $message), $code); }
function droit($d)
{
	global $user;
	return $user->admin || $user->hasRight('prime', $d);
}
function exiger($d)
{
	if (!droit($d)) erreur('Droit insuffisant : '.$d, 403);
}
function txt($k, $max = 65000)
{
	$v = GETPOST($k, 'restricthtml');
	$v = is_string($v) ? trim($v) : '';
	return mb_substr($v, 0, $max);
}
function sqlv($db, $v) { return ($v === '' || $v === null) ? 'NULL' : "'".$db->escape($v)."'"; }
function sqli($v) { return ($v === '' || $v === null || !is_numeric($v)) ? 'NULL' : (int) $v; }
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
function mois_param()
{
	$m = GETPOST('mois', 'alphanohtml');
	return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m : dol_print_date(dol_now(), '%Y-%m');
}
function regle()
{
	return array(
		'objectif' => (int) getDolGlobalString('PRIME_OBJECTIF', '10'),
		'prix_wagon' => (float) getDolGlobalString('PRIME_PAR_WAGON', '1500'),
		'seuil_casse' => (float) getDolGlobalString('PRIME_SEUIL_CASSE', '5'),
	);
}
function cloture($db, $mois)
{
	global $P, $E;
	$c = lignes($db, "SELECT c.date_cloture, c.total, u.login, u.firstname, u.lastname FROM {$P}prime_cloture c LEFT JOIN {$P}user u ON u.rowid = c.fk_user
		WHERE c.entity = $E AND c.mois = '".$db->escape($mois)."'");
	if (!$c) return null;
	$c = $c[0];
	return array('date' => $c['date_cloture'], 'total' => (float) $c['total'], 'par' => trim($c['firstname'].' '.$c['lastname']) ?: $c['login']);
}
function verifier_ouvert($db, $date)
{
	$mois = substr($date, 0, 7);
	if (cloture($db, $mois)) erreur("Le mois $mois est clôturé et transmis à la comptabilité : plus de modification possible.", 409);
}
/** Recalcule la prime d'une fiche et les parts de ses présents. */
function recalculer($db, $id)
{
	global $P;
	$p = lignes($db, "SELECT * FROM {$P}prime_poste WHERE rowid = ".(int) $id);
	if (!$p) return;
	$p = $p[0];
	$ok = $p['statut'] === 'valide' && $p['casse_pct'] !== null && (float) $p['casse_pct'] <= (float) $p['seuil_casse']
		&& $p['ctrl_paquets'] && $p['ctrl_securite'] && $p['ctrl_proprete'];
	$prime = $ok ? max(0, (int) $p['wagons'] - (int) $p['objectif']) * (float) $p['prix_wagon'] : 0;
	$n = lignes($db, "SELECT COUNT(*) AS n FROM {$P}prime_presence WHERE fk_poste = ".(int) $id." AND injustifie = 0");
	$n = (int) $n[0]['n'];
	$part = $n > 0 ? round($prime / $n) : 0;
	$db->query("UPDATE {$P}prime_poste SET prime = $prime, presents = $n, part = $part WHERE rowid = ".(int) $id);
	$db->query("UPDATE {$P}prime_presence SET part = IF(injustifie = 1, 0, $part) WHERE fk_poste = ".(int) $id);
}
function nom_user($o, $pre)
{
	return trim(($o[$pre.'_prenom'] ?? '').' '.($o[$pre.'_nom'] ?? '')) ?: ($o[$pre.'_login'] ?? '');
}

if (!droit('lire') && !droit('etat')) erreur('Droit insuffisant.', 403);
$P = MAIN_DB_PREFIX;
$E = (int) $conf->entity;
$op = GETPOST('op', 'aZ09');  // « op » et non « action » : la protection CSRF de Dolibarr bloque les GET avec « action »

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$jeton = GETPOST('token', 'alpha');
	if (empty($jeton) || empty($_SESSION['token']) || !hash_equals((string) $_SESSION['token'], (string) $jeton)) {
		if (empty($_SESSION['newtoken']) || !hash_equals((string) $_SESSION['newtoken'], (string) $jeton)) erreur('Session expirée : rechargez la page.', 403);
	}
}

switch ($op) {
	// ------------------------------------------------------------ référentiel et droits
	case 'tout':
		repondre(array(
			'regle' => regle(),
			'equipes' => lignes($db, "SELECT rowid AS id, nom, actif FROM {$P}prime_equipe WHERE entity = $E ORDER BY nom"),
			'employes' => lignes($db, "SELECT rowid AS id, matricule, nom, poste, fk_equipe AS equipe, actif FROM {$P}prime_employe WHERE entity = $E ORDER BY nom"),
			'clotures' => lignes($db, "SELECT mois, date_cloture, total FROM {$P}prime_cloture WHERE entity = $E ORDER BY mois DESC LIMIT 24"),
			'droits' => array('lire' => droit('lire'), 'saisir' => droit('saisir'), 'valider' => droit('valider'), 'etat' => droit('etat'), 'gerer' => droit('gerer')),
			'utilisateur' => trim($user->firstname.' '.$user->lastname) ?: $user->login,
			'aujourdhui' => dol_print_date(dol_now(), '%Y-%m-%d'),
			'jeton' => newToken(),
		));
		break;

	// ------------------------------------------------------------ fiches du mois
	case 'fiches':
		exiger('lire');
		$mois = mois_param();
		$f = lignes($db, "SELECT p.rowid AS id, p.date_poste, p.fk_equipe AS equipe, p.horaire, p.chef, p.brique, p.wagons, p.chariots, p.wagons_charges, p.enfournes, p.defournes, p.paquets,
			p.casse_pct, p.ctrl_paquets, p.ctrl_securite, p.ctrl_proprete, p.statut, p.motif, p.objectif, p.prix_wagon, p.seuil_casse, p.prime, p.presents, p.part, p.date_valid,
			us.login AS s_login, us.firstname AS s_prenom, us.lastname AS s_nom, uv.login AS v_login, uv.firstname AS v_prenom, uv.lastname AS v_nom
			FROM {$P}prime_poste p LEFT JOIN {$P}user us ON us.rowid = p.fk_user_saisie LEFT JOIN {$P}user uv ON uv.rowid = p.fk_user_valid
			WHERE p.entity = $E AND p.date_poste >= '$mois-01' AND p.date_poste < DATE_ADD('$mois-01', INTERVAL 1 MONTH) ORDER BY p.date_poste DESC, p.horaire, p.fk_equipe");
		$par = array();
		foreach ($f as $i => $o) {
			$f[$i]['saisi_par'] = nom_user($o, 's');
			$f[$i]['valide_par'] = $o['v_login'] ? nom_user($o, 'v') : '';
			foreach (array('s_login', 's_prenom', 's_nom', 'v_login', 'v_prenom', 'v_nom') as $k) unset($f[$i][$k]);
			$f[$i]['presences'] = array();
			$par[$o['id']] = $i;
		}
		if ($par) {
			foreach (lignes($db, "SELECT fk_poste, fk_employe AS employe, injustifie, part FROM {$P}prime_presence WHERE fk_poste IN (".implode(',', array_keys($par)).")") as $pr) {
				$f[$par[$pr['fk_poste']]]['presences'][] = array('employe' => (int) $pr['employe'], 'injustifie' => (int) $pr['injustifie'], 'part' => (float) $pr['part']);
			}
		}
		repondre(array('mois' => $mois, 'fiches' => $f, 'cloture' => cloture($db, $mois)));
		break;

	case 'fiche':
		exiger('saisir');
		$id = GETPOSTINT('id');
		$date = GETPOST('date_poste', 'alphanohtml');
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) erreur('Date du poste invalide.');
		if ($date > dol_print_date(dol_now(), '%Y-%m-%d')) erreur('La date du poste est dans le futur.');
		$eq = GETPOSTINT('equipe');
		if (!$eq) erreur("Choisissez l'équipe.");
		$wagons = GETPOST('wagons', 'alphanohtml');
		if ($wagons === '' || !ctype_digit($wagons) || (int) $wagons > 200) erreur('Nombre de wagons dépilés invalide.');
		$casse = str_replace(',', '.', GETPOST('casse_pct', 'alphanohtml'));
		if ($casse !== '' && (!is_numeric($casse) || $casse < 0 || $casse > 100)) erreur('Casse : un pourcentage entre 0 et 100.');
		$presents = json_decode(GETPOST('presents', 'restricthtml') ?: '[]', true);
		if (!is_array($presents) || !count($presents)) erreur('Cochez les membres présents.');
		verifier_ouvert($db, $date);
		$ancien = null;
		if ($id) {
			$a = lignes($db, "SELECT date_poste, statut FROM {$P}prime_poste WHERE rowid = $id AND entity = $E");
			if (!$a) erreur('Fiche introuvable.', 404);
			$ancien = $a[0];
			verifier_ouvert($db, $ancien['date_poste']);
			if ($ancien['statut'] !== 'saisi' && !droit('valider')) erreur('Cette fiche a déjà été contrôlée : seul le contrôleur peut la modifier.', 403);
		}
		$champs = array(
			'date_poste' => "'".$db->escape($date)."'", 'fk_equipe' => $eq, 'horaire' => "'".$db->escape(txt('horaire', 32))."'",
			'chef' => sqlv($db, txt('chef', 128)), 'brique' => sqlv($db, txt('brique', 32)), 'wagons' => (int) $wagons,
			'chariots' => sqli(GETPOST('chariots', 'alphanohtml')), 'wagons_charges' => sqli(GETPOST('wagons_charges', 'alphanohtml')),
			'enfournes' => sqli(GETPOST('enfournes', 'alphanohtml')), 'defournes' => sqli(GETPOST('defournes', 'alphanohtml')), 'paquets' => sqli(GETPOST('paquets', 'alphanohtml')),
			'casse_pct' => $casse === '' ? 'NULL' : (float) $casse,
			'ctrl_paquets' => GETPOSTINT('ctrl_paquets') ? 1 : 0, 'ctrl_securite' => GETPOSTINT('ctrl_securite') ? 1 : 0, 'ctrl_proprete' => GETPOSTINT('ctrl_proprete') ? 1 : 0,
		);
		// la règle en vigueur est figée sur la fiche tant qu'elle n'est pas validée
		if (!$ancien || $ancien['statut'] !== 'valide') {
			$r = regle();
			$champs += array('objectif' => $r['objectif'], 'prix_wagon' => $r['prix_wagon'], 'seuil_casse' => $r['seuil_casse']);
		}
		$db->begin();
		if ($id) {
			$set = array(); foreach ($champs as $k => $v) $set[] = "$k = $v";
			$ok = $db->query("UPDATE {$P}prime_poste SET ".implode(', ', $set)." WHERE rowid = $id AND entity = $E");
		} else {
			$champs['entity'] = $E; $champs['fk_user_saisie'] = (int) $user->id; $champs['statut'] = "'saisi'";
			$ok = $db->query("INSERT INTO {$P}prime_poste (".implode(', ', array_keys($champs)).") VALUES (".implode(', ', $champs).")");
			$id = $db->last_insert_id("{$P}prime_poste");
		}
		if (!$ok) {
			$db->rollback();
			if (stripos($db->lasterror(), 'duplicate') !== false) erreur('Une fiche existe déjà pour cette équipe, ce jour et ce poste : ouvrez-la pour la corriger.', 409);
			erreur('Erreur base : '.$db->lasterror(), 500);
		}
		$db->query("DELETE FROM {$P}prime_presence WHERE fk_poste = $id");
		$vus = array();
		foreach ($presents as $pr) {
			$emp = (int) ($pr['employe'] ?? 0);
			if (!$emp || isset($vus[$emp])) continue;
			$vus[$emp] = 1;
			$db->query("INSERT INTO {$P}prime_presence (fk_poste, fk_employe, injustifie) VALUES ($id, $emp, ".(!empty($pr['injustifie']) ? 1 : 0).")");
		}
		recalculer($db, $id);
		$db->commit();
		repondre(array('ok' => 1, 'id' => (int) $id));
		break;

	case 'controle':  // décision du contrôleur : valide, refuse, rouvrir
		exiger('valider');
		$id = GETPOSTINT('id');
		$a = lignes($db, "SELECT date_poste FROM {$P}prime_poste WHERE rowid = $id AND entity = $E");
		if (!$a) erreur('Fiche introuvable.', 404);
		verifier_ouvert($db, $a[0]['date_poste']);
		$decision = GETPOST('decision', 'aZ09');
		if (!in_array($decision, array('valide', 'refuse', 'rouvrir'))) erreur('Décision inconnue.');
		$motif = txt('motif', 2000);
		if ($decision === 'refuse' && $motif === '') erreur('Indiquez le motif du refus.');
		if ($decision === 'rouvrir') {
			$db->query("UPDATE {$P}prime_poste SET statut = 'saisi', fk_user_valid = NULL, date_valid = NULL WHERE rowid = $id");
		} else {
			$db->query("UPDATE {$P}prime_poste SET statut = '$decision', motif = ".sqlv($db, $motif).", fk_user_valid = ".(int) $user->id.", date_valid = NOW() WHERE rowid = $id");
		}
		recalculer($db, $id);
		repondre(array('ok' => 1));
		break;

	case 'supprimer_fiche':
		exiger('saisir');
		$id = GETPOSTINT('id');
		$a = lignes($db, "SELECT date_poste, statut FROM {$P}prime_poste WHERE rowid = $id AND entity = $E");
		if (!$a) erreur('Fiche introuvable.', 404);
		verifier_ouvert($db, $a[0]['date_poste']);
		if ($a[0]['statut'] !== 'saisi' && !droit('valider')) erreur('Fiche déjà contrôlée : seul le contrôleur peut la supprimer.', 403);
		$db->query("DELETE FROM {$P}prime_presence WHERE fk_poste = $id");
		$db->query("DELETE FROM {$P}prime_poste WHERE rowid = $id");
		repondre(array('ok' => 1));
		break;

	// ------------------------------------------------------------ état mensuel (comptabilité)
	case 'etat':
	case 'csv':
		if (!droit('etat') && !droit('gerer')) erreur('Droit insuffisant : etat', 403);
		$mois = mois_param();
		$debut = "'$mois-01'"; $fin = "DATE_ADD('$mois-01', INTERVAL 1 MONTH)";
		$emp = lignes($db, "SELECT e.rowid AS id, e.matricule, e.nom, e.poste, q.nom AS equipe,
			COUNT(pr.rowid) AS jours, SUM(CASE WHEN pr.part > 0 THEN 1 ELSE 0 END) AS jours_primes, COALESCE(SUM(pr.part), 0) AS total
			FROM {$P}prime_employe e LEFT JOIN {$P}prime_equipe q ON q.rowid = e.fk_equipe
			LEFT JOIN {$P}prime_presence pr ON pr.fk_employe = e.rowid AND pr.fk_poste IN
				(SELECT rowid FROM {$P}prime_poste WHERE entity = $E AND date_poste >= $debut AND date_poste < $fin)
			WHERE e.entity = $E GROUP BY e.rowid, e.matricule, e.nom, e.poste, q.nom
			HAVING MAX(e.actif) = 1 OR COUNT(pr.rowid) > 0 ORDER BY e.nom");
		$attente = lignes($db, "SELECT COUNT(*) AS n FROM {$P}prime_poste WHERE entity = $E AND statut = 'saisi' AND date_poste >= $debut AND date_poste < $fin");
		$cl = cloture($db, $mois);
		if ($op === 'csv') {
			header('Content-Type: text/csv; charset=utf-8');
			header('Content-Disposition: attachment; filename="prime_rendement_'.$mois.'.csv"');
			$out = fopen('php://output', 'w');
			fwrite($out, "\xEF\xBB\xBF");  // BOM : Excel lit les accents
			fputcsv($out, array('SARL DPR AXXAM - Etat des primes de rendement', $mois, $cl ? 'Clôturé le '.$cl['date'].' par '.$cl['par'] : 'PROVISOIRE (mois non clôturé)'), ';');
			fputcsv($out, array('Matricule', 'Nom et prénom', 'Poste', 'Équipe', 'Jours de présence', 'Jours primés', 'Total prime (DA)'), ';');
			$t = 0;
			foreach ($emp as $e) {
				fputcsv($out, array($e['matricule'], $e['nom'], $e['poste'], $e['equipe'], $e['jours'], $e['jours_primes'], number_format((float) $e['total'], 2, ',', '')), ';');
				$t += (float) $e['total'];
			}
			fputcsv($out, array('', 'TOTAL GÉNÉRAL', '', '', '', '', number_format($t, 2, ',', '')), ';');
			fclose($out);
			exit;
		}
		$jours = lignes($db, "SELECT p.date_poste, p.fk_equipe AS equipe, SUM(p.wagons) AS wagons, MAX(p.casse_pct) AS casse, SUM(p.prime) AS prime, SUM(p.presents) AS presents,
			MAX(p.part) AS part, GROUP_CONCAT(p.statut) AS statuts
			FROM {$P}prime_poste p WHERE p.entity = $E AND p.date_poste >= $debut AND p.date_poste < $fin GROUP BY p.date_poste, p.fk_equipe ORDER BY p.date_poste");
		repondre(array('mois' => $mois, 'employes' => $emp, 'jours' => $jours, 'en_attente' => (int) $attente[0]['n'], 'cloture' => $cl));
		break;

	case 'cloturer':
		exiger('gerer');
		$mois = mois_param();
		if (cloture($db, $mois)) erreur('Mois déjà clôturé.');
		if ($mois >= dol_print_date(dol_now(), '%Y-%m')) erreur('On ne clôture un mois qu\'une fois terminé.');
		$a = lignes($db, "SELECT COUNT(*) AS n FROM {$P}prime_poste WHERE entity = $E AND statut = 'saisi' AND date_poste >= '$mois-01' AND date_poste < DATE_ADD('$mois-01', INTERVAL 1 MONTH)");
		if ($a[0]['n'] > 0) erreur($a[0]['n'].' fiche(s) attendent encore le contrôleur : validez-les ou refusez-les avant de clôturer.');
		$t = lignes($db, "SELECT COALESCE(SUM(prime), 0) AS t FROM {$P}prime_poste WHERE entity = $E AND date_poste >= '$mois-01' AND date_poste < DATE_ADD('$mois-01', INTERVAL 1 MONTH)");
		$db->query("INSERT INTO {$P}prime_cloture (entity, mois, date_cloture, fk_user, total) VALUES ($E, '$mois', NOW(), ".(int) $user->id.", ".(float) $t[0]['t'].")");
		repondre(array('ok' => 1));
		break;

	case 'rouvrir_mois':
		exiger('gerer');
		$mois = mois_param();
		$db->query("DELETE FROM {$P}prime_cloture WHERE entity = $E AND mois = '$mois'");
		repondre(array('ok' => 1));
		break;

	// ------------------------------------------------------------ personnel, équipes, règle (droit « gerer »)
	case 'equipe':
		exiger('gerer');
		$id = GETPOSTINT('id');
		$nom = txt('nom', 64);
		if ($nom === '') erreur("Nom de l'équipe obligatoire.");
		$actif = GETPOST('actif', 'alpha') === '0' ? 0 : 1;
		$ok = $id ? $db->query("UPDATE {$P}prime_equipe SET nom = '".$db->escape($nom)."', actif = $actif WHERE rowid = $id AND entity = $E")
			: $db->query("INSERT INTO {$P}prime_equipe (entity, nom, actif) VALUES ($E, '".$db->escape($nom)."', 1)");
		if (!$ok) erreur(stripos($db->lasterror(), 'duplicate') !== false ? 'Cette équipe existe déjà.' : 'Erreur base : '.$db->lasterror());
		repondre(array('ok' => 1));
		break;

	case 'employe':
		exiger('gerer');
		$id = GETPOSTINT('id');
		$nom = txt('nom', 128);
		if ($nom === '') erreur('Nom obligatoire.');
		$champs = array('nom' => "'".$db->escape($nom)."'", 'matricule' => sqlv($db, txt('matricule', 32)), 'poste' => sqlv($db, txt('poste', 64)),
			'fk_equipe' => GETPOSTINT('equipe') ?: 'NULL', 'actif' => GETPOST('actif', 'alpha') === '0' ? 0 : 1);
		if ($id) {
			$set = array(); foreach ($champs as $k => $v) $set[] = "$k = $v";
			$ok = $db->query("UPDATE {$P}prime_employe SET ".implode(', ', $set)." WHERE rowid = $id AND entity = $E");
		} else {
			$champs['entity'] = $E;
			$ok = $db->query("INSERT INTO {$P}prime_employe (".implode(', ', array_keys($champs)).") VALUES (".implode(', ', $champs).")");
		}
		if (!$ok) erreur('Erreur base : '.$db->lasterror(), 500);
		repondre(array('ok' => 1));
		break;

	case 'employes_lot':
		// un nom par ligne, éventuellement « Nom ; poste ; matricule ; équipe »,
		// ou lignes copiées depuis la liste des salariés de la paie (MATRICULE, NOM, PRENOM, D.ENTREE, ADRESSE, NAISSANCE, LIEU, FONCTION) ;
		// un matricule déjà connu met la personne à jour au lieu de la créer en double
		exiger('gerer');
		$eq0 = GETPOSTINT('equipe');
		$crees = $maj = 0;
		$equipe_de = function ($nom) use ($db, $P, $E) {  // 4e colonne « Équipe X » : retrouvée, ou créée si elle n'existe pas
			$nom = mb_substr(trim($nom), 0, 64);
			if ($nom === '') return 0;
			$o = lignes($db, "SELECT rowid FROM {$P}prime_equipe WHERE entity = $E AND nom = '".$db->escape($nom)."'");
			if ($o) return (int) $o[0]['rowid'];
			$db->query("INSERT INTO {$P}prime_equipe (entity, nom) VALUES ($E, '".$db->escape($nom)."')");
			return (int) $db->last_insert_id("{$P}prime_equipe");
		};
		foreach (preg_split('/\R/', txt('liste')) as $l) {
			$c = array_map('trim', preg_split('/[;\t]/', $l));
			if ($c[0] === '' || strtoupper($c[0]) === 'MATRICULE') continue;
			if (count($c) >= 8 && preg_match('/^[A-Z]{2,4}\d+$/i', $c[0])) {
				list($nom, $poste, $mat) = array(trim(preg_replace('/\s+/', ' ', $c[1].' '.mb_convert_case($c[2], MB_CASE_TITLE, 'UTF-8'))), $c[7], $c[0]);
			} else {
				list($nom, $poste, $mat) = array($c[0], $c[1] ?? '', $c[2] ?? '');
			}
			$eq = !empty($c[3]) && count($c) < 8 ? $equipe_de($c[3]) : $eq0;
			$nom = mb_substr($nom, 0, 128); $poste = mb_convert_case(mb_substr($poste, 0, 64), MB_CASE_LOWER, 'UTF-8'); $mat = strtoupper(mb_substr($mat, 0, 32));
			$ex = $mat !== '' ? lignes($db, "SELECT rowid FROM {$P}prime_employe WHERE entity = $E AND matricule = '".$db->escape($mat)."'")
				: array();
			// pas encore de matricule connu : on reprend la personne du même nom saisie sans matricule
			if (!$ex) $ex = lignes($db, "SELECT rowid FROM {$P}prime_employe WHERE entity = $E AND (matricule IS NULL OR matricule = '') AND UPPER(nom) = UPPER('".$db->escape($nom)."')");
			if ($ex) {
				$db->query("UPDATE {$P}prime_employe SET nom = '".$db->escape($nom)."', poste = ".sqlv($db, $poste).", matricule = ".sqlv($db, $mat).", actif = 1".($eq ? ", fk_equipe = $eq" : '')." WHERE rowid = ".(int) $ex[0]['rowid']);
				$maj++;
			} else {
				$db->query("INSERT INTO {$P}prime_employe (entity, nom, poste, matricule, fk_equipe) VALUES ($E, '".$db->escape($nom)."', ".sqlv($db, $poste).", ".sqlv($db, $mat).", ".($eq ?: 'NULL').")");
				$crees++;
			}
		}
		repondre(array('ok' => 1, 'n' => $crees + $maj, 'crees' => $crees, 'maj' => $maj));
		break;

	case 'regle':
		exiger('gerer');
		$o = GETPOST('objectif', 'alphanohtml'); $p = str_replace(',', '.', GETPOST('prix_wagon', 'alphanohtml')); $s = str_replace(',', '.', GETPOST('seuil_casse', 'alphanohtml'));
		if (!ctype_digit($o) || !is_numeric($p) || !is_numeric($s) || $p < 0 || $s < 0 || $s > 100) erreur('Valeurs invalides.');
		dolibarr_set_const($db, 'PRIME_OBJECTIF', (int) $o, 'chaine', 0, '', $E);
		dolibarr_set_const($db, 'PRIME_PAR_WAGON', (float) $p, 'chaine', 0, '', $E);
		dolibarr_set_const($db, 'PRIME_SEUIL_CASSE', (float) $s, 'chaine', 0, '', $E);
		repondre(array('ok' => 1));
		break;

	default:
		erreur('Opération inconnue.', 404);
}
