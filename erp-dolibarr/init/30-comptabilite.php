<?php
/*
 * Comptabilité de SARL DPR AXXAM dans Dolibarr, sur le modèle de PC Compta (DLG).
 *
 * Lit init/compta/*.csv (produits par outils/convertir_compta.py) et :
 *  1. active la comptabilité en partie double et le module « Timbre DZ » ;
 *  2. crée le plan comptable de PC Compta (comptes SCF de la société) ;
 *  3. crée les journaux avec les mêmes codes (01, 02, 03, 073…) ;
 *  4. ouvre l'exercice 2026 ;
 *  5. règle les comptes par défaut comme PC Compta (41100, 40101, 70110, 44570, 44563, 44720…) ;
 *  6. relie banques et caisses à leur compte 512xx / 53000 et à leur journal ;
 *  7. donne un compte de vente / d'achat à chaque produit ;
 *  8. importe les écritures de PC Compta dans le grand livre (verrouillées).
 *
 * Exécuté au premier démarrage. Relance possible :
 *   docker compose exec web php /var/www/scripts/docker-init.d/30-comptabilite.php
 *   … --remplacer   remplace les écritures importées de PC Compta par celles du nouveau
 *                   fichier (les écritures saisies dans Dolibarr ne sont jamais touchées, et
 *                   les pièces déjà envoyées de Dolibarr vers PC Compta ne sont pas doublées).
 */

require_once '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

$DOSSIER = __DIR__.'/compta';
foreach ($argv as $a) if (preg_match('/^--dossier=(.+)$/', $a, $m)) $DOSSIER = rtrim($m[1], '/');
$PLAN = 'DPRAXXAM-SCF';
$IMPORT_KEY = 'PCCOMPTA';
$remplacer = in_array('--remplacer', $argv);

function etape($t) { print "Comptabilité : $t\n"; }
function lire($fichier)
{
	$h = fopen($fichier, 'r');
	$entete = fgetcsv($h, 0, ';', '"', '\\');
	$lignes = array();
	while (($l = fgetcsv($h, 0, ';', '"', '\\')) !== false) {
		if (count($l) == count($entete)) $lignes[] = array_combine($entete, $l);
	}
	fclose($h);
	return $lignes;
}
function q($db, $sql)
{
	$r = $db->query($sql);
	if (!$r) { print "ERREUR SQL : ".$db->lasterror()."\n"; exit(1); }
	return $r;
}

if (!is_readable("$DOSSIER/plan_comptable.csv")) { etape("fichiers init/compta absents, comptabilité non paramétrée."); exit(0); }

$user = new User($db);
if ($user->fetch(0, getenv('DOLI_ADMIN_LOGIN') ?: 'admin') <= 0) { etape('administrateur introuvable'); exit(1); }
$user->loadRights();
$P = MAIN_DB_PREFIX;
$entite = (int) $conf->entity;

// ------------------------------------------------------------ 1. Modules
foreach (array('modAccounting', 'modTimbreDZ') as $m) {
	$r = activateModule($m);
	if (!empty($r['errors'])) etape("ERREUR activation $m : ".implode(' ', $r['errors']));
}
etape('modules Comptabilité et Timbre DZ activés');

// Modèles PDF « DLG » (facture et bon de livraison au format PC Compta), par défaut
foreach (array('invoice' => array('FACTURE_ADDON_PDF', 'dlg'), 'shipping' => array('EXPEDITION_ADDON_PDF', 'dlgbl')) as $type => list($const, $nom)) {
	q($db, "DELETE FROM {$P}document_model WHERE nom = '$nom' AND type = '$type' AND entity = $entite");
	q($db, "INSERT INTO {$P}document_model (nom, type, entity, libelle) VALUES ('$nom', '$type', $entite, 'DLG')");
	dolibarr_set_const($db, $const, $nom, 'chaine', 0, '', $entite);
}
etape('modèles de facture et de bon de livraison « DLG » activés');

