<?php
/*
 * Classement des comptes dans les états financiers du SCF (système comptable financier algérien) :
 * bilan actif, bilan passif, tableau des comptes de résultats par nature (TCR).
 *
 * Règles :
 *  - immobilisations (classe 2) : brut en actif, amortissements 28x / pertes de valeur 29x en
 *    déduction de la rubrique correspondante ;
 *  - comptes de tiers 40 à 48 et trésorerie 51 à 58 : classés selon le sens du solde de chaque
 *    compte (débiteur à l'actif, créditeur au passif) ;
 *  - classe 1 : capitaux propres ou passifs non courants, en montant net de la rubrique ;
 *  - résultat de l'exercice = produits (classe 7) − charges (classe 6), repris au passif.
 */

/** Rubriques dans l'ordre de présentation du SCF. */
function scf_rubriques()
{
	return array(
		'actif' => array(
			array('ACTIF NON COURANT', null),
			array('Écart d\'acquisition (goodwill)', 'goodwill'),
			array('Immobilisations incorporelles', 'incorp'),
			array('Immobilisations corporelles', null),
			array('    Terrains', 'terrains'),
			array('    Bâtiments', 'batiments'),
			array('    Autres immobilisations corporelles', 'autres_corp'),
			array('    Immobilisations en concession', 'concession'),
			array('Immobilisations en cours', 'en_cours'),
			array('Immobilisations financières', null),
			array('    Titres mis en équivalence', 'titres_eq'),
			array('    Autres participations et créances rattachées', 'participations'),
			array('    Autres titres immobilisés', 'titres_immo'),
			array('    Prêts et autres actifs financiers non courants', 'prets'),
			array('    Impôts différés actif', 'impots_diff_a'),
			array('TOTAL ACTIF NON COURANT', '=anc'),
			array('ACTIF COURANT', null),
			array('Stocks et encours', 'stocks'),
			array('Créances et emplois assimilés', null),
			array('    Clients', 'clients'),
			array('    Autres débiteurs', 'autres_debiteurs'),
			array('    Impôts et assimilés', 'impots_a'),
			array('    Autres créances et emplois assimilés', 'autres_creances'),
			array('Disponibilités et assimilés', null),
			array('    Placements et autres actifs financiers courants', 'placements'),
			array('    Trésorerie', 'tresorerie_a'),
			array('TOTAL ACTIF COURANT', '=ac'),
			array('TOTAL GÉNÉRAL ACTIF', '=actif'),
		),
		'passif' => array(
			array('CAPITAUX PROPRES', null),
			array('Capital émis', 'capital'),
			array('Capital non appelé', 'capital_na'),
			array('Primes et réserves', 'reserves'),
			array('Écart de réévaluation', 'reevaluation'),
			array('Écart d\'équivalence', 'equivalence'),
			array('Résultat net de l\'exercice (provisoire)', 'resultat'),
			array('Autres capitaux propres — report à nouveau', 'report'),
			array('    dont résultat antérieur en instance d\'affectation (12)', 'resultat_ant'),
			array('TOTAL CAPITAUX PROPRES', '=cp'),
			array('PASSIFS NON COURANTS', null),
			array('Emprunts et dettes financières', 'emprunts'),
			array('Impôts (différés et provisionnés)', 'impots_diff_p'),
			array('Autres dettes non courantes', 'autres_dettes_nc'),
			array('Provisions et produits constatés d\'avance', 'provisions'),
			array('TOTAL PASSIFS NON COURANTS', '=pnc'),
			array('PASSIFS COURANTS', null),
			array('Fournisseurs et comptes rattachés', 'fournisseurs'),
			array('Impôts', 'impots_p'),
			array('Autres dettes', 'autres_dettes'),
			array('Trésorerie passif', 'tresorerie_p'),
			array('TOTAL PASSIFS COURANTS', '=pc'),
			array('TOTAL GÉNÉRAL PASSIF', '=passif'),
		),
	);
}

