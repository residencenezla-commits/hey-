<?php
/*
 * Paramétrage de Dolibarr pour la briqueterie SARL DPR Axxam.
 *
 * Exécuté automatiquement une seule fois, au premier démarrage du conteneur
 * (dossier docker-init.d). Peut être relancé à la main sans créer de doublons :
 *   docker compose exec web php /var/www/scripts/docker-init.d/10-briqueterie.php
 *
 * Les prix sont laissés à 0 : ils se saisissent dans Produits > fiche du produit.
 */

require_once '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

$user = new User($db);
if ($user->fetch(0, getenv('DOLI_ADMIN_LOGIN') ?: 'admin') <= 0) {
	print "Briqueterie : administrateur introuvable, paramétrage annulé.\n";
	exit(1);
}
$user->loadRights();

function etape($texte) { print "Briqueterie : ".$texte."\n"; }

// ---------------------------------------------------------------- Société
$constantes = array(
	'MAIN_LANG_DEFAULT'            => 'fr_FR',
	'MAIN_MONNAIE'                 => 'DZD',
	'MAIN_INFO_SOCIETE_NOM'        => 'SARL DPR AXXAM',
	'MAIN_INFO_SOCIETE_ADDRESS'    => "Village Tizi, Tissa\n(Usine : ZAC Helouane, Ighzer Amokrane)",
	'MAIN_INFO_SOCIETE_ZIP'        => '06020',
	'MAIN_INFO_SOCIETE_TOWN'       => 'Semaoun, Béjaïa',
	'MAIN_INFO_CAPITAL'            => '294000000',
	'MAIN_INFO_SIREN'              => '08B0185858-06/00',  // Algérie : RC
	'MAIN_INFO_SIRET'              => '000806018585831',   // NIF
	'MAIN_INFO_SOCIETE_TEL'        => '+213 34 19 21 21',
	'MAIN_INFO_SOCIETE_FAX'        => '+213 34 19 21 21',
	'MAIN_INFO_SOCIETE_MAIL'       => 'dpr.axxam2022@gmail.com',
	'MAIN_INFO_SOCIETE_WEB'        => 'https://www.dpraxxam.com',
	'MAIN_INFO_SOCIETE_MANAGERS'   => 'Ahcene DJENNADI',
	'MAIN_INFO_SOCIETE_OBJECT'     => 'Fabrication industrielle de produits en argile (briqueterie) : briques creuses B8, B12 et hourdis',
	'FACTURE_TVAOPTION'            => '1',   // assujetti à la TVA
	'PRODUCT_USE_UNITS'            => '1',   // unités sur les produits (pièce, tonne, m³...)
	'STOCK_CALCULATE_ON_SHIPMENT'  => '1',   // le stock baisse à la validation du bon de livraison
	'STOCK_CALCULATE_ON_SUPPLIER_DISPATCH_ORDER' => '1', // le stock monte à la réception des achats
	'MAIN_SIZE_SHORTLIST_LIMIT'    => '10',
	// Numérotation : FA2610-0001 (factures), AV (avoirs), AC (acomptes)
	'FACTURE_ADDON'                      => 'mod_facture_mercure',
	'FACTURE_MERCURE_MASK_INVOICE'       => 'FA{yy}{mm}-{0000}',
	'FACTURE_MERCURE_MASK_REPLACEMENT'   => 'FR{yy}{mm}-{0000}',
	'FACTURE_MERCURE_MASK_CREDIT'        => 'AV{yy}{mm}-{0000}',
	'FACTURE_MERCURE_MASK_DEPOSIT'       => 'AC{yy}{mm}-{0000}',
	// Sur les factures, devis et bons : RC, NIF et AI du client sous son adresse
	'MAIN_PROFID1_IN_ADDRESS'            => '1',
	'MAIN_PROFID2_IN_ADDRESS'            => '1',
	'MAIN_PROFID3_IN_ADDRESS'            => '1',
);
foreach ($constantes as $nom => $valeur) {
	dolibarr_set_const($db, $nom, $valeur, 'chaine', 0, '', $conf->entity);
}
$db->query("UPDATE ".MAIN_DB_PREFIX."c_currencies SET label = 'dinars algériens' WHERE code_iso = 'DZD'");
etape('société, devise DZD et langue française configurées');

// ---------------------------------------------------------------- Entrepôts
$entrepots = array(
	'PF'  => array('Parc produits finis', 'ZAC Helouane', 'Briques et hourdis cuits, palettisés, prêts à livrer'),
	'MP'  => array('Matières premières', 'ZAC Helouane', 'Argile, emballages (film, palettes)'),
	'MAG' => array('Magasin pièces de rechange', 'ZAC Helouane', 'Pièces et consommables de maintenance'),
);
$idEntrepot = array();
foreach ($entrepots as $ref => $e) {
	$w = new Entrepot($db);
	if ($w->fetch(0, $ref) > 0) { $idEntrepot[$ref] = $w->id; continue; }
	$w->ref = $ref; $w->label = $ref; $w->lieu = $e[0]; $w->description = $e[2];
	$w->address = $e[1]; $w->town = 'Béjaïa'; $w->country_id = 13; $w->statut = 1;
	$id = $w->create($user);
	if ($id > 0) { $idEntrepot[$ref] = $id; etape("entrepôt $ref créé"); } else { etape("ERREUR entrepôt $ref : ".$w->error); }
}

