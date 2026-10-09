<?php
/*
 * Recharge dans Dolibarr un nouvel export de PC Compta (classeur DLG_COMPTA….xls ou .xlsx).
 *
 * Utilisation : déposez le fichier dans le dossier exports/ de l'ERP, puis
 *   docker compose exec web php /var/www/scripts/outils/importer_pccompta.php NOM_DU_FICHIER.xlsx
 * (sous Windows : double-clic sur importer_pccompta.bat)
 *
 * Même logique que convertir_compta.py : contrôle que chaque pièce est équilibrée, date la
 * réouverture au 1er jour de l'exercice, puis remplace les écritures venues de PC Compta
 * (les écritures saisies dans Dolibarr restent ; celles déjà envoyées à PC Compta ne sont pas doublées).
 */
require_once '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/includes/phpoffice/phpspreadsheet/src/autoloader.php';
require_once DOL_DOCUMENT_ROOT.'/includes/Psr/autoloader.php';
require_once PHPEXCELNEW_PATH.'Spreadsheet.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if ($argc < 2) { print "Usage : importer_pccompta.php FICHIER (déposé dans exports/)\n"; exit(1); }
$source = $argv[1][0] == '/' ? $argv[1] : '/var/www/exports/'.$argv[1];
if (!is_readable($source)) { print "Fichier introuvable : $source\n"; exit(1); }

$natures = array('Ventes' => 'vente', 'Achats' => 'achat', 'Trésorerie' => 'banque', 'Stock' => 'stock');
$corrections = array('05' => 'achat', '15' => 'achat', '18' => 'banque', '24' => 'banque');
// Les cellules vides de PC Compta sont des cellules numériques sans valeur, lues comme 0 :
// dans les colonnes de texte, un nombre nul est donc une cellule vide (les vrais codes « 0 » sont du texte).
$t = function ($v) { return ((is_int($v) || is_float($v)) && $v == 0) || $v === null ? '' : trim(preg_replace('/\s+/u', ' ', (string) $v)); };
$n = function ($v) { return round((float) $v, 2); };

print "Lecture de ".basename($source)."…\n";
$lecteur = IOFactory::createReaderForFile($source);
$lecteur->setReadDataOnly(true);
$cl = $lecteur->load($source);
$feuille = function ($nom) use ($cl) {
	$f = $cl->getSheetByName($nom);
	if (!$f) { print "Feuille $nom absente : ce n'est pas un export PC Compta.\n"; exit(1); }
	$l = $f->toArray(null, false, false, false);
	array_shift($l);
	return array_values(array_filter($l, function ($r) { return $r && !(((is_int($r[0]) || is_float($r[0])) && $r[0] == 0) || $r[0] === null || $r[0] === ''); }));
};

$dossier = array();
foreach ($cl->getSheetByName('INFO.DOSSIER')->toArray(null, false, false, false) as $r) if ($r[0]) $dossier[$t($r[0])] = $t($r[1]);
$debut = $dossier['DEBUT_EXERCICE'] ?? '20260101';
// Sécurité : le fichier doit être celui de la société de l'ERP (même NIF)
$nifFichier = preg_replace('/\D/', '', $dossier['MAT_FISCALE'] ?? '');
$nifSociete = preg_replace('/\D/', '', getDolGlobalString('MAIN_INFO_SIRET'));
if ($nifSociete && $nifFichier !== $nifSociete) {
	print "REFUSÉ : ce fichier est celui de « ".($dossier['NOM'] ?? '?')." » (NIF $nifFichier), pas de "
		.getDolGlobalString('MAIN_INFO_SOCIETE_NOM')." (NIF $nifSociete). Rien n'a été modifié.\n";
	exit(1);
}

$comptes = array();
foreach ($feuille('TAB_COM') as $r) $comptes[$t($r[0])] = $t($r[1]) !== '' ? $t($r[1]) : $t($r[0]);
ksort($comptes, SORT_STRING);
$journaux = array();
foreach ($feuille('TAB_JRN') as $r) {
	$code = $t($r[0]);
	$journaux[] = array($code, $t($r[1]), $corrections[$code] ?? ($natures[$t($r[count($r) - 1])] ?? ($natures[$t($r[7] ?? '')] ?? 'divers')));
}
$aux = array();
$feuilleAux = $feuille('TAB_AUX');
$entAux = $cl->getSheetByName('TAB_AUX')->toArray(null, false, false, false)[0];
$col = array_flip(array_map($t, $entAux));
foreach ($feuilleAux as $r) {
	$aux[$t($r[0])] = array($t($r[1]), $t($r[$col['RUE']] ?? ''), $t($r[$col['VILLE']] ?? ''), $t($r[$col['ART_IMPOSITION']] ?? ''), $t($r[$col['MAT_FISCALE']] ?? ''), $t($r[$col['NO_REG_COM']] ?? ''));
}
ksort($aux, SORT_STRING);