/**
 * Rubrique d'un compte : [côté A/P/R, rubrique, colonne brut|amort], côté R = TCR (classes 6 et 7).
 * $s = solde débiteur (+) ou créditeur (−).
 */
function scf_classer($c, $s)
{
	$p = function () use ($c) { foreach (func_get_args() as $x) if (strpos($c, $x) === 0) return true; return false; };
	if ($c[0] == '6' || $c[0] == '7') return array('R', null, null);
	// Immobilisations : amortissements et pertes de valeur d'abord
	if ($c === '28122') return array('A', 'concession', 'amort');            // amortissement du gisement d'argile (22101)
	if ($p('2807', '2907')) return array('A', 'goodwill', 'amort');
	if ($p('280', '290')) return array('A', 'incorp', 'amort');
	if ($p('2811', '2812', '2911', '2912')) return array('A', 'terrains', 'amort');
	if ($p('2813', '2814', '2913', '2914')) return array('A', 'batiments', 'amort');
	if ($p('2815', '2818', '2915', '2918')) return array('A', 'autres_corp', 'amort');
	if ($p('282', '292')) return array('A', 'concession', 'amort');
	if ($p('293')) return array('A', 'en_cours', 'amort');
	if ($p('296')) return array('A', 'participations', 'amort');
	if ($p('297')) return array('A', 'prets', 'amort');
	if ($p('207')) return array('A', 'goodwill', 'brut');
	if ($p('20')) return array('A', 'incorp', 'brut');
	if ($p('211', '212')) return array('A', 'terrains', 'brut');
	if ($p('213', '214')) return array('A', 'batiments', 'brut');
	if ($p('21')) return array('A', 'autres_corp', 'brut');
	if ($p('22')) return array('A', 'concession', 'brut');
	if ($p('23')) return array('A', 'en_cours', 'brut');
	if ($p('265')) return array('A', 'titres_eq', 'brut');
	if ($p('26')) return array('A', 'participations', 'brut');
	if ($p('271', '272', '273')) return array('A', 'titres_immo', 'brut');
	if ($p('27')) return array('A', 'prets', 'brut');
	if ($p('133')) return array('A', 'impots_diff_a', 'brut');
	// Classe 1
	if ($p('109')) return array('P', 'capital_na', null);
	if ($p('10') && $p('101', '102', '103')) return array('P', 'capital', null);
	if ($p('104', '105')) return array('P', 'reevaluation', null);
	if ($p('107')) return array('P', 'equivalence', null);
	if ($p('10')) return array('P', 'reserves', null);
	if ($p('11')) return array('P', 'report', null);
	if ($p('12')) return array('P', 'resultat_ant', null);
	if ($p('134', '155')) return array('P', 'impots_diff_p', null);
	if ($p('13', '15')) return array('P', 'provisions', null);
	if ($p('16', '17')) return array('P', 'emprunts', null);
	if ($p('229')) return array('P', 'autres_dettes_nc', null);
	// Stocks
	if ($p('39')) return array('A', 'stocks', 'amort');
	if ($c[0] == '3') return array('A', 'stocks', 'brut');
	// Tiers : selon le sens du solde
	if ($p('49')) return array('A', 'autres_debiteurs', 'amort');
	if ($p('419')) return $s >= 0 ? array('A', 'autres_debiteurs', 'brut') : array('P', 'autres_dettes', null);
	if ($p('41')) return $s >= 0 ? array('A', 'clients', 'brut') : array('P', 'autres_dettes', null);
	if ($p('40')) return $s < 0 ? array('P', 'fournisseurs', null) : array('A', 'autres_debiteurs', 'brut');
	if ($p('44')) return $s >= 0 ? array('A', 'impots_a', 'brut') : array('P', 'impots_p', null);
	if ($p('42', '43', '45', '46', '47', '48')) return $s >= 0 ? array('A', 'autres_debiteurs', 'brut') : array('P', 'autres_dettes', null);
	// Trésorerie
	if ($p('59')) return array('A', 'tresorerie_a', 'amort');
	if ($p('50')) return array('A', 'placements', 'brut');
	if ($c[0] == '5') return $s >= 0 ? array('A', 'tresorerie_a', 'brut') : array('P', 'tresorerie_p', null);
	return null;
}