// ------------------------------------------------------------ 2. Plan comptable
$res = q($db, "SELECT rowid FROM {$P}accounting_system WHERE pcg_version = '".$db->escape($PLAN)."'");
if ($o = $db->fetch_object($res)) {
	$idPlan = (int) $o->rowid;
} else {
	q($db, "INSERT INTO {$P}accounting_system (fk_country, pcg_version, label, active) VALUES (13, '".$db->escape($PLAN)."', 'Plan comptable SARL DPR AXXAM (PC Compta, SCF)', 1)");
	$idPlan = (int) $db->last_insert_id("{$P}accounting_system");
}
dolibarr_set_const($db, 'CHARTOFACCOUNTS', $idPlan, 'chaine', 0, '', $entite);
$types = array('1' => 'CAPIT', '2' => 'IMMO', '3' => 'STOCK', '4' => 'THIRDPARTY', '5' => 'FINAN', '6' => 'EXPENSE', '7' => 'INCOME');
$existants = array();
$res = q($db, "SELECT account_number FROM {$P}accounting_account WHERE fk_pcg_version = '".$db->escape($PLAN)."' AND entity = $entite");
while ($o = $db->fetch_object($res)) $existants[$o->account_number] = true;
$nb = 0;
foreach (lire("$DOSSIER/plan_comptable.csv") as $c) {
	$num = $db->escape($c['compte']);
	$lib = $db->escape(dol_trunc($c['libelle'], 255, 'right', 'UTF-8', 1));
	$lettrable = preg_match('/^4[01]/', $c['compte']) ? 1 : 0;
	if (isset($existants[$c['compte']])) {
		q($db, "UPDATE {$P}accounting_account SET label = '$lib', labelshort = '".$db->escape(dol_trunc($c['libelle'], 30, 'right', 'UTF-8', 1))."' WHERE fk_pcg_version = '".$db->escape($PLAN)."' AND account_number = '$num' AND entity = $entite");
		continue;
	}
	q($db, "INSERT INTO {$P}accounting_account (entity, datec, fk_pcg_version, pcg_type, account_number, account_parent, label, labelshort, fk_user_author, active, reconcilable)"
		." VALUES ($entite, '".$db->idate(dol_now())."', '".$db->escape($PLAN)."', '".($types[$c['classe']] ?? 'OTHER')."', '$num', 0, '$lib', '".$db->escape(dol_trunc($c['libelle'], 30, 'right', 'UTF-8', 1))."', ".((int) $user->id).", 1, $lettrable)");
	$nb++;
}
etape("plan comptable « $PLAN » : $nb comptes créés");

// ------------------------------------------------------------ 3. Journaux (mêmes codes que PC Compta)
$natures = array('divers' => 1, 'vente' => 2, 'achat' => 3, 'banque' => 4, 'stock' => 8);
q($db, "UPDATE {$P}accounting_journal SET active = 0 WHERE entity = $entite AND code IN ('VT','AC','BQ','OD','AN')");
$journal = array();
foreach (lire("$DOSSIER/journaux.csv") as $j) {
	$nature = $j['code'] == '01' ? 9 : ($natures[$j['nature']] ?? 1);  // 01 = réouverture (à-nouveaux)
	$code = $db->escape($j['code']);
	$res = q($db, "SELECT rowid FROM {$P}accounting_journal WHERE code = '$code' AND entity = $entite");
	if ($o = $db->fetch_object($res)) {
		q($db, "UPDATE {$P}accounting_journal SET label = '".$db->escape($j['libelle'])."', nature = $nature, active = 1 WHERE rowid = ".((int) $o->rowid));
		$journal[$j['code']] = array((int) $o->rowid, $j['libelle']);
	} else {
		q($db, "INSERT INTO {$P}accounting_journal (entity, code, label, nature, active) VALUES ($entite, '$code', '".$db->escape($j['libelle'])."', $nature, 1)");
		$journal[$j['code']] = array((int) $db->last_insert_id("{$P}accounting_journal"), $j['libelle']);
	}
}
etape(count($journal).' journaux (codes PC Compta)');

