<?php
/*
 * Modèle de facture « DLG » : même présentation que les factures éditées par
 * DLG Gestion / PC Compta (numéro souligné, bloc client avec RC / NIF / AI,
 * tableau Référence / Désignation / Un / TVA % / Remise % / Quantité / Prix HT /
 * Montant HT, récapitulatif TVA, timbre, net à payer et montant en lettres).
 * SARL DPR AXXAM.
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/facture/modules_facture.php';
dol_include_once('/timbredz/lib/dlg_pdf.lib.php');

class pdf_dlg extends ModelePDFFactures
{
	public $version = 'dolibarr';

	/** Bas du tableau sur la dernière page (place pour les totaux) et sur les autres. */
	const BAS_DERNIERE = 196;
	const BAS_PAGE = 268;

	public function __construct($db)
	{
		global $langs, $mysoc;
		$langs->loadLangs(array('main', 'bills'));
		$this->db = $db;
		$this->name = 'dlg';
		$this->description = 'Facture au format DLG / PC Compta (montant en lettres, timbre, RC / NIF / AI)';
		$this->update_main_doc_field = 1;
		$this->type = 'pdf';
		$this->page_largeur = 210;
		$this->page_hauteur = 297;
		$this->format = array($this->page_largeur, $this->page_hauteur);
		$this->marge_gauche = DlgPdf::MARGE;
		$this->marge_droite = DlgPdf::MARGE;
		$this->marge_haute = 10;
		$this->marge_basse = 10;
		$this->option_logo = 0;
		$this->option_tva = 1;
		$this->option_modereg = 1;
		$this->option_condreg = 1;
		$this->option_multilang = 0;
		$this->option_escompte = 0;
		$this->option_credit_note = 1;
		$this->option_freetext = 0;
		$this->option_draft_watermark = 1;
		$this->emetteur = $mysoc;
	}

	private function colonnes()
	{
		return array(
			array('Référence', 26, 'L'),
			array('Désignation', 58, 'L'),
			array('Un', 9, 'C'),
			array('TVA %', 11, 'R'),
			array('Remise %', 13, 'R'),
			array('Quantité', 19, 'R'),
			array('Prix HT', 23, 'R'),
			array('Montant HT', 27, 'R'),
		);
	}

	private function titre($object)
	{
		$ref = $object->ref;
		if ($object->status == $object::STATUS_DRAFT) return 'FACTURE PROFORMA No:'.$ref;
		if ($object->type == $object::TYPE_CREDIT_NOTE) return 'AVOIR No:'.$ref;
		if ($object->type == $object::TYPE_DEPOSIT) return 'FACTURE D\'ACOMPTE No:'.$ref;
		return 'FACTURE No:'.$ref;
	}

	/** En-tête complet d'une page : société, titre, client, informations. */
	private function enTetePage($pdf, $object, $outputlangs)
	{
		global $mysoc;
		$pdf->AddPage();
		DlgPdf::entete($pdf, $mysoc);
		DlgPdf::titre($pdf, $this->titre($object), 34);

		$bl = array();
		$object->fetchObjectLinked(null, '', $object->id, $object->element);
		if (!empty($object->linkedObjects['shipping'])) {
			foreach ($object->linkedObjects['shipping'] as $exp) $bl[] = $exp->ref;
		}
		if (!empty($object->linkedObjects['commande'])) {
			foreach ($object->linkedObjects['commande'] as $cde) {
				$cde->fetchObjectLinked(null, '', $cde->id, 'commande');
				foreach ($cde->linkedObjects['shipping'] ?? array() as $exp) $bl[] = $exp->ref;
			}
		}
		$reglement = '';
		if ($object->mode_reglement_code) {
			$t = $outputlangs->transnoentitiesnoconv('PaymentType'.$object->mode_reglement_code);
			$reglement = ($t != 'PaymentType'.$object->mode_reglement_code) ? $t : $object->mode_reglement;
		}
		DlgPdf::blocInfos($pdf, array(
			'Date' => dol_print_date($object->date, '%d/%m/%Y'),
			'No BC' => $object->ref_client,
			'No BL' => implode(', ', array_unique($bl)),
			'Règlement' => mb_strtoupper($reglement),
			'Échéance' => ($object->date_lim_reglement && $object->date_lim_reglement != $object->date) ? dol_print_date($object->date_lim_reglement, '%d/%m/%Y') : '',
		), 62);
		DlgPdf::blocClient($pdf, $object->thirdparty, 50);
		if ($object->status == $object::STATUS_DRAFT && getDolGlobalString('FACTURE_DRAFT_WATERMARK')) {
			pdf_watermark($pdf, $outputlangs, 297, 210, 'mm', getDolGlobalString('FACTURE_DRAFT_WATERMARK'));
		}
	}

	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		global $conf, $langs, $mysoc;

		if (!is_object($outputlangs)) $outputlangs = $langs;
		$outputlangs->loadLangs(array('main', 'bills', 'products', 'dict', 'companies'));
		if (empty($conf->facture->multidir_output[$conf->entity])) {
			$this->error = $langs->transnoentities('ErrorConstantNotDefined', 'FAC_OUTPUTDIR');
			return 0;
		}
		$object->fetch_thirdparty();

		if ($object->specimen) {
			$dir = $conf->facture->multidir_output[$conf->entity];
			$file = $dir.'/SPECIMEN.pdf';
		} else {
			$objectref = dol_sanitizeFileName($object->ref);
			$dir = $conf->facture->multidir_output[$object->entity ?? $conf->entity].'/'.$objectref;
			$file = $dir.'/'.$objectref.'.pdf';
		}
		if (!file_exists($dir) && dol_mkdir($dir) < 0) {
			$this->error = $langs->transnoentities('ErrorCanNotCreateDir', $dir);
			return 0;
		}

		$pdf = DlgPdf::nouveauPdf($this->titre($object), $outputlangs);
		$col = $this->colonnes();
		$haut = DlgPdf::HAUT_TABLEAU;

		// Lignes et cumuls
		$brut = 0;
		$tva = array();
		$lignes = array();
		foreach ($object->lines as $l) {
			if ($l->special_code == 3 || $l->product_type == 9) continue; // titres et sous-totaux
			$brut += $l->qty * $l->subprice;
			$taux = price2num($l->tva_tx);
			if (!isset($tva[$taux])) $tva[$taux] = array(0, 0);
			$tva[$taux][0] += $l->total_ht;
			$tva[$taux][1] += $l->total_tva;
			$lignes[] = array(
				$l->product_ref ?: '',
				DlgPdf::designation($l),
				DlgPdf::unite($l, $outputlangs),
				DlgPdf::montant($l->tva_tx),
				$l->remise_percent ? DlgPdf::montant($l->remise_percent) : '',
				DlgPdf::quantite($l->qty),
				DlgPdf::montant($l->subprice),
				DlgPdf::montant($l->total_ht),
			);
		}
		$remise = $brut - $object->total_ht;

		// Pagination : le tableau descend jusqu'à BAS_PAGE, sauf sur la dernière page
		$this->enTetePage($pdf, $object, $outputlangs);
		$y = $haut + 8;
		$pdf->SetFont(DlgPdf::POLICE, '', 7.5);
		foreach ($lignes as $v) {
			$h = DlgPdf::hauteurLigne($pdf, $col, $v);
			if ($y + $h > self::BAS_PAGE) {
				DlgPdf::cadreTableau($pdf, $col, $haut, self::BAS_PAGE - $haut);
				$this->enTetePage($pdf, $object, $outputlangs);
				$y = $haut + 8;
				$pdf->SetFont(DlgPdf::POLICE, '', 7.5);
			}
			$y = DlgPdf::ligneTableau($pdf, $col, $v, $y);
		}
		if ($y > self::BAS_DERNIERE) {
			DlgPdf::cadreTableau($pdf, $col, $haut, self::BAS_PAGE - $haut);
			$this->enTetePage($pdf, $object, $outputlangs);
		}
		DlgPdf::cadreTableau($pdf, $col, $haut, self::BAS_DERNIERE - $haut);

		// Récapitulatif TVA (à gauche) et totaux (à droite)
		$m = DlgPdf::MARGE;
		$y0 = self::BAS_DERNIERE + 4;
		$pdf->SetLineWidth(0.25);
		$pdf->Rect($m, $y0, 92, 34);
		$pdf->Line($m, $y0 + 6, $m + 92, $y0 + 6);
		$pdf->Line($m + 20, $y0, $m + 20, $y0 + 34);
		$pdf->Line($m + 56, $y0, $m + 56, $y0 + 34);
		DlgPdf::texte($pdf, $m, $y0 + 1, 20, 'Taux', 'C', '', 8.5);
		DlgPdf::texte($pdf, $m + 20, $y0 + 1, 36, 'Base TVA', 'C', '', 8.5);
		DlgPdf::texte($pdf, $m + 56, $y0 + 1, 36, 'Montant TVA', 'C', '', 8.5);
		$yt = $y0 + 8;
		ksort($tva);
		foreach ($tva as $taux => $t) {
			DlgPdf::texte($pdf, $m, $yt, 19, DlgPdf::montant($taux), 'R', '', 8.5);
			DlgPdf::texte($pdf, $m + 20, $yt, 35, DlgPdf::montant($t[0]), 'R', '', 8.5);
			DlgPdf::texte($pdf, $m + 56, $yt, 35, DlgPdf::montant($t[1]), 'R', '', 8.5);
			$yt += 4.5;
		}

		$xt = $m + 96;
		$pdf->Rect($xt, $y0, 90, 34);
		$totaux = array(array('Total HT', $brut));
		if (abs($remise) >= 0.005) $totaux[] = array('Remise', $remise);
		if (abs($remise) >= 0.005) $totaux[] = array('Net HT', $object->total_ht);
		$totaux[] = array('Total TVA', $object->total_tva);
		if ((float) $object->revenuestamp != 0) $totaux[] = array('Droit de timbre', $object->revenuestamp);
		$yt = $y0 + 2;
		foreach ($totaux as $t) {
			DlgPdf::texte($pdf, $xt + 2, $yt, 44, $t[0], 'L', '', 8.5);
			DlgPdf::texte($pdf, $xt + 44, $yt, 44, DlgPdf::montant($t[1]), 'R', '', 8.5);
			$yt += 4.6;
		}
		$pdf->SetLineStyle(array('width' => 0.25, 'dash' => '1,1'));
		$pdf->Line($xt, $y0 + 26, $xt + 90, $y0 + 26);
		$pdf->SetLineStyle(array('width' => 0.25, 'dash' => 0));
		$net = $object->type == $object::TYPE_CREDIT_NOTE ? 'Net à rembourser' : 'Net à payer';
		DlgPdf::texte($pdf, $xt + 2, $y0 + 28, 44, $net, 'L', 'B', 9.5);
		DlgPdf::texte($pdf, $xt + 40, $y0 + 28, 48, DlgPdf::montant($object->total_ttc), 'R', 'B', 9.5);

		// Montant en lettres
		$yl = $y0 + 40;
		$debut = $object->type == $object::TYPE_CREDIT_NOTE ? 'ARRETE LE PRESENT AVOIR A LA SOMME DE :' : 'ARRETEE LA PRESENTE FACTURE A LA SOMME DE :';
		DlgPdf::texte($pdf, $m, $yl, DlgPdf::LARGEUR, $debut, 'L', '', 8.5);
		$pdf->SetFont(DlgPdf::POLICE, 'B', 8.5);
		$pdf->SetXY($m, $yl + 4.5);
		$pdf->MultiCell(120, 4, DlgPdf::enLettres(abs($object->total_ttc), $outputlangs).', TOUTES TAXES COMPRISES.', 0, 'L');

		// Note publique et cachet
		if (trim((string) $object->note_public) !== '') {
			$pdf->SetFont('dejavusans', '', 7);
			$pdf->SetXY($m, $yl + 15);
			$pdf->MultiCell(120, 3.5, dol_string_nohtmltag($object->note_public, 1), 0, 'L');
		}
		DlgPdf::texte($pdf, $m + 132, $yl + 2, 54, 'Cachet et signature', 'C', 'I', 7.5);
		$pdf->SetLineStyle(array('width' => 0.2, 'dash' => '1,1'));
		$pdf->Rect($m + 132, $yl + 6, 54, 22);
		$pdf->SetLineStyle(array('width' => 0.2, 'dash' => 0));

		$n = $pdf->getNumPages();
		for ($p = 1; $p <= $n; $p++) {
			$pdf->setPage($p);
			DlgPdf::pied($pdf, $mysoc, $p, $n);
		}

		$pdf->Close();
		$pdf->Output($file, 'F');
		dolChmod($file);
		$this->result = array('fullpath' => $file);
		return 1;
	}
}