/** Lignes du TCR SCF (par nature) : [libellé, préfixes de comptes ou formule, signe] */
function scf_tcr_modele()
{
	return array(
		array('Chiffre d\'affaires', array('70'), 1),
		array('Variation stocks produits finis et en cours', array('72'), 1),
		array('Production immobilisée', array('73'), 1),
		array('Subventions d\'exploitation', array('74'), 1),
		array('I — PRODUCTION DE L\'EXERCICE', '=I', 0),
		array('Achats consommés', array('60'), -1),
		array('Services extérieurs et autres consommations', array('61', '62'), -1),
		array('II — CONSOMMATION DE L\'EXERCICE', '=II', 0),
		array('III — VALEUR AJOUTÉE D\'EXPLOITATION (I − II)', '=III', 0),
		array('Charges de personnel', array('63'), -1),
		array('Impôts, taxes et versements assimilés', array('64'), -1),
		array('IV — EXCÉDENT BRUT D\'EXPLOITATION', '=IV', 0),
		array('Autres produits opérationnels', array('75'), 1),
		array('Autres charges opérationnelles', array('65'), -1),
		array('Dotations aux amortissements, provisions et pertes de valeur', array('68'), -1),
		array('Reprise sur pertes de valeur et provisions', array('78'), 1),
		array('V — RÉSULTAT OPÉRATIONNEL', '=V', 0),
		array('Produits financiers', array('76'), 1),
		array('Charges financières', array('66'), -1),
		array('VI — RÉSULTAT FINANCIER', '=VI', 0),
		array('VII — RÉSULTAT ORDINAIRE AVANT IMPÔTS (V + VI)', '=VII', 0),
		array('Impôts exigibles sur résultats ordinaires', array('695', '698'), -1),
		array('Impôts différés (variations) sur résultats ordinaires', array('692', '693'), -1),
		array('VIII — TOTAL DES PRODUITS DES ACTIVITÉS ORDINAIRES', '=VIII', 0),
		array('IX — TOTAL DES CHARGES DES ACTIVITÉS ORDINAIRES', '=IX', 0),
		array('X — RÉSULTAT NET DES ACTIVITÉS ORDINAIRES', '=X', 0),
		array('Éléments extraordinaires (produits)', array('77'), 1),
		array('Éléments extraordinaires (charges)', array('67'), -1),
		array('XI — RÉSULTAT EXTRAORDINAIRE', '=XI', 0),
		array('XII — RÉSULTAT NET DE L\'EXERCICE', '=XII', 0),
	);
}

