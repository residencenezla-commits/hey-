<?php
/*
 * Mise en page commune des modèles « DLG » (facture et bon de livraison),
 * sur le modèle des documents édités par PC Compta / DLG Gestion :
 * en-tête de la société, numéro souligné, bloc client avec RC / NIF / AI,
 * tableau à colonnes séparées, récapitulatif TVA, montant en lettres.
 * SARL DPR AXXAM.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
dol_include_once('/timbredz/core/substitutions/functions_timbredz.lib.php');

class DlgPdf
{
	const POLICE = 'dejavusansmono';
	const MARGE = 12;
	const LARGEUR = 186;           // 210 - 2 × 12
	const HAUT_TABLEAU = 96;       // ordonnée du haut du tableau des lignes

	/** Texte sur une ligne, coupé à la largeur. */
	public static function texte($pdf, $x, $y, $w, $txt, $align = 'L', $style = '', $taille = 8)
	{
		$pdf->SetFont(self::POLICE, $style, $taille);
		$pdf->SetXY($x, $y);
		$pdf->Cell($w, 4, $txt, 0, 0, $align, false, '', 1);
	}

	/** En-tête de la société émettrice, comme le bandeau DLG. */
	public static function entete($pdf, $mysoc)
	{
		$m = self::MARGE;
		$logo = dirname(__DIR__).'/img/logo-dpr-axxam.png';
		if (is_readable($logo)) {
			$pdf->Image($logo, $m, 5.5, 42, 0, 'PNG');   // 42 × 20 mm
		} else {
			$pdf->SetFont('dejavusans', 'B', 24);
			$pdf->SetXY($m, 10);
			$pdf->Cell(40, 10, 'DPR AXXAM', 0, 0, 'L');
		}
		$x = $m + 44;
		$pdf->SetTextColor(30, 30, 30);
		$pdf->SetFont('dejavusans', 'I', 8);
		$pdf->SetXY($x, 13);
		$pdf->Cell(80, 5, 'Fabrication industrielle de produits rouges', 0, 0, 'L');
		$pdf->SetTextColor(180, 71, 43);
		$pdf->SetFont('dejavusans', 'BI', 10);
		$pdf->SetXY($m, 12.5);
		$pdf->Cell(self::LARGEUR, 5, 'Le bien-être dans l\'habitat', 0, 0, 'R');
		$pdf->SetTextColor(30, 30, 30);
		$pdf->SetDrawColor(30, 30, 30);
		$pdf->SetLineWidth(0.5);
		$pdf->Line($x, 19, $m + self::LARGEUR, 19);
		$pdf->SetFont('dejavusans', '', 8);
		$pdf->SetXY($x, 20.3);
		$pdf->Cell(self::LARGEUR - 44, 5, 'Briqueterie :  B8  -  B12  -  Hourdis  -  Usine ZAC Helouane, Ighzer Amokrane', 0, 0, 'L', false, '', 1);
		$pdf->SetLineWidth(0.2);
		$pdf->Line($x, 25.5, $m + self::LARGEUR, 25.5);
	}

	/** Titre « FACTURE No: … » centré, en italique gras, souligné de tirets. */
	public static function titre($pdf, $titre, $y = 38)
	{
		$pdf->SetFont(self::POLICE, 'BI', 15);
		$w = $pdf->GetStringWidth($titre) + 2;
		$x = (210 - $w) / 2;
		$pdf->SetXY($x, $y);
		$pdf->Cell($w, 7, $titre, 0, 0, 'C');
		$pdf->SetLineStyle(array('width' => 0.3, 'dash' => '2,1'));
		$pdf->Line($x, $y + 8, $x + $w, $y + 8);
		$pdf->SetLineStyle(array('width' => 0.2, 'dash' => 0));
	}

	/** Bloc client à droite : code, activité, nom, adresse, RC, NIF, AI. */
	public static function blocClient($pdf, $soc, $y, $avecIdentifiants = true)
	{
		$x = 112;
		$lignes = array();
		$code = $soc->code_client ?: ($soc->code_fournisseur ?: '');
		self::texte($pdf, 90, $y, 22, 'Client:', 'R');
		self::texte($pdf, $x, $y, 86, $code !== '' ? $code.'   '.mb_strtoupper($soc->name) : mb_strtoupper($soc->name), 'L', 'B');
		$y += 4.5;
		$adresse = trim(preg_replace('/\s+/', ' ', (string) $soc->address));
		if ($adresse !== '') { self::texte($pdf, $x, $y, 86, mb_strtoupper($adresse)); $y += 4; }
		$ville = trim(($soc->zip ? $soc->zip.' ' : '').$soc->town);
		if ($ville !== '') { self::texte($pdf, $x, $y, 86, mb_strtoupper($ville)); $y += 4; }
		if ($avecIdentifiants) {
			$y += 1.5;
			foreach (array('No R.C  : ' => $soc->idprof1, 'No I.F. : ' => $soc->idprof2, 'No Art. : ' => $soc->idprof3) as $lib => $val) {
				if (trim((string) $val) !== '') { self::texte($pdf, $x, $y, 86, $lib.$val); $y += 4; }
			}
		}
		return $y;
	}

	/** Bloc d'informations à gauche (Date, No BC, No BL, Règlement…). */
	public static function blocInfos($pdf, $infos, $y)
	{
		foreach ($infos as $lib => $val) {
			if ($val === null || $val === '') continue;
			self::texte($pdf, self::MARGE, $y, 22, $lib);
			self::texte($pdf, self::MARGE + 22, $y, 72, ': '.$val);
			$y += 4.5;
		}
		return $y;
	}

	/**
	 * Tableau à colonnes séparées sur toute la hauteur, comme les documents DLG.
	 * $colonnes : liste de [titre, largeur, alignement].
	 * Retourne l'ordonnée de la première ligne de données.
	 */
	public static function cadreTableau($pdf, $colonnes, $y, $hauteur)
	{
		$m = self::MARGE;
		$pdf->SetDrawColor(60, 60, 60);
		$pdf->SetLineWidth(0.25);
		$pdf->Rect($m, $y, self::LARGEUR, $hauteur);
		$pdf->SetLineStyle(array('width' => 0.25, 'dash' => '1,1'));
		$pdf->Line($m, $y + 6, $m + self::LARGEUR, $y + 6);
		$pdf->SetLineStyle(array('width' => 0.25, 'dash' => 0));
		$x = $m;
		foreach ($colonnes as $i => $c) {
			self::texte($pdf, $x, $y + 1, $c[1], $c[0], 'C', '', 7.5);
			$x += $c[1];
			if ($i < count($colonnes) - 1) $pdf->Line($x, $y, $x, $y + $hauteur);
		}
		return $y + 8;
	}

	/** Hauteur d'une ligne du tableau (la désignation peut tenir sur plusieurs lignes). */
	public static function hauteurLigne($pdf, $colonnes, $valeurs, $iDesignation = 1)
	{
		$pdf->SetFont(self::POLICE, '', 7.5);
		$pad = $pdf->getCellPaddings();
		$pdf->setCellPaddings(0, 0.3, 0, 0.3);
		$h = $pdf->getStringHeight($colonnes[$iDesignation][1] - 1.4, $valeurs[$iDesignation]);
		$pdf->setCellPaddings($pad['L'], $pad['T'], $pad['R'], $pad['B']);
		return max(4, $h) + 0.8;
	}

	/** Une ligne du tableau ; retourne l'ordonnée de la ligne suivante. */
	public static function ligneTableau($pdf, $colonnes, $valeurs, $y, $iDesignation = 1)
	{
		$h = self::hauteurLigne($pdf, $colonnes, $valeurs, $iDesignation);
		$x = self::MARGE;
		foreach ($colonnes as $i => $c) {
			$pdf->SetXY($x + 0.7, $y);
			if ($i == $iDesignation) {
				$pad = $pdf->getCellPaddings();
				$pdf->setCellPaddings(0, 0.3, 0, 0.3);
				$pdf->MultiCell($c[1] - 1.4, 3.4, $valeurs[$i], 0, 'L', false, 0);
				$pdf->setCellPaddings($pad['L'], $pad['T'], $pad['R'], $pad['B']);
			} else {
				$pdf->Cell($c[1] - 1.4, 4, $valeurs[$i], 0, 0, $c[2], false, '', 1);
			}
			$x += $c[1];
		}
		return $y + $h;
	}

	/** Pied de page : mentions légales de DPR AXXAM. */
	public static function pied($pdf, $mysoc, $page, $nbPages)
	{
		$m = self::MARGE;
		$pdf->SetDrawColor(30, 30, 30);
		$pdf->SetLineWidth(0.3);
		$pdf->Line($m, 276, $m + self::LARGEUR, 276);
		$capital = $mysoc->capital ? number_format((float) $mysoc->capital, 0, ',', ' ').' DA' : '';
		$l1 = 'Sarl DPR AXXAM'.($capital ? ' au capital de '.$capital : '')
			.($mysoc->idprof1 ? ' - RC N° '.$mysoc->idprof1 : '')
			.($mysoc->idprof3 ? ' - Article d\'imposition : '.$mysoc->idprof3 : '')
			.($mysoc->idprof2 ? ' - Id. Fiscal : '.$mysoc->idprof2 : '');
		$adr = trim(preg_replace('/\s+/', ' ', str_replace(array("\r", "\n"), ' ', (string) $mysoc->address)));
		$l2 = $adr.($mysoc->zip || $mysoc->town ? ', '.trim($mysoc->zip.' '.$mysoc->town) : '')
			.($mysoc->phone ? '   Tél. / Fax : '.$mysoc->phone : '');
		$l3 = ($mysoc->email ? 'e-mail : '.$mysoc->email : '').($mysoc->url ? '   Site web : '.preg_replace('#^https?://#', '', $mysoc->url) : '');
		// logo avec le slogan, à gauche du pied de page
		$logo = dirname(__DIR__).'/img/logo-dpr-axxam-slogan.png';
		$x = $m;
		if (is_readable($logo)) {
			$pdf->Image($logo, $m, 277, 24, 0, 'PNG');   // 24 × 14 mm
			$x = $m + 27;
		}
		$pdf->SetFont('dejavusans', '', 6.8);
		foreach (array($l1, $l2, $l3) as $k => $l) {
			$pdf->SetXY($x, 278 + $k * 3.6);
			$pdf->Cell($m + self::LARGEUR - $x, 3.6, $l, 0, 0, 'C', false, '', 1);
		}
		if ($nbPages > 1) {
			$pdf->SetXY($m, 271);
			$pdf->SetFont(self::POLICE, '', 7);
			$pdf->Cell(self::LARGEUR, 4, 'Page '.$page.' / '.$nbPages, 0, 0, 'R');
		}
	}

	public static function montant($v)
	{
		return number_format((float) $v, 2, '.', ' ');
	}

	public static function quantite($v)
	{
		$v = (float) $v;
		return $v == floor($v) ? number_format($v, 0, '.', ' ') : number_format($v, 2, '.', ' ');
	}

	/** Montant en lettres en majuscules, comme sur les factures DLG. */
	public static function enLettres($montant, $langs)
	{
		return mb_strtoupper(timbredz_montant_en_lettres($montant, $langs));
	}

	/** Libellé lisible d'une ligne : produit + description, sans HTML. */
	public static function designation($ligne)
	{
		$lib = trim((string) ($ligne->product_label ?? $ligne->libelle ?? ''));
		$desc = trim(dol_string_nohtmltag((string) ($ligne->desc ?? $ligne->description ?? ''), 1));
		if ($lib === '') return $desc;
		if ($desc === '' || $desc === $lib || strpos($desc, $lib) === 0) return $lib;
		return $lib."\n".$desc;
	}

	/** Unité courte d'une ligne (P, T, M3…). */
	public static function unite($ligne, $langs)
	{
		global $db;
		static $cache = array();
		$fkUnit = (int) ($ligne->fk_unit ?? 0);
		if (!$fkUnit && !empty($ligne->fk_product)) {
			// unité de la fiche produit quand la ligne n'en porte pas
			$id = (int) $ligne->fk_product;
			if (!array_key_exists($id, $cache)) {
				$res = $db->query('SELECT fk_unit FROM '.MAIN_DB_PREFIX.'product WHERE rowid = '.$id);
				$o = $res ? $db->fetch_object($res) : null;
				$cache[$id] = $o ? (int) $o->fk_unit : 0;
			}
			$fkUnit = $cache[$id];
		}
		if (!$fkUnit) return '';
		static $codes = null;
		if ($codes === null) {
			$codes = array();
			$res = $db->query('SELECT rowid, short_label FROM '.MAIN_DB_PREFIX.'c_units');
			while ($res && ($o = $db->fetch_object($res))) $codes[(int) $o->rowid] = $o->short_label;
		}
		return isset($codes[$fkUnit]) ? $langs->transnoentitiesnoconv($codes[$fkUnit]) : '';
	}

	public static function nouveauPdf($titreDoc, $outputlangs)
	{
		$pdf = pdf_getInstance(array(210, 297));
		$pdf->setPrintHeader(false);
		$pdf->setPrintFooter(false);
		$pdf->SetAutoPageBreak(false, 0);
		$pdf->SetMargins(self::MARGE, 10, self::MARGE);
		$pdf->SetTitle($titreDoc);
		$pdf->SetSubject($titreDoc);
		$pdf->SetCreator('Dolibarr '.DOL_VERSION.' - modèle DLG');
		$pdf->SetAuthor('SARL DPR AXXAM');
		$pdf->SetCompression(true);
		return $pdf;
	}
}
