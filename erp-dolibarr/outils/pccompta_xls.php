<?php
/*
 * Écriture d'un classeur .xls au format exact des fichiers d'import de PC Compta (DLG).
 * Portage PHP de pccompta_xls.py (validé : réécrit à l'octet près les fichiers PC Compta).
 *
 *   pcc_xls_ecrire(array $feuilles, string $chemin)
 *   $feuilles = [ ['nom' => 'JOURNAL', 'lignes' => [[...], ...]], ... ] dans l'ordre du modèle ;
 *   cellules : chaînes (UTF-8) ou nombres (int/float).
 *
 * En ligne de commande (vérification) : php pccompta_xls.php export.json sortie.xls
 */

const PCC_XF = 0x0F;
const PCC_BLOC = 8224;
const PCC_FIN = 0xFFFFFFFE;
const PCC_LIBRE = 0xFFFFFFFF;
const PCC_FATSECT = 0xFFFFFFFD;

function pcc_rec($t, $data = '')
{
	return pack('vv', $t, strlen($data)).$data;
}

/** Chaîne BIFF8 : [octets, largeur] — 8 bits si tous les caractères < 256, sinon UTF-16. */
function pcc_chaine($s)
{
	$u16 = mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
	$n = intdiv(strlen($u16), 2);
	$latin = true;
	for ($i = 1; $i < strlen($u16); $i += 2) {
		if ($u16[$i] !== "\0") { $latin = false; break; }
	}
	if ($latin) {
		$car = '';
		for ($i = 0; $i < strlen($u16); $i += 2) $car .= $u16[$i];
		return array(pack('vC', $n, 0).$car, 1);
	}
	return array(pack('vC', $n, 1).$u16, 2);
}

function pcc_table_textes($textes, $total, $debutFlux)
{
	$blocs = array();
	$bloc = pack('VV', $total, count($textes));
	$positions = array();
	foreach ($textes as $s) {
		list($donnees, $taille) = pcc_chaine($s);
		$entete = substr($donnees, 0, 3);
		$car = substr($donnees, 3);
		$place = PCC_BLOC - strlen($bloc);
		if (strlen($donnees) <= $place) {
			$positions[] = array(count($blocs), strlen($bloc));
			$bloc .= $donnees;
			continue;
		}
		if ($place <= strlen($entete) + $taille - 1) {
			$blocs[] = $bloc;
			$bloc = '';
			$positions[] = array(count($blocs), 0);
			$bloc .= $donnees;
			continue;
		}
		$positions[] = array(count($blocs), strlen($bloc));
		$n = intdiv($place - strlen($entete), $taille) * $taille;
		$bloc .= $entete.substr($car, 0, $n);
		$car = (string) substr($car, $n);
		while ($car !== '') {
			$blocs[] = $bloc;
			$n = min(strlen($car), intdiv(PCC_BLOC - 1, $taille) * $taille);
			$bloc = chr($taille - 1).substr($car, 0, $n);
			$car = (string) substr($car, $n);
		}
	}
	$blocs[] = $bloc;
	$octets = '';
	$debuts = array();
	foreach ($blocs as $i => $b) {
		$debuts[] = $debutFlux + strlen($octets);
		$octets .= pcc_rec($i == 0 ? 0x00FC : 0x003C, $b);
	}
	$pos = array();
	foreach ($positions as $p) $pos[] = array($debuts[$p[0]] + 4 + $p[1], $p[1] + 4);
	return array($octets, $pos);
}

function pcc_extsst($positions)
{
	$n = count($positions);
	$parSeau = max(8, intdiv($n, 128) + 1);
	$data = pack('v', $parSeau);
	for ($i = 0; $i < $n; $i += $parSeau) $data .= pack('Vvv', $positions[$i][0], $positions[$i][1], 0);
	return pcc_rec(0x00FF, $data);
}

function pcc_nombre($l, $c, $v)
{
	$v = (float) $v;
	$min = -(1 << 29); $max = (1 << 29);
	if (floor($v) == $v && $v >= $min && $v < $max) {
		return pcc_rec(0x027E, pack('vvvV', $l, $c, PCC_XF, (((int) $v) << 2 | 2) & 0xFFFFFFFF));
	}
	$cent = round($v * 100);
	if (abs($v * 100 - $cent) < 1e-6 && $cent >= $min && $cent < $max && $cent / 100 == $v) {
		return pcc_rec(0x027E, pack('vvvV', $l, $c, PCC_XF, (((int) $cent) << 2 | 3) & 0xFFFFFFFF));
	}
	return pcc_rec(0x0203, pack('vvv', $l, $c, PCC_XF).pack('e', $v));
}