function scf_etats($comptes, $labels)
{
	$val = array(); $amort = array(); $liste = array(); $nonClasses = array();
	$soldeR = array();
	foreach ($comptes as $c => $m) {
		$c = (string) $c;
		$s = round($m[0] + $m[2] - $m[1] - $m[3], 2);
		if (abs($s) < 0.005) continue;
		$cl = scf_classer($c, $s);
		if ($cl === null) { $nonClasses[] = $c.' '.($labels[$c] ?? ''); continue; }
		if ($cl[0] == 'R') { $soldeR[$c] = $s; continue; }
		$k = $cl[1];
		if ($cl[0] == 'A') {
			if ($cl[2] == 'amort') $amort[$k] = ($amort[$k] ?? 0) - $s;   // amortissements : montant positif
			else $val[$k] = ($val[$k] ?? 0) + $s;
		} else {
			$val[$k] = ($val[$k] ?? 0) - $s;                               // passif : montant positif si créditeur
		}
		$liste[$k][] = $c;
	}

	// TCR
	$tcr = array(); $v = array(); $comptesTcr = array();
	$somme = function ($prefixes, $signe) use ($soldeR, &$comptesTcr) {
		$t = 0; $cs = array();
		foreach ($soldeR as $c => $s) foreach ($prefixes as $p) if (strpos($c, $p) === 0) { $t += -$s * ($signe > 0 ? 1 : -1); $cs[] = $c; break; }
		return array($t, $cs);
	};
	$montants = array();
	foreach (scf_tcr_modele() as $i => $l) {
		if (is_array($l[1])) { list($montants[$i], $cs) = $somme($l[1], $l[2]); foreach ($cs as $c) $comptesTcr[$c] = true; }
	}
	$m = $montants;
	$I = $m[0] + $m[1] + $m[2] + $m[3]; $II = $m[5] + $m[6]; $III = $I - $II; $IV = $III - $m[9] - $m[10];
	$V = $IV + $m[12] - $m[13] - $m[14] + $m[15]; $VI = $m[17] - $m[18]; $VII = $V + $VI;
	$VIII = $I + $m[12] + $m[15] + $m[17]; $IX = $II + $m[9] + $m[10] + $m[13] + $m[14] + $m[18] + $m[21] + $m[22];
	$X = $VIII - $IX; $XI = $m[26] - $m[27]; $XII = $X + $XI;
	$formules = array('=I' => $I, '=II' => $II, '=III' => $III, '=IV' => $IV, '=V' => $V, '=VI' => $VI, '=VII' => $VII, '=VIII' => $VIII, '=IX' => $IX, '=X' => $X, '=XI' => $XI, '=XII' => $XII);
	foreach (scf_tcr_modele() as $i => $l) {
		if (is_array($l[1])) {
			$cs = array();
			foreach ($soldeR as $c => $s) foreach ($l[1] as $p) if (strpos($c, $p) === 0) { $cs[] = $c; break; }
			$tcr[] = array('libelle' => $l[0], 'montant' => round($montants[$i], 2), 'comptes' => implode(', ', $cs), 'total' => false);
		} else {
			$tcr[] = array('libelle' => $l[0], 'montant' => round($formules[$l[1]], 2), 'comptes' => '', 'total' => true);
		}
	}
	foreach ($soldeR as $c => $s) if (!isset($comptesTcr[$c])) $nonClasses[] = $c.' '.($labels[$c] ?? '').' (TCR)';
	$val['resultat'] = round($XII, 2);
	$liste['resultat'] = array('classes 6 et 7');

	// Bilan
	$rub = scf_rubriques();
	$groupes = array('=anc' => array('goodwill', 'incorp', 'terrains', 'batiments', 'autres_corp', 'concession', 'en_cours', 'titres_eq', 'participations', 'titres_immo', 'prets', 'impots_diff_a'),
		'=ac' => array('stocks', 'clients', 'autres_debiteurs', 'impots_a', 'autres_creances', 'placements', 'tresorerie_a'),
		'=cp' => array('capital', 'capital_na', 'reserves', 'reevaluation', 'equivalence', 'resultat', 'report', 'resultat_ant'),
		'=pnc' => array('emprunts', 'impots_diff_p', 'autres_dettes_nc', 'provisions'),
		'=pc' => array('fournisseurs', 'impots_p', 'autres_dettes', 'tresorerie_p'));
	$groupes['=actif'] = array_merge($groupes['=anc'], $groupes['=ac']);
	$groupes['=passif'] = array_merge($groupes['=cp'], $groupes['=pnc'], $groupes['=pc']);
	// Le résultat antérieur (12) est présenté avec le report à nouveau (ligne « dont »)
	$actif = array();
	foreach ($rub['actif'] as $r) {
		if ($r[1] === null) { $actif[] = array('libelle' => $r[0], 'brut' => null, 'amort' => null, 'net' => null, 'comptes' => '', 'total' => true, 'titre' => true); continue; }
		$ks = $r[1][0] == '=' ? $groupes[$r[1]] : array($r[1]);
		$b = 0; $a = 0; $cs = array();
		foreach ($ks as $k) { $b += $val[$k] ?? 0; $a += $amort[$k] ?? 0; if ($r[1][0] != '=') $cs = array_merge($cs, $liste[$k] ?? array()); }
		$actif[] = array('libelle' => $r[0], 'brut' => round($b, 2), 'amort' => round($a, 2), 'net' => round($b - $a, 2), 'comptes' => implode(', ', $cs), 'total' => $r[1][0] == '=', 'titre' => false);
	}
	$passif = array();
	foreach ($rub['passif'] as $r) {
		if ($r[1] === null) { $passif[] = array('libelle' => $r[0], 'montant' => null, 'comptes' => '', 'total' => true, 'titre' => true); continue; }
		$ks = $r[1][0] == '=' ? $groupes[$r[1]] : array($r[1]);
		$t = 0; $cs = array();
		foreach ($ks as $k) { $t += $val[$k] ?? 0; if ($r[1][0] != '=') $cs = array_merge($cs, $liste[$k] ?? array()); }
		if ($r[1] === 'report') { $t += $val['resultat_ant'] ?? 0; $cs = array_merge($cs, $liste['resultat_ant'] ?? array()); }
		if ($r[1] === 'resultat_ant') $cs = array();
		$passif[] = array('libelle' => $r[0], 'montant' => round($t, 2), 'comptes' => implode(', ', $cs), 'total' => $r[1][0] == '=', 'titre' => false, 'dont' => $r[1] === 'resultat_ant');
	}
	// Les totaux de passif ne doivent compter « resultat_ant » qu'une fois (inclus dans le report)
	$totalActif = end($actif)['net'];
	$totalPassif = end($passif)['montant'];
	return array('actif' => $actif, 'passif' => $passif, 'tcr' => $tcr, 'resultat' => round($XII, 2),
		'total_actif' => $totalActif, 'total_passif' => $totalPassif, 'non_classes' => $nonClasses);
}

