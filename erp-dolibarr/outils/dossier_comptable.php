<?php
/*
 * Dossier comptable de l'exercice, calculé sur le grand livre de l'ERP
 * (écritures de PC Compta + écritures saisies dans l'ERP) :
 *   - bilan actif / passif et tableau des comptes de résultats (TCR) au format SCF ;
 *   - balance générale, balance des tiers, grand livre ;
 *   - aide à la préparation de la G50, mois par mois ;
 *   - contrôles (équilibre, actif = passif, résultat TCR = classe 7 − classe 6).
 *
 *   docker compose exec web php /var/www/scripts/outils/dossier_comptable.php [--au=AAAA-MM-JJ]
 *   (Windows : double-clic sur dossier_comptable.bat)
 *
 * Fichiers : exports/DOSSIER_COMPTABLE_<exercice>_<date>.xlsx (tous les états)
 *            exports/ETATS_FINANCIERS_<exercice>_<date>.html (à imprimer ou enregistrer en PDF)
 *
 * Le résultat est PROVISOIRE tant que les écritures d'inventaire (amortissements de l'année,
 * variation des stocks, provisions, IBS) ne sont pas passées.
 */
require_once '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/includes/phpoffice/phpspreadsheet/src/autoloader.php';
require_once DOL_DOCUMENT_ROOT.'/includes/Psr/autoloader.php';
require_once PHPEXCELNEW_PATH.'Spreadsheet.php';
require_once __DIR__.'/scf.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

$P = MAIN_DB_PREFIX;
$e = (int) $conf->entity;
$au = null;
foreach ($argv as $a) if (preg_match('/^--au=(\d{4}-\d{2}-\d{2})$/', $a, $m)) $au = $m[1];

$r = $db->query("SELECT label, date_start, date_end FROM {$P}accounting_fiscalyear WHERE entity = $e ORDER BY date_start DESC LIMIT 1");
$ex = $db->fetch_object($r);
if (!$ex) { print "Aucun exercice comptable.\n"; exit(1); }
$du = $ex->date_start;
$au = $au ?: $ex->date_end;

// ------------------------------------------------------------ Lecture du grand livre
$labels = array();
$r = $db->query("SELECT a.account_number, a.label FROM {$P}accounting_account a WHERE a.entity = $e AND a.fk_pcg_version = (SELECT pcg_version FROM {$P}accounting_system WHERE rowid = ".getDolGlobalInt('CHARTOFACCOUNTS').")");
while ($o = $db->fetch_object($r)) $labels[$o->account_number] = $o->label;
$reouv = array();
$r = $db->query("SELECT code FROM {$P}accounting_journal WHERE entity = $e AND nature = 9");
while ($o = $db->fetch_object($r)) $reouv[$o->code] = true;

$sql = "SELECT b.numero_compte AS compte, b.label_compte, b.subledger_account AS aux, b.subledger_label AS nom_aux, b.code_journal AS jrn,
	b.doc_date AS date, b.piece_num, b.doc_ref, b.ref, b.label_operation AS libelle, b.debit, b.credit
	FROM {$P}accounting_bookkeeping b WHERE b.entity = $e AND b.doc_date BETWEEN '$du' AND '$au'
	ORDER BY b.numero_compte, b.doc_date, b.piece_num, b.rowid";
$r = $db->query($sql);
$lignes = array();
$comptes = array();      // compte => [ouv_d, ouv_c, mvt_d, mvt_c]
$tiers = array();        // compte|aux => [compte, aux, nom, ouv_d, ouv_c, mvt_d, mvt_c]
$derniereDate = $du;
while ($o = $db->fetch_object($r)) {
	$d = (float) $o->debit; $c = (float) $o->credit;
	$o->date = substr($o->date, 0, 10);
	$lignes[] = $o;
	$ouv = isset($reouv[$o->jrn]);
	if (!isset($comptes[$o->compte])) $comptes[$o->compte] = array(0, 0, 0, 0);
	$comptes[$o->compte][$ouv ? 0 : 2] += $d;
	$comptes[$o->compte][$ouv ? 1 : 3] += $c;
	if (!isset($labels[$o->compte])) $labels[$o->compte] = $o->label_compte;
	if ($o->aux !== null && $o->aux !== '') {
		$k = $o->compte.'|'.$o->aux;
		if (!isset($tiers[$k])) $tiers[$k] = array($o->compte, $o->aux, $o->nom_aux, 0, 0, 0, 0);
		$tiers[$k][$ouv ? 3 : 5] += $d;
		$tiers[$k][$ouv ? 4 : 6] += $c;
		if (!$tiers[$k][2] && $o->nom_aux) $tiers[$k][2] = $o->nom_aux;
	}
	if (!$ouv && $o->date > $derniereDate) $derniereDate = $o->date;
}
ksort($comptes, SORT_STRING);
ksort($tiers, SORT_STRING);
$solde = function ($c) { return round($c[0] + $c[2] - $c[1] - $c[3], 2); };

