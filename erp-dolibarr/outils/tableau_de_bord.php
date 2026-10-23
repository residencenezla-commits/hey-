<?php
/*
 * Tableau de bord de gestion de SARL DPR AXXAM, calculé sur le grand livre de l'ERP
 * (écritures de PC Compta + écritures saisies dans l'ERP).
 *
 *   docker compose exec web php /var/www/scripts/outils/tableau_de_bord.php
 *   (Windows : double-clic sur tableau_de_bord.bat)
 *
 * Produit exports/TABLEAU_DE_BORD_AAAAMMJJ.html : une page autonome (sans Internet),
 * à ouvrir dans le navigateur, imprimer ou envoyer.
 */
require_once '/var/www/html/master.inc.php';

$P = MAIN_DB_PREFIX;
$e = (int) $conf->entity;
function lignes($db, $sql)
{
	$r = $db->query($sql);
	if (!$r) { print "ERREUR SQL : ".$db->lasterror()."\n"; exit(1); }
	$t = array();
	while ($o = $db->fetch_object($r)) $t[] = $o;
	return $t;
}

$ex = lignes($db, "SELECT label, date_start, date_end FROM {$P}accounting_fiscalyear WHERE entity = $e ORDER BY date_start DESC LIMIT 1");
if (!$ex) { print "Aucun exercice comptable.\n"; exit(1); }
$du = $ex[0]->date_start; $au = $ex[0]->date_end;
$per = "b.entity = $e AND b.doc_date BETWEEN '$du' AND '$au'";
$horsReouv = "$per AND b.code_journal <> '01'";
$journauxVente = "(SELECT code FROM {$P}accounting_journal WHERE entity = $e AND nature = 2)";

// Chiffre d'affaires mensuel par compte de ventes 70
$ca = lignes($db, "SELECT DATE_FORMAT(b.doc_date, '%Y-%m') AS mois, b.numero_compte AS compte, MAX(b.label_compte) AS libelle,
	SUM(b.credit - b.debit) AS montant FROM {$P}accounting_bookkeeping b WHERE $horsReouv AND b.numero_compte LIKE '70%'
	GROUP BY mois, compte ORDER BY mois, compte");

// TVA et timbre par mois : TVA collectée et timbre des journaux de vente, TVA déductible brute
// (les écritures de règlement G50 et de régularisation ne viennent pas en déduction)
$taxes = lignes($db, "SELECT DATE_FORMAT(b.doc_date, '%Y-%m') AS mois,
	SUM(CASE WHEN b.numero_compte LIKE '4457%' AND b.code_journal IN $journauxVente THEN b.credit - b.debit ELSE 0 END) AS tva_collectee,
	SUM(CASE WHEN b.numero_compte LIKE '4456%' THEN b.debit ELSE 0 END) AS tva_deductible,
	SUM(CASE WHEN b.numero_compte = '44720' AND b.code_journal IN $journauxVente THEN b.credit - b.debit ELSE 0 END) AS timbre
	FROM {$P}accounting_bookkeeping b WHERE $horsReouv GROUP BY mois ORDER BY mois");

// Trésorerie : solde de chaque compte 51x / 53x (réouverture comprise)
$treso = lignes($db, "SELECT b.numero_compte AS compte, MAX(b.label_compte) AS libelle, SUM(b.debit - b.credit) AS solde
	FROM {$P}accounting_bookkeeping b WHERE $per AND (b.numero_compte LIKE '51%' OR b.numero_compte LIKE '53%')
	GROUP BY compte HAVING ABS(solde) >= 1 ORDER BY compte");

// Soldes de tiers et résultat
$s = lignes($db, "SELECT
	SUM(CASE WHEN b.numero_compte LIKE '411%' THEN b.debit - b.credit ELSE 0 END) AS clients,
	SUM(CASE WHEN b.numero_compte LIKE '401%' OR b.numero_compte LIKE '404%' OR b.numero_compte LIKE '408%' THEN b.credit - b.debit ELSE 0 END) AS fournisseurs,
	SUM(CASE WHEN b.numero_compte LIKE '7%' AND b.code_journal <> '01' THEN b.credit - b.debit ELSE 0 END) AS produits,
	SUM(CASE WHEN b.numero_compte LIKE '6%' AND b.code_journal <> '01' THEN b.debit - b.credit ELSE 0 END) AS charges
	FROM {$P}accounting_bookkeeping b WHERE $per")[0];

// Principaux clients des ventes briqueterie (journal 073) : montant facturé TTC
$clients = lignes($db, "SELECT b.subledger_account AS code, MAX(b.subledger_label) AS nom, SUM(b.debit) AS facture, COUNT(DISTINCT b.piece_num) AS factures
	FROM {$P}accounting_bookkeeping b WHERE $per AND b.code_journal = '073' AND b.numero_compte LIKE '411%' AND b.subledger_account IS NOT NULL
	GROUP BY b.subledger_account ORDER BY facture DESC LIMIT 10");

// Principales charges
$charges = lignes($db, "SELECT b.numero_compte AS compte, MAX(b.label_compte) AS libelle, SUM(b.debit - b.credit) AS montant
	FROM {$P}accounting_bookkeeping b WHERE $horsReouv AND b.numero_compte LIKE '6%' GROUP BY compte ORDER BY montant DESC LIMIT 10");

// Dernière écriture par journal (pour signaler les mois incomplets)
$derniers = lignes($db, "SELECT b.code_journal AS code, MAX(j.label) AS libelle, MAX(b.doc_date) AS derniere, COUNT(*) AS lignes
	FROM {$P}accounting_bookkeeping b LEFT JOIN {$P}accounting_journal j ON j.code = b.code_journal AND j.entity = $e
	WHERE $per AND b.code_journal <> '01' GROUP BY b.code_journal ORDER BY b.code_journal");

$origine = lignes($db, "SELECT SUM(CASE WHEN import_key = 'PCCOMPTA' THEN 1 ELSE 0 END) AS pcc, SUM(CASE WHEN import_key IS NULL OR import_key <> 'PCCOMPTA' THEN 1 ELSE 0 END) AS erp
	FROM {$P}accounting_bookkeeping b WHERE $per")[0];

$donnees = array(
	'societe' => getDolGlobalString('MAIN_INFO_SOCIETE_NOM'),
	'exercice' => $ex[0]->label, 'du' => $du, 'au' => $au,
	'genere' => dol_print_date(dol_now(), '%d/%m/%Y %H:%M'),
	'lignes_pcc' => (int) $origine->pcc, 'lignes_erp' => (int) $origine->erp,
	'ca' => $ca, 'taxes' => $taxes, 'treso' => $treso, 'soldes' => $s,
	'clients' => $clients, 'charges' => $charges, 'derniers' => $derniers,
);
$json = json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

$html = file_get_contents(__DIR__.'/tableau_de_bord.html');
$html = str_replace('/*DONNEES*/null', $json, $html);
if (!is_dir('/var/www/exports')) dol_mkdir('/var/www/exports');
$nom = 'TABLEAU_DE_BORD_'.dol_print_date(dol_now(), '%Y%m%d').'.html';
file_put_contents("/var/www/exports/$nom", $html);
print "Tableau de bord : exports/$nom (exercice {$ex[0]->label}, ".($origine->pcc + $origine->erp)." lignes d'écritures)\n";