function scf_html($societe, $infos, $exercice, $du, $au, $derniere, $actif, $passif, $tcr, $g50, $balance, $balanceGras, $controles, $etats)
{
	$n = function ($v) { return $v === null ? '' : ($v == 0 ? '—' : number_format($v, 2, ',', ' ')); };
	$h = function ($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); };
	$d = function ($x) { return substr($x, 8, 2).'/'.substr($x, 5, 2).'/'.substr($x, 0, 4); };
	$mois = array('01' => 'Janvier', '02' => 'Février', '03' => 'Mars', '04' => 'Avril', '05' => 'Mai', '06' => 'Juin', '07' => 'Juillet', '08' => 'Août', '09' => 'Septembre', '10' => 'Octobre', '11' => 'Novembre', '12' => 'Décembre');
	$entete = '<div class="entete"><div><b>'.$h($societe).'</b><br>'.$h($infos['adresse']).'</div><div class="id">NIF '.$h($infos['nif']).'<br>RC '.$h($infos['rc']).'<br>AI '.$h($infos['ai']).'</div></div>';
	$periode = 'Exercice '.$h($exercice).' — du '.$d($du).' au '.$d($au).' · dernière écriture : '.$d($derniere);
	$o = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>États financiers '.$h($exercice).' — '.$h($societe).'</title><style>