function pcc_classeur($feuilles, $modele)
{
	$enteteG = '';
	foreach ($modele['globaux'] as $r) $enteteG .= pcc_rec($r[0], hex2bin($r[1]));
	$apres = '';
	foreach ($modele['apres_feuilles'] as $r) $apres .= pcc_rec($r[0], hex2bin($r[1]));

	$index = array(); $textes = array(); $total = 0;
	foreach ($feuilles as $f) foreach ($f['lignes'] as $ligne) foreach ($ligne as $v) {
		if (is_string($v)) {
			$total++;
			if (!array_key_exists($v, $index)) { $index[$v] = count($textes); $textes[] = $v; }
		}
	}
	$boundsheet = function ($nom, $position) {
		$u = mb_convert_encoding($nom, 'UTF-16LE', 'UTF-8');
		return pcc_rec(0x0085, pack('VCCCC', $position, 0, 0, intdiv(strlen($u), 2), 1).$u);
	};
	$tailleBs = 0;
	foreach ($feuilles as $f) $tailleBs += strlen($boundsheet($f['nom'], 0));
	$debutSst = strlen($enteteG) + $tailleBs + strlen($apres);
	list($sst, $positions) = pcc_table_textes($textes, $total, $debutSst);
	$globauxFin = $sst.pcc_extsst($positions).pcc_rec(0x000A);

	$corps = ''; $debuts = array();
	$base = $debutSst + strlen($globauxFin);
	foreach ($feuilles as $f) {
		$m = $modele['feuilles'][$f['nom']];
		$debuts[] = $base + strlen($corps);
		$nbCol = 0;
		foreach ($f['lignes'] as $l) $nbCol = max($nbCol, count($l));
		foreach ($m['head'] as $r) {
			$data = hex2bin($r[1]);
			if ($r[0] == 0x0200) $data = pack('VVvvv', 0, count($f['lignes']), 0, $nbCol, 0);
			$corps .= pcc_rec($r[0], $data);
		}
		foreach ($f['lignes'] as $i => $ligne) {
			foreach (array_values($ligne) as $j => $v) {
				$corps .= is_string($v) ? pcc_rec(0x00FD, pack('vvvV', $i, $j, PCC_XF, $index[$v])) : pcc_nombre($i, $j, $v);
			}
		}
		foreach ($m['tail'] as $r) $corps .= pcc_rec($r[0], hex2bin($r[1]));
	}
	$bs = '';
	foreach ($feuilles as $k => $f) $bs .= $boundsheet($f['nom'], $debuts[$k]);
	return $enteteG.$bs.$apres.$globauxFin.$corps;
}

function pcc_ole($flux)
{
	$taille = strlen($flux);
	if ($taille < 4096) { $flux .= str_repeat("\0", 4096 - $taille); $taille = 4096; }
	$n = (int) ceil(strlen($flux) / 512);
	$flux .= str_repeat("\0", $n * 512 - strlen($flux));
	$f = 1;
	while ($n + 1 + $f > $f * 128) $f++;
	$fat = array();
	for ($i = 0; $i < $n - 1; $i++) $fat[] = $i + 1;
	$fat[] = PCC_FIN; $fat[] = PCC_FIN;
	for ($i = 0; $i < $f; $i++) $fat[] = PCC_FATSECT;
	while (count($fat) < $f * 128) $fat[] = PCC_LIBRE;

	$entree = function ($nom, $type, $enfant, $debut, $t) {
		$nom16 = $nom !== '' ? mb_convert_encoding($nom, 'UTF-16LE', 'UTF-8')."\0\0" : '';
		return str_pad($nom16, 64, "\0").pack('vCC', strlen($nom16), $type, $nom !== '' ? 1 : 0)
			.pack('VVV', PCC_LIBRE, PCC_LIBRE, $enfant).str_repeat("\0", 36).pack('VV', $debut, $t).str_repeat("\0", 4);
	};
	$rep = $entree('Root Entry', 5, 1, PCC_FIN, 0).$entree('Workbook', 2, PCC_LIBRE, 0, $taille)
		.$entree('', 0, PCC_LIBRE, 0, 0).$entree('', 0, PCC_LIBRE, 0, 0);
	$difat = array();
	for ($i = 0; $i < 109; $i++) $difat[] = $i < $f ? $n + 1 + $i : PCC_LIBRE;
	$entete = hex2bin('d0cf11e0a1b11ae1').str_repeat("\0", 16).pack('vvvvv', 0x3E, 3, 0xFFFE, 9, 6).str_repeat("\0", 6)
		.pack('VVVVVVVVV', 0, $f, $n, 0, 4096, PCC_FIN, 0, PCC_FIN, 0).pack('V*', ...$difat);
	return $entete.$flux.$rep.pack('V*', ...$fat);
}

function pcc_xls_ecrire($feuilles, $chemin)
{
	$modele = json_decode(file_get_contents(__DIR__.'/modele_pccompta.json'), true);
	return file_put_contents($chemin, pcc_ole(pcc_classeur($feuilles, $modele)));
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
	if ($argc != 3) { fwrite(STDERR, "Usage : php pccompta_xls.php export.json sortie.xls\n"); exit(1); }
	$d = json_decode(file_get_contents($argv[1]), true);
	pcc_xls_ecrire($d['feuilles'], $argv[2]);
	echo "Fichier PC Compta : ".basename($argv[2])."\n";
}
