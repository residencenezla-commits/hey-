<?php
/*
 * Modèle de bon de livraison « DLG » : même présentation que les bons de
 * livraison édités par DLG Gestion / PC Compta, avec en plus le véhicule,
 * le chauffeur et les signatures (magasinier, chauffeur, client).
 * SARL DPR AXXAM.
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/expedition/modules_expedition.php';
dol_include_once('/timbredz/lib/dlg_pdf.lib.php');

class pdf_dlgbl extends ModelePdfExpedition
{
	public $version = 'dolibarr';

	const BAS_DERNIERE = 222;
	const BAS_PAGE = 268;

	public function __construct($db)
	{
		global $mysoc;
		$this->db = $db;
		$this->name = 'dlgbl';
		$this->description = 'Bon de livraison au format DLG / PC Compta (signatures magasinier, chauffeur, client)';
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
		$this->option_draft_watermark = 1;
		$this->emetteur = $mysoc;
	}

	private function colonnes()
	{
		return array(
			array('Référence', 30, 'L'),
			array('Désignation', 96, 'L'),
			array('Un', 14, 'C'),
			array('Commandé', 22, 'R'),
			array('Livré', 24, 'R'),
		);
	}

	private function enTetePage($pdf, $object, $outputlangs)
	{
		global $mysoc;
		$pdf->AddPage();
		DlgPdf::entete($pdf, $mysoc);
		DlgPdf::blocClient($pdf, $object->thirdparty, 36, false);
		DlgPdf::titre($pdf, 'BON DE LIVRAISON No: '.$object->ref, 58);
		$commande = '';
		if (!empty($object->origin_id) && $object->origin == 'commande') {
			try {
				$object->fetch_origin();
				$commande = $object->origin_object->ref ?? ($object->commande->ref ?? '');
			} catch (\Throwable $e) {
				$commande = '';
			}
		}
		DlgPdf::blocInfos($pdf, array(
			'Date' => dol_print_date($object->date_delivery ?: ($object->date_shipping ?: $object->date_creation), '%d/%m/%Y'),
			'No BC' => $object->ref_customer,
			'Commande' => $commande,
			'Véhicule' => $object->tracking_number,
		), 72);
	}

	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		global $conf, $langs, $mysoc;

		if (!is_object($outputlangs)) $outputlangs = $langs;
		$outputlangs->loadLangs(array('main', 'bills', 'products', 'dict', 'companies', 'sendings'));
		if (empty($conf->expedition->dir_output)) {
			$this->error = $langs->transnoentities('ErrorConstantNotDefined', 'EXP_OUTPUTDIR');
			return 0;
		}
		$object->fetch_thirdparty();
		if ($object->specimen) {
			$dir = $conf->expedition->dir_output.'/sending';
			$file = $dir.'/SPECIMEN.pdf';
		} else {
			$ref = dol_sanitizeFileName($object->ref);
			$dir = $conf->expedition->dir_output.'/sending/'.$ref;
			$file = $dir.'/'.$ref.'.pdf';
		}
		if (!file_exists($dir) && dol_mkdir($dir) < 0) {
			$this->error = $langs->transnoentities('ErrorCanNotCreateDir', $dir);
			return 0;
		}

		$pdf = DlgPdf::nouveauPdf('Bon de livraison '.$object->ref, $outputlangs);
		$col = $this->colonnes();
		$haut = DlgPdf::HAUT_TABLEAU;
		$total = 0;

		$this->enTetePage($pdf, $object, $outputlangs);
		$y = $haut + 8;
		$pdf->SetFont(DlgPdf::POLICE, '', 7.5);
		foreach ($object->lines as $l) {
			$v = array(
				$l->ref ?: ($l->product_ref ?? ''),
				DlgPdf::designation($l),
				DlgPdf::unite($l, $outputlangs),
				DlgPdf::quantite($l->qty_asked),
				DlgPdf::quantite($l->qty_shipped),
			);
			$total += $l->qty_shipped;
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

		$m = DlgPdf::MARGE;
		$y0 = self::BAS_DERNIERE + 3;
		DlgPdf::texte($pdf, $m, $y0, 120, 'Nombre de lignes : '.count($object->lines), 'L', '', 8);
		DlgPdf::texte($pdf, $m + 100, $y0, 86, 'Total livré : '.DlgPdf::quantite($total), 'R', 'B', 8.5);

		// Signatures
		$y1 = $y0 + 8;
		$w = (DlgPdf::LARGEUR - 8) / 3;
		foreach (array('Le magasinier', 'Le chauffeur', 'Le client (reçu conforme)') as $k => $lib) {
			$x = $m + $k * ($w + 4);
			DlgPdf::texte($pdf, $x, $y1, $w, $lib, 'C', 'I', 8);
			$pdf->SetLineStyle(array('width' => 0.2, 'dash' => '1,1'));
			$pdf->Rect($x, $y1 + 5, $w, 26);
			$pdf->SetLineStyle(array('width' => 0.2, 'dash' => 0));
		}

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