// ------------------------------------------------------------ États SCF
$etats = scf_etats($comptes, $labels);
$bilanA = $etats['actif']; $bilanP = $etats['passif']; $tcr = $etats['tcr'];

// ------------------------------------------------------------ G50 (aide à la préparation)
$jVente = array(); $jPaie = array();
$r = $db->query("SELECT code, label, nature FROM {$P}accounting_journal WHERE entity = $e");
while ($o = $db->fetch_object($r)) {
	if ((int) $o->nature == 2) $jVente[$o->code] = true;
	if (stripos($o->label, 'PAIE') !== false) $jPaie[$o->code] = true;
}
$g50 = array();
foreach ($lignes as $o) {
	if (isset($reouv[$o->jrn])) continue;
	$m = substr($o->date, 0, 7);
	if (!isset($g50[$m])) $g50[$m] = array_fill_keys(array('ca', 'tva_col', 'timbre', 'tva_ded_bs', 'tva_ded_inv', 'irg', 'ibs', 'formation'), 0.0);
	$d = (float) $o->debit; $c = (float) $o->credit; $cp = $o->compte;
	if (strpos($cp, '70') === 0) $g50[$m]['ca'] += $c - $d;
	if (strpos($cp, '4457') === 0 && isset($jVente[$o->jrn])) $g50[$m]['tva_col'] += $c - $d;
	if ($cp === '44720' && isset($jVente[$o->jrn])) $g50[$m]['timbre'] += $c - $d;
	if ($cp === '44563') $g50[$m]['tva_ded_bs'] += $d;
	if ($cp === '44562') $g50[$m]['tva_ded_inv'] += $d;
	if ($cp === '44210' && isset($jPaie[$o->jrn])) $g50[$m]['irg'] += $c - $d;
	if ($cp === '44410') $g50[$m]['ibs'] += $d;
	if ($cp === '44740' && isset($jPaie[$o->jrn])) $g50[$m]['formation'] += $c - $d;
}
ksort($g50);

// ------------------------------------------------------------ Contrôles
$tD = 0; $tC = 0;
foreach ($comptes as $c) { $tD += $c[0] + $c[2]; $tC += $c[1] + $c[3]; }
$res7_6 = 0;
foreach ($comptes as $k => $c) { $k = (string) $k; if ($k[0] == '6' || $k[0] == '7') $res7_6 -= $solde($c); }
$controles = array(
	array('Total débit = total crédit du grand livre', $tD, $tC),
	array('Total actif net = total passif', $etats['total_actif'], $etats['total_passif']),
	array('Résultat du TCR = classe 7 − classe 6', $etats['resultat'], $res7_6),
	array('Comptes non classés dans le bilan ou le TCR', count($etats['non_classes']), 0),
);

// ------------------------------------------------------------ Classeur Excel
$x = new Spreadsheet();
$x->getDefaultStyle()->getFont()->setName('Arial')->setSize(9);
$fmt = '#,##0.00;[Red]-#,##0.00;""';
$feuille = function ($titre, $entete, $donnees, $largeurs, $styles = array()) use ($x, $fmt) {
	$f = $x->createSheet();
	$f->setTitle($titre);
	$f->fromArray($entete, null, 'A1');
	$f->getStyle('A1:'.chr(64 + count($entete)).'1')->applyFromArray(array(
		'font' => array('bold' => true, 'color' => array('rgb' => 'FFFFFF')),
		'fill' => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => '7D2A17'))));
	if ($donnees) $f->fromArray($donnees, null, 'A2', true);
	foreach ($largeurs as $i => $l) {
		$col = chr(65 + $i);
		$f->getColumnDimension($col)->setWidth(abs($l));
		if ($l < 0) $f->getStyle($col.'2:'.$col.(count($donnees) + 1))->getNumberFormat()->setFormatCode($fmt);
	}
	foreach ($styles as $ligne) $f->getStyle('A'.($ligne + 2).':'.chr(64 + count($entete)).($ligne + 2))->getFont()->setBold(true);
	$f->freezePane('A2');
	return $f;
};
$x->removeSheetByIndex(0);

