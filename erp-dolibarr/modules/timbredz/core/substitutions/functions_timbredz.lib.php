<?php
/*
 * Variable de substitution __MONTANT_EN_LETTRES__ : montant TTC du document écrit en
 * toutes lettres, en dinars algériens (« Arrêtée la présente facture à la somme de : … »).
 * Utilisable dans le texte libre des factures et des devis.
 */
require_once DOL_DOCUMENT_ROOT.'/core/lib/functionsnumtoword.lib.php';

function timbredz_montant_en_lettres($montant, $langs)
{
	$montant = round(abs((float) $montant), 2);
	$dinars = (int) floor($montant);
	$centimes = (int) round(($montant - $dinars) * 100);
	$mots = trim(dol_convertToWord($dinars, $langs, '', false));
	if ($dinars == 0) $mots = 'zéro';
	// « un million de dinars », « deux milliards de dinars »
	$de = preg_match('/(millions?|milliards?)$/u', $mots) ? ' de' : '';
	$texte = $mots.$de.($dinars > 1 ? ' dinars algériens' : ' dinar algérien');
	if ($centimes > 0) $texte .= ' et '.trim(dol_convertToWord($centimes, $langs, '', false)).($centimes > 1 ? ' centimes' : ' centime');
	return $texte;
}

function timbredz_completesubstitutionarray(&$substitutionarray, $langs, $object)
{
	if (is_object($object) && isset($object->total_ttc)) {
		$substitutionarray['__MONTANT_EN_LETTRES__'] = timbredz_montant_en_lettres($object->total_ttc, $langs);
	}
}