// ------------------------------------------------------------ 4. Exercice comptable
$dossier = array();
foreach (lire("$DOSSIER/dossier.csv") as $d) $dossier[$d['cle']] = $d['valeur'];
$debut = $dossier['DEBUT_EXERCICE'] ?? '20260101';
$fin = $dossier['FIN_EXERCICE'] ?? '20261231';
$dDebut = substr($debut, 0, 4).'-'.substr($debut, 4, 2).'-'.substr($debut, 6, 2);
$dFin = substr($fin, 0, 4).'-'.substr($fin, 4, 2).'-'.substr($fin, 6, 2);
$res = q($db, "SELECT rowid FROM {$P}accounting_fiscalyear WHERE date_start = '$dDebut' AND entity = $entite");
if (!$db->fetch_object($res)) {
	q($db, "INSERT INTO {$P}accounting_fiscalyear (label, date_start, date_end, statut, entity, datec, fk_user_author) VALUES ('".substr($debut, 0, 4)."', '$dDebut', '$dFin', 0, $entite, '".$db->idate(dol_now())."', ".((int) $user->id).")");
}
etape("exercice du $dDebut au $dFin");

// ------------------------------------------------------------ 5. Comptes par défaut (comme PC Compta)
$defaut = array(
	'ACCOUNTING_ACCOUNT_CUSTOMER'          => '41100',
	'ACCOUNTING_ACCOUNT_SUPPLIER'          => '40101',
	'ACCOUNTING_PRODUCT_SOLD_ACCOUNT'      => '70110',  // ventes briqueterie
	'ACCOUNTING_SERVICE_SOLD_ACCOUNT'      => '70601',  // prestations imposables
	'ACCOUNTING_PRODUCT_BUY_ACCOUNT'       => '38110',  // achats matières locaux
	'ACCOUNTING_SERVICE_BUY_ACCOUNT'       => '61380',  // autres prestations
	'ACCOUNTING_VAT_SOLD_ACCOUNT'          => '44570',  // TVA due sur ventes
	'ACCOUNTING_VAT_BUY_ACCOUNT'           => '44563',  // TVA déductible
	'ACCOUNTING_VAT_PAY_ACCOUNT'           => '44510',  // TVA à payer
	'ACCOUNTING_REVENUESTAMP_SOLD_ACCOUNT' => '44720',  // timbre sur ventes
	'ACCOUNTING_REVENUESTAMP_BUY_ACCOUNT'  => '64520',  // droits de timbre
	'ACCOUNTING_ACCOUNT_TRANSFER_CASH'     => '58100',  // virements de fonds
	'ACCOUNTING_ACCOUNT_SUSPENSE'          => '47000',  // comptes transitoires
	'ACCOUNTING_ACCOUNT_CUSTOMER_DEPOSIT'  => '41900',  // avances reçues
	'ACCOUNTING_ACCOUNT_SUPPLIER_DEPOSIT'  => '40900',  // avances versées
	'ACCOUNTING_ACCOUNT_DISCOUNT_GRANTED'  => '70900',  // rabais, remises accordés
	'SALARIES_ACCOUNTING_ACCOUNT_PAYMENT'  => '42101',  // personnel, rémunérations dues
	'ACCOUNTING_LENGTH_GACCOUNT'           => '0',
	'ACCOUNTING_LENGTH_AACCOUNT'           => '0',
	'ACCOUNTING_DATE_START_BINDING'        => $dDebut,
	'MAIN_COMPANY_CONTROL_DBL_ID'          => '0',
	'MAIN_INFO_APE'                        => $dossier['ART_IMPOSITION'] ?? '',  // Algérie : article d'imposition
);
foreach ($defaut as $k => $v) dolibarr_set_const($db, $k, $v, 'chaine', 0, '', $entite);
q($db, "UPDATE {$P}c_tva SET accountancy_code_sell = '44570', accountancy_code_buy = '44563' WHERE fk_pays = 13 AND taux > 0");
// Une ligne « timbre » pour l'Algérie fait apparaître le champ timbre fiscal sur les factures ;
// le montant est calculé par le module Timbre DZ.
$res = q($db, "SELECT rowid FROM {$P}c_revenuestamp WHERE fk_pays = 13");
if (!$db->fetch_object($res)) {
	q($db, "INSERT INTO {$P}c_revenuestamp (rowid, fk_pays, taux, revenuestamp_type, note, active, accountancy_code_sell, accountancy_code_buy) VALUES (1301, 13, 0, 'fixed', 'Droit de timbre (calculé automatiquement si paiement en espèces)', 1, '44720', '64520')");
}
etape('comptes par défaut : clients 41100, fournisseurs 40101, ventes 70110, TVA 44570 / 44563, timbre 44720');