$entJ = array_map($t, $cl->getSheetByName('JOURNAL')->toArray(null, false, false, false)[0]);
$cj = array_flip($entJ);
$pieces = array();
foreach ($feuille('JOURNAL') as $r) {
	$g = function ($k) use ($r, $cj, $t) { return isset($cj[$k]) ? $t($r[$cj[$k]]) : ''; };
	$date = $g('DATE');
	if ($date < $debut) $date = $debut;
	$jrn = $g('CODE_JRN'); $piece = $g('PIECE'); $folio = $g('FOLIO'); $compte = $g('CODE_COM'); $codeAux = $g('CODE_AUX');
	$cle = $jrn.'|'.($piece !== '' ? $piece : 'F'.$folio);
	$pieces[$cle][] = array($jrn, $piece, $folio, $g('LIGNE'), $date, $g('REFERENCE'), $g('LIBELLE'), $compte, $comptes[$compte] ?? $compte,
		$codeAux, $codeAux !== '' ? ($aux[$codeAux][0] ?? '') : '', $g('CODE_NAT'), $n($r[$cj['DEBIT']]), $n($r[$cj['CREDIT']]));
}

$dir = '/var/www/exports/import_'.dol_print_date(dol_now(), '%Y%m%d_%H%M%S');
dol_mkdir($dir);
$ecrire = function ($nom, $entete, $lignes) use ($dir) {
	$h = fopen("$dir/$nom", 'w');
	fputcsv($h, $entete, ';', '"', '\\');
	foreach ($lignes as $l) fputcsv($h, $l, ';', '"', '\\');
	fclose($h);
};
$plan = array();
foreach ($comptes as $c => $l) $plan[] = array((string) $c, $l, substr((string) $c, 0, 1));
$ecrire('plan_comptable.csv', array('compte', 'libelle', 'classe'), $plan);
$ecrire('journaux.csv', array('code', 'libelle', 'nature'), $journaux);
$la = array();
foreach ($aux as $c => $v) $la[] = array_merge(array($c), $v);
$ecrire('auxiliaires.csv', array('code', 'libelle', 'rue', 'ville', 'ai', 'nif', 'rc'), $la);
$lignes = array(); $controle = array(); $deseq = array(); $num = 0;
foreach ($pieces as $cle => $lp) {
	$num++;
	if (abs(array_sum(array_map(function ($l) { return $l[12] - $l[13]; }, $lp))) > 0.005) $deseq[] = $cle;
	foreach ($lp as $l) {
		$lignes[] = array_merge(array($num), $l);
		$controle[$l[7]][0] = ($controle[$l[7]][0] ?? 0) + $l[12];
		$controle[$l[7]][1] = ($controle[$l[7]][1] ?? 0) + $l[13];
	}
}
if ($deseq) { print "Pièces non équilibrées : ".implode(', ', array_slice($deseq, 0, 10))." — import annulé.\n"; exit(1); }
$ecrire('ecritures.csv', array('piece_num', 'journal', 'piece', 'folio', 'ligne', 'date', 'reference', 'libelle', 'compte', 'libelle_compte', 'aux', 'libelle_aux', 'nature', 'debit', 'credit'), $lignes);
ksort($controle, SORT_STRING);
$lc = array();
foreach ($controle as $c => $v) $lc[] = array($c, sprintf('%.2f', $v[0]), sprintf('%.2f', $v[1]));
$ecrire('controle.csv', array('compte', 'debit', 'credit'), $lc);
$ld = array();
$dossier['FICHIER_SOURCE'] = basename($source);
ksort($dossier, SORT_STRING);
foreach ($dossier as $k => $v) $ld[] = array($k, $v);
$ecrire('dossier.csv', array('cle', 'valeur'), $ld);

print count($lignes)." lignes en ".count($pieces)." pièces équilibrées ; chargement dans Dolibarr…\n";
$argv = array('30-comptabilite.php', '--remplacer', '--dossier='.$dir);
include '/var/www/scripts/docker-init.d/30-comptabilite.php';
