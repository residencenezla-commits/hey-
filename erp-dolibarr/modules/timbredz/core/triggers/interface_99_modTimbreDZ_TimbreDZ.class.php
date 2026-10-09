<?php
/*
 * Calcule le droit de timbre (champ « timbre fiscal » de la facture) à la validation
 * d'une facture client payée en espèces, selon le barème appliqué par DPR AXXAM :
 *   TTC <= 30 000 DA          : 1 %
 *   30 000 < TTC <= 100 000   : 1,5 %
 *   TTC > 100 000 DA          : 2 %
 * calculé sur le TTC (hors timbre) et arrondi au dinar supérieur.
 * Le timbre est remis à zéro si la facture repasse en brouillon, pour être recalculé.
 */
require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

class InterfaceTimbreDZ extends DolibarrTriggers
{
	public function __construct($db)
	{
		parent::__construct($db);
		$this->family = 'financial';
		$this->description = 'Droit de timbre algérien sur les factures payées en espèces';
		$this->version = '1.0.0';
		$this->picto = 'bill';
	}

	public static function montantTimbre($ttc)
	{
		if ($ttc <= 0) return 0;
		$taux = $ttc <= 30000 ? 1 : ($ttc <= 100000 ? 1.5 : 2);
		return (float) ceil(round($ttc * $taux / 100, 4));
	}

	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('timbredz') || !in_array($action, array('BILL_VALIDATE', 'BILL_UNVALIDATE'))) {
			return 0;
		}
		if ((int) $object->type !== Facture::TYPE_STANDARD) {
			return 0;
		}

		if ($action == 'BILL_UNVALIDATE') {
			if ((float) $object->revenuestamp != 0) {
				$object->revenuestamp = 0;
				return $this->enregistrer($object);
			}
			return 0;
		}

		// Mode de règlement « espèces » (code LIQ) uniquement
		$code = '';
		if (!empty($object->mode_reglement_id)) {
			$res = $this->db->query("SELECT code FROM ".MAIN_DB_PREFIX."c_paiement WHERE id = ".((int) $object->mode_reglement_id));
			if ($res && ($o = $this->db->fetch_object($res))) $code = $o->code;
		}
		if ($code !== 'LIQ') {
			return 0;
		}

		$ttcHorsTimbre = (float) $object->total_ttc - (float) $object->revenuestamp;
		$object->revenuestamp = self::montantTimbre($ttcHorsTimbre);
		dol_syslog("TimbreDZ: facture ".$object->ref." TTC ".$ttcHorsTimbre." timbre ".$object->revenuestamp);
		return $this->enregistrer($object);
	}

	private function enregistrer($object)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."facture SET revenuestamp = ".((float) $object->revenuestamp)." WHERE rowid = ".((int) $object->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return $object->update_price(1) < 0 ? -1 : 1;
	}
}
