<?php
/*
 * GMAO DPR AXXAM — import CSV de la liste des équipements (schéma électrique) et des alarmes automate.
 * Séparateur ; ou , ou tabulation, détecté automatiquement. Première ligne = en-têtes.
 */

function gmao_lire_csv($texte)
{
	$texte = preg_replace('/^\xEF\xBB\xBF/', '', str_replace("\r\n", "\n", $texte));
	$premiere = strtok($texte, "\n");
	$sep = ';';
	foreach (array("\t", ';', ',') as $s) if (substr_count($premiere, $s) > substr_count($premiere, $sep)) $sep = $s;
	$lignes = array();
	$f = fopen('php://memory', 'r+'); fwrite($f, $texte); rewind($f);
	$entetes = null;
	while (($l = fgetcsv($f, 0, $sep, '"', '\\')) !== false) {
		if ($l === array(null) || implode('', $l) === '') continue;
		if ($entetes === null) {
			$entetes = array_map(function ($h) {
				$h = strtolower(trim($h));
				$h = strtr($h, array('é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'ç' => 'c', 'ô' => 'o', ' ' => '_', '°' => ''));
				return $h;
			}, $l);
			continue;
		}
		$ligne = array();
		foreach ($entetes as $i => $h) $ligne[$h] = isset($l[$i]) ? trim($l[$i]) : '';
		$lignes[] = $ligne;
	}
	fclose($f);
	return $lignes;
}

function gmao_val($l, $noms)
{
	foreach ((array) $noms as $n) if (isset($l[$n]) && $l[$n] !== '') return $l[$n];
	return '';
}

/**
 * Colonnes reconnues : tag ; nom ; type ; parent (tag du parent) ; armoire ; repere ; puissance_kw ;
 * vitesse ; folio ; criticite ; fabricant ; modele ; numserie ; notes.
 * Les équipements existants (même tag) sont mis à jour : réimporter ne crée pas de doublon.
 */
function gmao_importer_equipements($db, $texte, $E)
{
	$P = MAIN_DB_PREFIX;
	$lignes = gmao_lire_csv($texte);
	$types = array('site', 'atelier', 'zone', 'machine', 'moteur', 'organe', 'armoire');
	$crees = 0; $maj = 0; $erreurs = array(); $parents = array();
	$db->begin();
	foreach ($lignes as $n => $l) {
		$tag = strtoupper(preg_replace('/\s+/', '', gmao_val($l, array('tag', 'code', 'repere_machine'))));
		$nom = gmao_val($l, array('nom', 'designation', 'libelle'));
		if ($tag === '' || $nom === '') { $erreurs[] = 'Ligne '.($n + 2).' : tag ou nom manquant'; continue; }
		$type = strtolower(gmao_val($l, 'type')); if (!in_array($type, $types)) $type = 'machine';
		$crit = strtoupper(gmao_val($l, 'criticite')); if (!in_array($crit, array('A', 'B', 'C'))) $crit = 'B';
		$kw = str_replace(',', '.', gmao_val($l, array('puissance_kw', 'kw', 'puissance')));
		$v = function ($x, $max = 255) use ($db) { return $x === '' ? 'NULL' : "'".$db->escape(mb_substr($x, 0, $max))."'"; };
		$champs = array(
			'nom' => $v($nom), 'type' => "'$type'", 'armoire' => $v(gmao_val($l, 'armoire'), 64),
			'repere' => $v(gmao_val($l, array('repere', 'reperes', 'repere_electrique')), 128),
			'puissance_kw' => is_numeric($kw) ? (float) $kw : 'NULL', 'vitesse' => $v(gmao_val($l, 'vitesse'), 32),
			'folio' => $v(gmao_val($l, array('folio', 'page', 'page_schema')), 64), 'criticite' => "'$crit'",
			'fabricant' => $v(gmao_val($l, 'fabricant'), 128), 'modele' => $v(gmao_val($l, 'modele'), 128),
			'numserie' => $v(gmao_val($l, array('numserie', 'n_serie', 'serie')), 128), 'notes' => $v(gmao_val($l, 'notes'), 65000),
		);
		$res = $db->query("SELECT rowid FROM {$P}gmao_equipement WHERE tag = '".$db->escape($tag)."' AND entity = $E");
		if ($o = $db->fetch_object($res)) {
			$set = array(); foreach ($champs as $k => $x) if ($x !== 'NULL') $set[] = "$k = $x";
			$db->query("UPDATE {$P}gmao_equipement SET ".implode(', ', $set)." WHERE rowid = ".(int) $o->rowid);
			$maj++;
		} else {
			$champs['tag'] = "'".$db->escape($tag)."'"; $champs['entity'] = $E; $champs['date_creation'] = 'NOW()';
			if (!$db->query("INSERT INTO {$P}gmao_equipement (".implode(', ', array_keys($champs)).") VALUES (".implode(', ', $champs).")")) {
				$erreurs[] = 'Ligne '.($n + 2).' : '.$db->lasterror(); continue;
			}
			$crees++;
		}
		$pt = strtoupper(preg_replace('/\s+/', '', gmao_val($l, array('parent', 'tag_parent'))));
		if ($pt !== '') $parents[$tag] = $pt;
	}
	// second passage : rattachement aux parents (le parent peut être plus bas dans le fichier)
	foreach ($parents as $tag => $pt) {
		$r = $db->query("SELECT rowid FROM {$P}gmao_equipement WHERE tag = '".$db->escape($pt)."' AND entity = $E");
		if ($o = $db->fetch_object($r)) {
			$db->query("UPDATE {$P}gmao_equipement SET fk_parent = ".(int) $o->rowid." WHERE tag = '".$db->escape($tag)."' AND entity = $E AND tag <> '".$db->escape($pt)."'");
		} else {
			$erreurs[] = "Parent $pt introuvable pour $tag";
		}
	}
	$db->commit();
	return array('ok' => 1, 'crees' => $crees, 'mis_a_jour' => $maj, 'erreurs' => $erreurs);
}

/** Colonnes : code ; texte ; tag (équipement concerné) ; causes ; remede. */
function gmao_importer_alarmes($db, $texte, $E)
{
	$P = MAIN_DB_PREFIX;
	$crees = 0; $maj = 0; $erreurs = array();
	$db->begin();
	foreach (gmao_lire_csv($texte) as $n => $l) {
		$code = gmao_val($l, array('code', 'numero', 'n', 'bit', 'adresse'));
		$txt = gmao_val($l, array('texte', 'message', 'libelle', 'description'));
		if ($code === '' || $txt === '') { $erreurs[] = 'Ligne '.($n + 2).' : code ou texte manquant'; continue; }
		$eq = 'NULL';
		$tag = strtoupper(preg_replace('/\s+/', '', gmao_val($l, array('tag', 'equipement', 'machine'))));
		if ($tag !== '') {
			$r = $db->query("SELECT rowid FROM {$P}gmao_equipement WHERE tag = '".$db->escape($tag)."' AND entity = $E");
			if ($o = $db->fetch_object($r)) $eq = (int) $o->rowid; else $erreurs[] = "Alarme $code : équipement $tag introuvable";
		}
		$c = "'".$db->escape(mb_substr($code, 0, 64))."'";
		$vals = array('texte' => "'".$db->escape(mb_substr($txt, 0, 255))."'", 'fk_equipement' => $eq,
			'causes' => gmao_val($l, 'causes') === '' ? 'NULL' : "'".$db->escape(gmao_val($l, 'causes'))."'",
			'remede' => gmao_val($l, array('remede', 'action', 'que_faire')) === '' ? 'NULL' : "'".$db->escape(gmao_val($l, array('remede', 'action', 'que_faire')))."'");
		$r = $db->query("SELECT rowid FROM {$P}gmao_alarme WHERE code = $c AND entity = $E");
		if ($o = $db->fetch_object($r)) {
			$set = array(); foreach ($vals as $k => $x) if ($x !== 'NULL') $set[] = "$k = $x";
			$db->query("UPDATE {$P}gmao_alarme SET ".implode(', ', $set)." WHERE rowid = ".(int) $o->rowid); $maj++;
		} else {
			$db->query("INSERT INTO {$P}gmao_alarme (entity, code, ".implode(', ', array_keys($vals)).") VALUES ($E, $c, ".implode(', ', $vals).")"); $crees++;
		}
	}
	$db->commit();
	return array('ok' => 1, 'crees' => $crees, 'mis_a_jour' => $maj, 'erreurs' => $erreurs);
}