// Bilan actif
$d = array(); $gras = array();
foreach ($bilanA as $l) { if ($l['total']) $gras[] = count($d); $d[] = array($l['libelle'], $l['brut'], $l['amort'], $l['net'], $l['comptes']); }
$feuille('Bilan actif', array('ACTIF', 'Brut', 'Amort. / provisions', 'Net', 'Comptes'), $d, array(48, -18, -18, -18, 60), $gras);
$d = array(); $gras = array();
foreach ($bilanP as $l) { if ($l['total']) $gras[] = count($d); $d[] = array($l['libelle'], $l['montant'], $l['comptes']); }
$feuille('Bilan passif', array('PASSIF', 'Montant', 'Comptes'), $d, array(52, -18, 60), $gras);
$d = array(); $gras = array();
foreach ($tcr as $l) { if ($l['total']) $gras[] = count($d); $d[] = array($l['libelle'], $l['montant'], $l['comptes']); }
$feuille('TCR', array('COMPTE DE RÉSULTAT (par nature)', 'Montant', 'Comptes'), $d, array(56, -18, 60), $gras);

// Balance générale
$d = array(); $gras = array(); $classe = null; $st = array_fill(0, 6, 0.0); $gt = array_fill(0, 6, 0.0);
$ajoutClasse = function () use (&$d, &$gras, &$classe, &$st) {
	if ($classe === null) return;
	$gras[] = count($d);
	$d[] = array('Total classe '.$classe, '', $st[0], $st[1], $st[2], $st[3], $st[4], $st[5]);
	$st = array_fill(0, 6, 0.0);
};
foreach ($comptes as $k => $c) {
	$k = (string) $k;  // les numéros de compte servent de clés : PHP les convertit en entiers
	if ($classe !== null && $k[0] !== $classe) $ajoutClasse();
	$classe = $k[0];
	$s = $solde($c);
	$ligne = array($c[0], $c[1], $c[2], $c[3], $s > 0 ? $s : 0, $s < 0 ? -$s : 0);
	foreach ($ligne as $i => $v) { $st[$i] += $v; $gt[$i] += $v; }
	$d[] = array_merge(array((string) $k, $labels[$k] ?? ''), $ligne);
}
$ajoutClasse();
$gras[] = count($d);
$d[] = array_merge(array('TOTAL GÉNÉRAL', ''), $gt);
$balance = $d; $balanceGras = $gras;
$feuille('Balance générale', array('Compte', 'Intitulé', 'Ouverture débit', 'Ouverture crédit', 'Mouvements débit', 'Mouvements crédit', 'Solde débiteur', 'Solde créditeur'), $d, array(10, 34, -16, -16, -16, -16, -16, -16), $gras);

// Balance des tiers
$d = array();
foreach ($tiers as $t) {
	$s = round($t[3] + $t[5] - $t[4] - $t[6], 2);
	$d[] = array((string) $t[0], (string) $t[1], $t[2], $t[3], $t[4], $t[5], $t[6], $s > 0 ? $s : 0, $s < 0 ? -$s : 0);
}
$feuille('Balance des tiers', array('Compte', 'Code tiers', 'Nom', 'Ouverture débit', 'Ouverture crédit', 'Mouvements débit', 'Mouvements crédit', 'Solde débiteur', 'Solde créditeur'), $d, array(9, 10, 34, -15, -15, -15, -15, -15, -15));

// Grand livre
$d = array(); $courant = null; $cumul = 0;
foreach ($lignes as $o) {
	if ($o->compte !== $courant) { $courant = $o->compte; $cumul = 0; }
	$cumul += (float) $o->debit - (float) $o->credit;
	$d[] = array((string) $o->compte, $o->date, (string) $o->jrn, (string) ($o->ref ?: $o->piece_num), (string) $o->doc_ref, (string) $o->aux, (string) $o->libelle, (float) $o->debit, (float) $o->credit, round($cumul, 2));
}
$feuille('Grand livre', array('Compte', 'Date', 'Journal', 'Pièce', 'Référence', 'Tiers', 'Libellé', 'Débit', 'Crédit', 'Solde cumulé'), $d, array(9, 11, 7, 12, 14, 9, 42, -15, -15, -16));