// ------------------------------------------------------------ 6. Banques et caisses
$banques = array(
	// réf => [libellé, compte, journal, type (1 courant, 2 caisse), banque]
	'CAISSE'   => array('Caisse briqueterie', '53000', '03', Account::TYPE_CASH, ''),
	'CAISSE-C' => array('Caisse carrosserie', '53000', '18', Account::TYPE_CASH, ''),
	'CPA-4083' => array('CPA El Kseur 004-00370-4000004083-77', '51201', '02', Account::TYPE_CURRENT, 'CPA'),
	'CPA-6599' => array('CPA 004-00370-4000006599-95', '51211', '11', Account::TYPE_CURRENT, 'CPA'),
	'CPA-6216' => array('CPA 004-00370-4000262163-91', '51202', '23', Account::TYPE_CURRENT, 'CPA'),
	'BNA'      => array('BNA 0300000961-18', '51230', '13', Account::TYPE_CURRENT, 'BNA'),
	'BARAKA'   => array('Al Baraka Banque', '51240', '17', Account::TYPE_CURRENT, 'Al Baraka'),
	'SGA'      => array('Société Générale Algérie 1130075061-09', '51250', '24', Account::TYPE_CURRENT, 'Société Générale Algérie'),
);
foreach ($banques as $ref => $b) {
	if (!isset($journal[$b[2]])) continue;
	$a = new Account($db);
	$existe = $a->fetch(0, $ref) > 0;
	$a->ref = $ref; $a->label = $b[0]; $a->bank = $b[4];
	$a->type = $a->courant = $b[3];
	$a->account_number = $b[1];
	$a->fk_accountancy_journal = $journal[$b[2]][0];
	$a->currency_code = 'DZD'; $a->country_id = 13; $a->clos = 0;
	if ($existe) {
		$r = $a->update($user);
	} else {
		$a->date_solde = dol_now(); $a->solde = 0;
		$r = $a->create($user);
	}
	if ($r < 0) etape("ERREUR compte $ref : ".$a->error);
}
etape(count($banques).' banques et caisses reliées à leur compte et à leur journal');

// ------------------------------------------------------------ 7. Comptes des produits
$comptesProduits = array(
	'B8' => array('70110', ''), 'B12' => array('70110', ''), 'HOURDIS' => array('70110', ''),
	'ARGILE' => array('', '38130'), 'FILM-PAL' => array('', '38110'), 'PALETTE' => array('', '38110'),
	'GASOIL' => array('', '38213'), 'PDR' => array('', '38215'), 'GAZ' => array('', '60713'),
	'ELEC' => array('', '60700'), 'TRANSPORT' => array('70601', '62400'), 'ENTRETIEN' => array('', '61500'),
);
foreach ($comptesProduits as $ref => $c) {
	q($db, "UPDATE {$P}product SET accountancy_code_sell = ".($c[0] ? "'".$c[0]."'" : "NULL").", accountancy_code_buy = ".($c[1] ? "'".$c[1]."'" : "NULL")." WHERE ref = '".$db->escape($ref)."' AND entity IN (".getEntity('product').")");
}
etape('comptes de vente et d\'achat affectés aux produits');

// ------------------------------------------------------------ 8. Écritures de PC Compta
$res = q($db, "SELECT COUNT(*) AS n FROM {$P}accounting_bookkeeping WHERE import_key = '$IMPORT_KEY' AND entity = $entite");
$deja = (int) $db->fetch_object($res)->n;
if ($deja && !$remplacer) {
	etape("$deja lignes de PC Compta déjà présentes, import ignoré (option --remplacer pour recharger).");
	exit(0);
}
$db->begin();
if ($deja) q($db, "DELETE FROM {$P}accounting_bookkeeping WHERE import_key = '$IMPORT_KEY' AND entity = $entite");

// Pièces nées dans Dolibarr et déjà envoyées à PC Compta (ref = journal/pièce attribuée à
// l'export) : quand elles reviennent dans un export de PC Compta, on ne les importe pas une 2e fois.
$dolibarr = array();
$res = q($db, "SELECT DISTINCT ref FROM {$P}accounting_bookkeeping WHERE (import_key IS NULL OR import_key <> '$IMPORT_KEY') AND ref IS NOT NULL AND entity = $entite");
while ($o = $db->fetch_object($res)) $dolibarr[$o->ref] = true;

