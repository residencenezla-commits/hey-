<?php
/*
 * Export des écritures saisies dans Dolibarr vers PC Compta (DLG), au format du fichier
 * d'import PC Compta : feuilles INFO.DOSSIER, JOURNAL, TAB_JRN, TAB_COM, TAB_AUX,
 * TAB_BDG, TAB_NAT, TAB_UNT, en .xls, .xlsx et .csv.
 *
 * Utilisation (depuis le dossier de l'ERP) :
 *   docker compose exec web php /var/www/scripts/outils/export_pccompta.php
 *       → toutes les écritures Dolibarr pas encore exportées
 *   … --du=2026-10-01 --au=2026-10-31        période précise
 *   … --journal=073                          un seul journal
 *   … --essai                                fichier produit sans marquer les écritures comme exportées
 * Sous Windows : double-clic sur exporter_pccompta.bat
 *
 * Les fichiers sont écrits dans le dossier exports/ de l'ERP.
 * Règles PC Compta reprises de vos écritures : FOLIO = mois, PIECE = numéro à 6 chiffres
 * continu par journal sur l'exercice (suite des pièces de PC Compta), REFERENCE 12 caractères,
 * LIBELLE 40 caractères en majuscules.
 */

require_once '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/includes/phpoffice/phpspreadsheet/src/autoloader.php';
require_once DOL_DOCUMENT_ROOT.'/includes/Psr/autoloader.php';
require_once PHPEXCELNEW_PATH.'Spreadsheet.php';
require_once __DIR__.'/pccompta_xls.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

$opt = array('du' => '', 'au' => '', 'journal' => '');
foreach (array_slice($argv, 1) as $a) {
	if (preg_match('/^--(du|au|journal)=(.+)$/', $a, $m)) $opt[$m[1]] = $m[2];
}
$essai = in_array('--essai', $argv);
$P = MAIN_DB_PREFIX;
$entite = (int) $conf->entity;
$IMPORT_KEY = 'PCCOMPTA';
$SORTIE = '/var/www/exports';

function maj40($t, $n = 40)
{
	$t = strtoupper(dol_string_unaccent((string) $t));
	$t = preg_replace('/[^A-Z0-9 \/\.\-\'%&,()]/', ' ', $t);
	return substr(trim(preg_replace('/\s+/', ' ', $t)), 0, $n);
}

/** Libellés dans le style de PC Compta (« TVA COLLECTEE 19 % FACTURE … », « DROIT DE TIMBRE FACTURE … »). */
function libelle_pccompta($l, $ref)
{
	$op = $l->label_operation;
	$c = $l->numero_compte;
	$parts = array_map('trim', explode(' - ', $op));
	$tiers = count($parts) >= 3 ? $parts[0] : '';
	if (preg_match('/^R[eè]glement client/iu', $op)) return 'ENCAISSEMENT FACTURE '.$ref.' '.preg_replace('/^.*\/\s*/', '', $op);
	if (preg_match('/^R[eè]glement fournisseur/iu', $op)) return 'REGLEMENT FACTURE '.$ref.' '.preg_replace('/^.*\/\s*/', '', $op);
	if (strpos($c, '4457') === 0 && preg_match('/(\d+(?:[.,]\d+)?)\s*%/', $op, $m)) return 'TVA COLLECTEE '.$m[1].' % FACTURE '.$ref;
	if (strpos($c, '4456') === 0) return 'TVA DEDUCTIBLE FACTURE '.$ref.' '.$tiers;
	if ($c === '44720') return 'DROIT DE TIMBRE FACTURE '.$ref;
	if ($c === '70900') return 'REMISE FACTURE '.$ref;
	if ($c === '70110') return 'VENTE BRIQUES FACTURE '.$ref;
	if ($l->subledger_account && preg_match('/^4[01]/', $c)) return 'FACTURE '.$ref.' '.$tiers;
	if (count($parts) >= 3) return $parts[2].' FACTURE '.$ref;
	return $op;
}

// ------------------------------------------------------------ Écritures à exporter
$where = "b.entity = $entite AND (b.import_key IS NULL OR b.import_key <> '$IMPORT_KEY')";
if (!$essai) $where .= " AND b.date_export IS NULL";
if ($opt['du']) $where .= " AND b.doc_date >= '".$db->escape($opt['du'])."'";
if ($opt['au']) $where .= " AND b.doc_date <= '".$db->escape($opt['au'])."'";
if ($opt['journal']) $where .= " AND b.code_journal = '".$db->escape($opt['journal'])."'";
$res = $db->query("SELECT b.rowid, b.piece_num, b.doc_date, b.doc_ref, b.code_journal, b.numero_compte, b.subledger_account, b.subledger_label,"
	." b.label_operation, b.debit, b.credit FROM {$P}accounting_bookkeeping b WHERE $where ORDER BY b.code_journal, b.doc_date, b.piece_num, b.rowid");
if (!$res) { print "ERREUR : ".$db->lasterror()."\n"; exit(1); }
$pieces = array();
while ($o = $db->fetch_object($res)) $pieces[$o->code_journal][$o->piece_num][] = $o;
if (!$pieces) { print "Aucune écriture à exporter (tout est déjà exporté ou la période est vide).\n"; exit(0); }