@page{size:A4;margin:12mm}
:root{--t:#1f1712;--d:#6b5a51;--l:#d9c9ba;--f:#f6efe7;--b:#7d2a17}
*{box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;color:var(--t);font-size:10.5px;margin:0;background:#fff}
.page{max-width:900px;margin:0 auto;padding:18px 16px;page-break-after:always}.page:last-child{page-break-after:auto}
.entete{display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;border-bottom:2px solid var(--b);padding-bottom:6px;margin-bottom:10px;font-size:10px}
.id{text-align:right}
h1{font-size:16px;margin:4px 0 2px;color:var(--b)}h2{font-size:12px;margin:12px 0 4px}
.periode{color:var(--d);margin-bottom:8px}
table{width:100%;border-collapse:collapse}th{background:var(--b);color:#fff;text-align:left;padding:4px 5px;font-size:10px}
td{padding:3px 5px;border-bottom:1px solid #eee4da;vertical-align:top}td.n,th.n{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
tr.total td{font-weight:bold;background:var(--f);border-top:1px solid var(--l)}tr.titre td{font-weight:bold;color:var(--b);padding-top:7px;background:#fff}
tr.dont td{color:var(--d);font-style:italic}
.note{color:var(--d);font-size:9.5px;margin-top:8px}.ok{color:#2e7d4f;font-weight:bold}.ko{color:#b00020;font-weight:bold}
.defile{overflow-x:auto}
@media screen and (max-width:640px){.page{padding:12px 10px}table{min-width:560px}}
</style></head><body>';
	// Bilan actif
	$o .= '<section class="page">'.$entete.'<h1>BILAN — ACTIF</h1><div class="periode">'.$periode.' · montants en dinars algériens</div><div class="defile"><table><tr><th>Actif</th><th class="n">Brut</th><th class="n">Amort. / provisions</th><th class="n">Net</th></tr>';
	foreach ($actif as $l) $o .= '<tr class="'.($l['titre'] ? 'titre' : ($l['total'] ? 'total' : '')).'"><td>'.$h($l['libelle']).'</td><td class="n">'.$n($l['brut']).'</td><td class="n">'.$n($l['amort']).'</td><td class="n">'.$n($l['net']).'</td></tr>';
	$o .= '</table></div><p class="note">Comptes de tiers et de trésorerie classés selon le sens de leur solde. Amortissement du gisement d\'argile (28122) déduit des immobilisations en concession.</p></section>';
	// Bilan passif
	$o .= '<section class="page">'.$entete.'<h1>BILAN — PASSIF</h1><div class="periode">'.$periode.'</div><div class="defile"><table><tr><th>Passif</th><th class="n">Montant</th></tr>';
	foreach ($passif as $l) $o .= '<tr class="'.($l['titre'] ? 'titre' : ($l['total'] ? 'total' : (!empty($l['dont']) ? 'dont' : ''))).'"><td>'.$h($l['libelle']).'</td><td class="n">'.$n($l['montant']).'</td></tr>';
	$o .= '</table></div><p class="note">Résultat de l\'exercice PROVISOIRE (classes 7 − 6), avant écritures d\'inventaire : amortissements de l\'exercice, variation des stocks, provisions, IBS. Le résultat antérieur non encore affecté (compte 12) est présenté avec le report à nouveau.</p></section>';
	// TCR
	$o .= '<section class="page">'.$entete.'<h1>COMPTE DE RÉSULTAT (TCR) — PAR NATURE</h1><div class="periode">'.$periode.'</div><div class="defile"><table><tr><th>Rubrique</th><th class="n">Montant</th><th>Comptes</th></tr>';
	foreach ($tcr as $l) $o .= '<tr class="'.($l['total'] ? 'total' : '').'"><td>'.$h($l['libelle']).'</td><td class="n">'.$n($l['montant']).'</td><td style="color:var(--d);font-size:9px">'.$h(mb_strimwidth($l['comptes'], 0, 70, '…')).'</td></tr>';
	$o .= '</table></div><p class="note">Résultat provisoire : les dotations aux amortissements de l\'exercice et la variation des stocks ne sont pas encore toutes comptabilisées.</p></section>';
	// G50
	$o .= '<section class="page">'.$entete.'<h1>AIDE À LA PRÉPARATION DE LA G50</h1><div class="periode">'.$periode.'</div><div class="defile"><table><tr><th>Mois</th><th class="n">CA HT (70)</th><th class="n">TVA collectée</th><th class="n">TVA déd. biens et services</th><th class="n">TVA déd. invest.</th><th class="n">TVA nette</th><th class="n">Timbre</th><th class="n">IRG salaires</th><th class="n">Taxe formation</th></tr>';
	$t = array_fill(0, 8, 0.0);
	foreach ($g50 as $m => $v) {
		$nette = $v['tva_col'] - $v['tva_ded_bs'] - $v['tva_ded_inv'];
		$vals = array($v['ca'], $v['tva_col'], $v['tva_ded_bs'], $v['tva_ded_inv'], $nette, $v['timbre'], $v['irg'], $v['formation']);
		foreach ($vals as $i => $x) $t[$i] += $x;
		$o .= '<tr><td>'.$mois[substr($m, 5, 2)].' '.substr($m, 0, 4).'</td>'.implode('', array_map(function ($x) use ($n) { return '<td class="n">'.$n($x).'</td>'; }, $vals)).'</tr>';
	}
	$o .= '<tr class="total"><td>Total</td>'.implode('', array_map(function ($x) use ($n) { return '<td class="n">'.$n($x).'</td>'; }, $t)).'</tr></table></div>';
	$o .= '<p class="note">Montants tirés des comptes : TVA collectée = comptes 4457 des journaux de vente ; TVA déductible = débits des comptes 44563 et 44562 ; timbre = compte 44720 des journaux de vente ; IRG = compte 44210 des journaux de paie ; taxe de formation = compte 44740 des journaux de paie. TVA nette positive = à payer, négative = crédit. Document d\'aide : à rapprocher des déclarations G50 déposées (régularisations, précompte, crédit antérieur).</p></section>';
	// Balance générale
	$o .= '<section class="page">'.$entete.'<h1>BALANCE GÉNÉRALE</h1><div class="periode">'.$periode.'</div><div class="defile"><table><tr><th>Compte</th><th>Intitulé</th><th class="n">Ouv. débit</th><th class="n">Ouv. crédit</th><th class="n">Mvt débit</th><th class="n">Mvt crédit</th><th class="n">Solde débiteur</th><th class="n">Solde créditeur</th></tr>';
	foreach ($balance as $i => $l) {
		$o .= '<tr class="'.(in_array($i, $balanceGras) ? 'total' : '').'"><td>'.$h($l[0]).'</td><td>'.$h(mb_strimwidth($l[1], 0, 32, '…')).'</td>';
		for ($k = 2; $k < 8; $k++) $o .= '<td class="n">'.$n($l[$k]).'</td>';
		$o .= '</tr>';
	}
	$o .= '</table></div></section>';
	// Contrôles
	$o .= '<section class="page">'.$entete.'<h1>CONTRÔLES</h1><div class="defile"><table><tr><th>Contrôle</th><th class="n">Valeur 1</th><th class="n">Valeur 2</th><th>Résultat</th></tr>';
	foreach ($controles as $c) {
		$ok = abs($c[1] - $c[2]) < 0.01;
		$o .= '<tr><td>'.$h($c[0]).'</td><td class="n">'.$n($c[1]).'</td><td class="n">'.$n($c[2]).'</td><td class="'.($ok ? 'ok' : 'ko').'">'.($ok ? 'OK' : 'ÉCART').'</td></tr>';
	}
	foreach ($etats['non_classes'] as $nc) $o .= '<tr><td colspan="4" class="ko">Compte non classé : '.$h($nc).'</td></tr>';
	$o .= '</table></div><p class="note">Le classeur Excel joint contient en plus la balance des tiers et le grand livre complet.</p></section></body></html>';
	return $o;
}