$res = q($db, "SELECT COALESCE(MAX(piece_num), 0) AS m FROM {$P}accounting_bookkeeping WHERE entity = $entite");
$decalage = (int) $db->fetch_object($res)->m;
$maintenant = $db->idate(dol_now());
$lignes = $ignorees = 0;
$valeurs = array();
foreach (lire("$DOSSIER/ecritures.csv") as $e) {
	if ($e['piece'] !== '' && isset($dolibarr[$e['journal'].'/'.$e['piece']])) { $ignorees++; continue; }
	$d = (float) $e['debit']; $c = (float) $e['credit'];
	$date = substr($e['date'], 0, 4).'-'.substr($e['date'], 4, 2).'-'.substr($e['date'], 6, 2);
	$docref = $e['reference'] !== '' ? $e['reference'] : 'PCC '.$e['journal'].'/F'.$e['folio'];
	// ref = journal/pièce PC Compta (ex. 073/000215) : l'export vers PC Compta continue cette numérotation
	$refPcc = $e['journal'].'/'.($e['piece'] !== '' ? $e['piece'] : 'F'.$e['folio']);
	$valeurs[] = "($entite, '".$db->escape($refPcc)."', ".($decalage + (int) $e['piece_num']).", '$date', 'import', '".$db->escape($docref)."', 0, 0, "
		.($e['aux'] !== '' ? "'".$db->escape($e['aux'])."'" : "NULL").", "
		.($e['aux'] !== '' ? "'".$db->escape($e['aux'])."'" : "NULL").", "
		.($e['aux'] !== '' ? "'".$db->escape(dol_trunc($e['libelle_aux'], 255, 'right', 'UTF-8', 1))."'" : "NULL").", "
		."'".$db->escape($e['compte'])."', '".$db->escape(dol_trunc($e['libelle_compte'], 255, 'right', 'UTF-8', 1))."', "
		."'".$db->escape($e['libelle'])."', $d, $c, ".abs($d - $c).", '".($d >= $c ? 'D' : 'C')."', "
		.((int) $user->id).", '$maintenant', '".$db->escape($e['journal'])."', '".$db->escape($journal[$e['journal']][1] ?? $e['journal'])."', "
		."'$maintenant', '$maintenant', '$IMPORT_KEY')";
	$lignes++;
	if (count($valeurs) == 500) { inserer($db, $valeurs); $valeurs = array(); }
}
if ($valeurs) inserer($db, $valeurs);
$db->commit();

function inserer($db, $valeurs)
{
	q($db, "INSERT INTO ".MAIN_DB_PREFIX."accounting_bookkeeping (entity, ref, piece_num, doc_date, doc_type, doc_ref, fk_doc, fk_docdet, thirdparty_code, subledger_account, subledger_label,"
		." numero_compte, label_compte, label_operation, debit, credit, montant, sens, fk_user_author, date_creation, code_journal, journal_label, date_validated, date_export, import_key) VALUES ".implode(',', $valeurs));
}

// Contrôle : débit et crédit par compte = fichier de PC Compta
$attendu = array();
foreach (lire("$DOSSIER/controle.csv") as $c) $attendu[$c['compte']] = array((float) $c['debit'], (float) $c['credit']);
$res = q($db, "SELECT numero_compte, SUM(debit) AS d, SUM(credit) AS c FROM {$P}accounting_bookkeeping WHERE import_key = '$IMPORT_KEY' AND entity = $entite GROUP BY numero_compte");
$ecarts = 0; $td = 0;
while ($o = $db->fetch_object($res)) {
	$td += $o->d;
	if (!$ignorees && (abs($o->d - ($attendu[$o->numero_compte][0] ?? 0)) > 0.01 || abs($o->c - ($attendu[$o->numero_compte][1] ?? 0)) > 0.01)) $ecarts++;
}
etape("$lignes lignes importées".($ignorees ? ", $ignorees ignorées (déjà venues de Dolibarr)" : '').", total débit ".number_format($td, 2, ',', ' ')." DA, "
	.($ignorees ? 'contrôle par compte non applicable' : ($ecarts ? "$ecarts COMPTES EN ÉCART avec PC Compta" : 'balance identique à PC Compta compte par compte')));