// Dernier n° de pièce par journal (pièces de PC Compta et exports précédents)
$dernier = array();
$res = $db->query("SELECT ref FROM {$P}accounting_bookkeeping WHERE entity = $entite AND ref REGEXP '^[0-9A-Z]+/[0-9]{6}$'");
while ($o = $db->fetch_object($res)) {
	list($j, $n) = explode('/', $o->ref);
	$dernier[$j] = max($dernier[$j] ?? 0, (int) $n);
}

// ------------------------------------------------------------ Lignes JOURNAL
$journal = array();
$auxUtilises = array();
$numerotation = array();  // rowid => ref attribuée
$nbPieces = 0;
foreach ($pieces as $jrn => $parPiece) {
	foreach ($parPiece as $num => $lignes) {
		$nbPieces++;
		$piece = sprintf('%06d', ($dernier[$jrn] = ($dernier[$jrn] ?? 0) + 1));
		$docref = $lignes[0]->doc_ref;
		$reference = preg_match('/\b((FA|AV|AC|FR|SI|FF)\d{4}-\d{4,})\b/', $docref, $m) ? $m[1] : $docref;
		$reference = substr(maj40($reference, 12), 0, 12);
		foreach ($lignes as $l) {
			$date = $db->jdate($l->doc_date);
			$libelle = libelle_pccompta($l, $reference);
			$journal[] = array(
				(string) ((int) dol_print_date($date, '%m')), $piece, dol_print_date($date, '%Y%m%d'), $reference, maj40($libelle),
				$jrn, $l->numero_compte, (string) $l->subledger_account, '', '', '',
				round((float) $l->debit, 2), round((float) $l->credit, 2), '    ', '', '', '', '        ',
			);
			if ($l->subledger_account) $auxUtilises[$l->subledger_account] = $l->subledger_label;
			$numerotation[$l->rowid] = $jrn.'/'.$piece;
		}
	}
}

// ------------------------------------------------------------ Tables
$dossier = array();
if (($h = @fopen('/var/www/scripts/docker-init.d/compta/dossier.csv', 'r'))) {
	fgetcsv($h, 0, ';', '"', '\\');
	while (($l = fgetcsv($h, 0, ';', '"', '\\')) !== false) if (count($l) == 2) $dossier[$l[0]] = $l[1];
	fclose($h);
}
$info = array();
foreach (array('NOM', 'RUE', 'VILLE', 'DEBUT_EXERCICE', 'FIN_EXERCICE', 'MAT_FISCALE', 'ART_IMPOSITION', 'NO_REG_COM', 'ACTIVITE', 'FORME JURIDIQUE') as $k) {
	$info[] = array($k, $dossier[$k] ?? ($k == 'NOM' ? getDolGlobalString('MAIN_INFO_SOCIETE_NOM') : ''));
}

$natureTexte = array(1 => 'Divers', 2 => 'Ventes', 3 => 'Achats', 4 => 'Trésorerie', 8 => 'Stock', 9 => 'Divers');
$tabJrn = array(array('', '', '', ''));
$res = $db->query("SELECT code, label, nature FROM {$P}accounting_journal WHERE entity = $entite AND active = 1 AND code NOT IN ('ER','INV') ORDER BY code");
while ($o = $db->fetch_object($res)) $tabJrn[] = array($o->code, $o->label, '', $natureTexte[$o->nature] ?? 'Divers');

$tabCom = array(array_fill(0, 13, ''));
$res = $db->query("SELECT a.account_number, a.label FROM {$P}accounting_account a WHERE a.entity = $entite AND a.fk_pcg_version = (SELECT pcg_version FROM {$P}accounting_system WHERE rowid = ".getDolGlobalInt('CHARTOFACCOUNTS').") ORDER BY a.account_number");
while ($o = $db->fetch_object($res)) {
	$auxCompte = preg_match('/^4[01]/', $o->account_number) ? 'X' : '';
	$tabCom[] = array($o->account_number, $o->label, '', $auxCompte, '', $auxCompte, '', '', '', '', '', '', '');
}

$tabAux = array(array_fill(0, 8, ''));
foreach ($auxUtilises as $code => $libelle) {
	$sql = "SELECT nom, address, town, ape, siret, siren FROM {$P}societe WHERE entity IN (".getEntity('societe').") AND (code_compta = '".$db->escape($code)."' OR code_compta_fournisseur = '".$db->escape($code)."') LIMIT 1";
	$s = ($r = $db->query($sql)) ? $db->fetch_object($r) : null;
	// Algérie : idprof1 (siren) = RC, idprof2 (siret) = NIF, idprof3 (ape) = AI
	$tabAux[] = array($code, maj40($s ? $s->nom : $libelle, 30), '', maj40($s ? $s->address : '', 30), maj40($s ? $s->town : '', 30),
		$s ? (string) $s->ape : '', $s ? (string) $s->siret : '', $s ? (string) $s->siren : '');
}
$divers = array(array_fill(0, 8, ''), array('00X00', 'CODE DIVERS CREE PAR PCCOMPTAP', '', '', '', '', '', ''));
$tabNat = array(array_fill(0, 8, ''), array('000', 'Divers', '', '', '', '', '', ''));