// ---------------------------------------------------------------- Unités
$unite = array();
$res = $db->query("SELECT rowid, code FROM ".MAIN_DB_PREFIX."c_units WHERE active = 1");
while ($res && ($o = $db->fetch_object($res))) { $unite[$o->code] = (int) $o->rowid; }

// ---------------------------------------------------------------- Produits et services
// ref => [libellé, description, type 0=produit 1=service, vendu, acheté, fabriqué, unité, entrepôt par défaut, TVA]
$produits = array(
	// Produits finis (fabriqués et vendus)
	'B8'      => array('Brique creuse 8 trous (B8)', 'Format courant 30 × 20 × 10 cm, 2 rangées d\'alvéoles. Cloisons et paroi intérieure des doubles murs.', 0, 1, 0, 1, 'P', 'PF', 19),
	'B12'     => array('Brique creuse 12 trous (B12)', 'Format courant 30 × 20 × 15 cm, 3 rangées d\'alvéoles. Murs extérieurs et paroi extérieure des doubles murs.', 0, 1, 0, 1, 'P', 'PF', 19),
	'HOURDIS' => array('Hourdis (entrevous) 16', 'Format courant 53 × 20 × 16 cm. Planchers à poutrelles préfabriquées.', 0, 1, 0, 1, 'P', 'PF', 19),
	// Matières et consommables (achetés et stockés)
	'ARGILE'    => array('Argile', 'Matière première extraite de la carrière ou achetée.', 0, 0, 1, 0, 'T', 'MP', 19),
	'FILM-PAL'  => array('Film de palettisation', 'Emballage des palettes.', 0, 0, 1, 0, 'KG', 'MP', 19),
	'PALETTE'   => array('Palette bois', 'Support de palettisation.', 0, 0, 1, 0, 'P', 'MP', 19),
	'GASOIL'    => array('Gasoil', 'Carburant des engins et camions.', 0, 0, 1, 0, 'L', 'MP', 19),
	'PDR'       => array('Pièces de rechange (divers)', 'Pièces et consommables de maintenance. Créez une fiche par pièce suivie en stock si besoin.', 0, 0, 1, 0, 'P', 'MAG', 19),
	// Services achetés
	'GAZ'       => array('Gaz naturel (four et séchoir)', 'Énergie de cuisson et de séchage.', 1, 0, 1, 0, 'M3', '', 19),
	'ELEC'      => array('Électricité', 'Énergie électrique de l\'usine.', 1, 0, 1, 0, '', '', 19),
	'TRANSPORT' => array('Transport', 'Transport de marchandises (achats ou livraisons sous-traitées).', 1, 1, 1, 0, '', '', 19),
	'ENTRETIEN' => array('Entretien et réparation', 'Prestations de maintenance.', 1, 0, 1, 0, '', '', 19),
);
foreach ($produits as $ref => $p) {
	$prod = new Product($db);
	if ($prod->fetch(0, $ref) > 0) continue;
	$prod->ref = $ref;
	$prod->label = $p[0];
	$prod->description = $p[1];
	$prod->type = $p[2];
	$prod->status = $p[3];
	$prod->status_buy = $p[4];
	$prod->finished = $p[2] == 0 ? $p[5] : null;
	$prod->fk_unit = ($p[6] && isset($unite[$p[6]])) ? $unite[$p[6]] : null;
	$prod->fk_default_warehouse = ($p[7] && isset($idEntrepot[$p[7]])) ? $idEntrepot[$p[7]] : null;
	$prod->tva_tx = $p[8];
	$prod->price = 0;
	$prod->price_base_type = 'HT';
	$prod->country_id = 13;
	$id = $prod->create($user);
	if ($id > 0) etape("produit $ref créé"); else etape("ERREUR produit $ref : ".$prod->error.' '.implode(' ', $prod->errors));
}

// ---------------------------------------------------------------- Caisse
$caisse = new Account($db);
if ($caisse->fetch(0, 'CAISSE') <= 0) {
	$caisse->ref = 'CAISSE';
	$caisse->label = 'Caisse usine';
	$caisse->type = Account::TYPE_CASH;
	$caisse->courant = Account::TYPE_CASH;
	$caisse->currency_code = 'DZD';
	$caisse->country_id = 13;
	$caisse->date_solde = dol_now();
	$caisse->solde = 0;
	$caisse->clos = 0;
	$id = $caisse->create($user);
	if ($id > 0) etape('compte « Caisse usine » créé'); else etape('ERREUR caisse : '.$caisse->error);
}

// ---------------------------------------------------------------- Catégories de tiers
$categories = array(
	Categorie::TYPE_CUSTOMER => array('Particuliers', 'Entreprises du bâtiment', 'Revendeurs / dépôts de matériaux', 'Promoteurs et marchés publics'),
	Categorie::TYPE_SUPPLIER => array('Énergie (gaz, électricité, carburant)', 'Pièces de rechange et maintenance', 'Transport', 'Emballage', 'Argile et matières premières'),
);
foreach ($categories as $type => $liste) {
	foreach ($liste as $libelle) {
		$c = new Categorie($db);
		if ($c->fetch(0, $libelle, $type) > 0) continue;
		$c->label = $libelle;
		$c->type = $type;
		$id = $c->create($user);
		if ($id > 0) etape("catégorie « $libelle » créée"); else etape("ERREUR catégorie $libelle : ".$c->error);
	}
}

etape('paramétrage terminé');