// G50
$d = array();
foreach ($g50 as $m => $v) {
	$tvaNette = $v['tva_col'] - $v['tva_ded_bs'] - $v['tva_ded_inv'];
	$d[] = array($m, $v['ca'], $v['tva_col'], $v['tva_ded_bs'], $v['tva_ded_inv'], $tvaNette, $v['timbre'], $v['irg'], $v['formation'], $v['ibs']);
}
$feuille('G50 (préparation)', array('Mois', 'Chiffre d\'affaires HT (70)', 'TVA collectée (4457)', 'TVA déductible biens et services (44563)', 'TVA déductible investissements (44562)', 'TVA nette (+ à payer / − crédit)', 'Droit de timbre (44720)', 'IRG salaires retenu (44210)', 'Taxe de formation (44740)', 'Acomptes IBS versés (44410)'), $d, array(9, -18, -16, -18, -18, -18, -16, -16, -16, -16));

// Contrôles
$d = array();
foreach ($controles as $c) $d[] = array($c[0], $c[1], $c[2], abs($c[1] - $c[2]) < 0.01 ? 'OK' : 'ÉCART');
foreach ($etats['non_classes'] as $nc) $d[] = array('Compte non classé : '.$nc, '', '', '');
$d[] = array('Période : du '.dol_print_date(strtotime($du), '%d/%m/%Y').' au '.dol_print_date(strtotime($au), '%d/%m/%Y').' — dernière écriture (hors réouverture) : '.dol_print_date(strtotime($derniereDate), '%d/%m/%Y'), '', '', '');
$d[] = array('Résultat provisoire : avant écritures d\'inventaire (amortissements de l\'exercice, variation des stocks, provisions, IBS).', '', '', '');
$feuille('Contrôles', array('Contrôle', 'Valeur 1', 'Valeur 2', 'Résultat'), $d, array(80, -20, -20, 10));
$x->setActiveSheetIndex(0);

if (!is_dir('/var/www/exports')) dol_mkdir('/var/www/exports');
$suffixe = $ex->label.'_'.str_replace('-', '', $au === $ex->date_end ? substr($derniereDate, 0, 10) : $au);
$xlsx = "/var/www/exports/DOSSIER_COMPTABLE_$suffixe.xlsx";
IOFactory::createWriter($x, 'Xlsx')->save($xlsx);

// ------------------------------------------------------------ Page imprimable (bilan, TCR, G50, balance)
$societe = getDolGlobalString('MAIN_INFO_SOCIETE_NOM');
$infos = array(
	'nif' => getDolGlobalString('MAIN_INFO_SIRET'), 'rc' => getDolGlobalString('MAIN_INFO_SIREN'), 'ai' => getDolGlobalString('MAIN_INFO_APE'),
	'adresse' => trim(preg_replace('/\s+/', ' ', getDolGlobalString('MAIN_INFO_SOCIETE_ADDRESS').' '.getDolGlobalString('MAIN_INFO_SOCIETE_ZIP').' '.getDolGlobalString('MAIN_INFO_SOCIETE_TOWN'))),
);
$html = scf_html($societe, $infos, $ex->label, $du, $au, $derniereDate, $bilanA, $bilanP, $tcr, $g50, $balance, $balanceGras, $controles, $etats);
$htmlf = "/var/www/exports/ETATS_FINANCIERS_$suffixe.html";
file_put_contents($htmlf, $html);

$ok = true;
foreach ($controles as $c) $ok = $ok && abs($c[1] - $c[2]) < 0.01;
print "Dossier comptable $ex->label (au ".dol_print_date(strtotime($au), '%d/%m/%Y').", dernière écriture ".dol_print_date(strtotime($derniereDate), '%d/%m/%Y').")\n";
print "  Total actif ".number_format($etats['total_actif'], 2, ',', ' ')." = total passif ".number_format($etats['total_passif'], 2, ',', ' ')." DA\n";
print "  Résultat provisoire ".number_format($etats['resultat'], 2, ',', ' ')." DA\n";
print "  Contrôles : ".($ok ? 'tous OK' : 'ÉCARTS — voir l\'onglet Contrôles')."\n";
print "  Fichiers : exports/".basename($xlsx)." et exports/".basename($htmlf)."\n";