// ------------------------------------------------------------ Fichiers
$feuilles = array(
	'INFO.DOSSIER' => array(null, $info),
	'JOURNAL' => array(array('FOLIO', 'PIECE', 'DATE', 'REFERENCE', 'LIBELLE', 'CODE_JRN', 'CODE_COM', 'CODE_AUX', 'CODE_BDG', 'CODE_NAT', 'CODE_UNT', 'DEBIT', 'CREDIT', 'LETTRAGE', 'LIBELLEA', 'CTP_COM', 'CTP_AUX', 'ECHEANCE'), $journal),
	'TAB_JRN' => array(array('CODE', 'LIBELLE', 'LIBELLEA', 'NATURE'), $tabJrn),
	'TAB_COM' => array(array('CODE', 'LIBELLE', 'LIBELLEA', 'LETTRABLE', 'DEVISE', 'S/AUX', 'P/AUX', 'S/BDG', 'P/BDG', 'S/NAT', 'P/NAT', 'S/UNT', 'P/UNT'), $tabCom),
	'TAB_AUX' => array(array('CODE', 'LIBELLE', 'LIBELLEA', 'RUE', 'VILLE', 'ART_IMPOSITION', 'MAT_FISCALE', 'NO_REG_COM'), $tabAux),
	'TAB_BDG' => array(array('CODE', 'LIBELLE', 'LIBELLEA', 'RUE', 'VILLE', 'ART_IMPOSITION', 'MAT_FISCALE', 'NO_REG_COM'), $divers),
	'TAB_NAT' => array(array('CODE', 'LIBELLE', 'LIBELLEA', 'RUE', 'VILLE', 'ART_IMPOSITION', 'MAT_FISCALE', 'NO_REG_COM'), $tabNat),
	'TAB_UNT' => array(array('CODE', 'LIBELLE', 'LIBELLEA', 'RUE', 'VILLE', 'ART_IMPOSITION', 'MAT_FISCALE', 'NO_REG_COM'), $divers),
);
$classeur = new Spreadsheet();
$classeur->removeSheetByIndex(0);
foreach ($feuilles as $nom => list($entete, $lignes)) {
	$f = $classeur->createSheet();
	$f->setTitle($nom);
	$toutes = $entete ? array_merge(array($entete), $lignes) : $lignes;
	foreach ($toutes as $i => $ligne) {
		foreach (array_values($ligne) as $c => $v) {
			$cell = $f->getCellByColumnAndRow($c + 1, $i + 1);
			if (is_float($v) || is_int($v)) $cell->setValueExplicit($v, DataType::TYPE_NUMERIC);
			else $cell->setValueExplicit((string) $v, DataType::TYPE_STRING);
		}
	}
}
$classeur->setActiveSheetIndex(1);

if (!is_dir($SORTIE)) dol_mkdir($SORTIE);
$nom = 'EXPORT_PCCOMPTA_'.($opt['journal'] ? 'JRN'.$opt['journal'].'_' : '').dol_print_date(dol_now(), '%Y%m%d_%H%M').($essai ? '_ESSAI' : '');
// .xls au format binaire exact de PC Compta (voir pccompta_xls.php)
$pourXls = array();
foreach ($feuilles as $n => list($entete, $lignes)) $pourXls[] = array('nom' => $n, 'lignes' => $entete ? array_merge(array($entete), $lignes) : $lignes);
pcc_xls_ecrire($pourXls, "$SORTIE/$nom.xls");
IOFactory::createWriter($classeur, 'Xlsx')->save("$SORTIE/$nom.xlsx");
$h = fopen("$SORTIE/$nom.csv", 'w');
fputcsv($h, $feuilles['JOURNAL'][0], ';', '"', '\\');
foreach ($journal as $l) fputcsv($h, $l, ';', '"', '\\');
fclose($h);

// ------------------------------------------------------------ Marquage
if (!$essai) {
	$db->begin();
	foreach ($numerotation as $rowid => $ref) {
		$db->query("UPDATE {$P}accounting_bookkeeping SET ref = '".$db->escape($ref)."', date_export = '".$db->idate(dol_now())."' WHERE rowid = ".((int) $rowid));
	}
	$db->commit();
}

$td = array_sum(array_column($journal, 11));
$tc = array_sum(array_column($journal, 12));
print "Export PC Compta : $nbPieces pièce(s), ".count($journal)." ligne(s), débit ".number_format($td, 2, ',', ' ')." / crédit ".number_format($tc, 2, ',', ' ')." DA"
	.(abs($td - $tc) > 0.005 ? " — ATTENTION : NON ÉQUILIBRÉ" : '')."\n";
print "Fichiers : exports/$nom.xls, .xlsx, .csv".($essai ? "  (essai : écritures non marquées comme exportées)" : '')."\n";
