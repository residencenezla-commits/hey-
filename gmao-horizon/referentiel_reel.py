#!/usr/bin/env python3
"""Référentiel RÉEL de la maintenance DPR AXXAM, tiré des schémas électriques Marcheluzzo
(E5 « Quadro 1 Lavorazione », E5A « Quadro 2 Lavorazione », E5B pupitre, E4A « Quadro mobile navetta »)
et du catalogue Messersì MS500. Les relevés page par page sont dans schemas/*.csv et schemas/*.md.

Produit erp-dolibarr/modules/gmao/referentiel/{equipements,plans,pieces}_preparation.csv,
chargés par init/40-maintenance.php au premier démarrage (ou à importer par l'onglet Import).
Usage : python3 gmao-horizon/referentiel_reel.py
"""
import csv
from pathlib import Path

ICI = Path(__file__).resolve().parent
SCH = ICI / 'schemas'
SORTIE = ICI.parent / 'erp-dolibarr' / 'modules' / 'gmao' / 'referentiel'
ENT = ['tag', 'nom', 'type', 'parent', 'armoire', 'repere', 'puissance_kw', 'vitesse', 'folio', 'criticite', 'fabricant', 'modele', 'notes']

def lire(nom):
    with open(SCH / nom, encoding='utf-8') as f:
        return list(csv.DictReader(f, delimiter=';'))

L = []          # lignes du référentiel
def ajout(**k):
    L.append({c: k.get(c, '') for c in ENT})

# ------------------------------------------------------------------ structure du site
ajout(tag='DPR-SITE', nom='Briqueterie DPR AXXAM — ZAC Helouane', type='site', criticite='B', notes='Ighzer Amokrane (Béjaïa)')
ATELIERS = [
    ('DPR-PRE', "Atelier Préparation de l'argile", 'Caisson doseur, laminoir, répartiteur, convoyeurs 1 à 3 (armoire Quadro 1 Lavorazione)'),
    ('DPR-FAC', 'Atelier Façonnage (étireuse)', 'Mélangeurs, étireuse, pompe à vide, convoyeurs 4 à 6 (armoire Quadro 2 Lavorazione)'),
    ('DPR-TRS', 'Transbordement des wagons (navette)', 'Navette et ses tapis / lanceurs (armoire mobile navetta)'),
    ('DPR-SEC', 'Séchoir tunnel', 'Schémas à recevoir'),
    ('DPR-FOU', 'Four tunnel (84 m)', 'Schémas à recevoir'),
    ('DPR-CND', 'Déchargement, palettisation et cerclage', 'Robot, cercleuse (schémas à recevoir)'),
    ('DPR-ELEC', 'Locaux électriques et automatismes', 'Armoires, pupitre, automates Siemens'),
]
for t, n, d in ATELIERS:
    ajout(tag=t, nom=n, type='atelier', parent='DPR-SITE', criticite='B', notes=d)

# ------------------------------------------------------------------ armoires et automatisme
ARM = {'Q1': 'Quadro 1 Lavorazione (E5)', 'Q2': 'Quadro 2 Lavorazione (E5A)', 'PUP': 'Pupitre Lavorazione (E5B)', 'NAV': 'Armoire mobile navette (E4A)'}
ajout(tag='DPR-ARM-Q1', nom='Armoire Quadro 1 Lavorazione — préparation', type='armoire', parent='DPR-ELEC', armoire=ARM['Q1'], folio='300-375', criticite='A', fabricant='Marcheluzzo Impianti',
      notes='Commande 1361_100, schéma E5 VS.1 du 16/01/2014, 3×400 V 50 Hz. Automate Siemens S7-300 CPU 315-2 PN/DP (6ES7 315-2EH14-0AB0), folio 340.')
ajout(tag='DPR-ARM-Q2', nom='Armoire Quadro 2 Lavorazione — façonnage', type='armoire', parent='DPR-ELEC', armoire=ARM['Q2'], folio='400-474', criticite='A', fabricant='Marcheluzzo Impianti',
      notes='Schéma E5A VS.1 du 16/01/2014. Variateurs Mitsubishi en Profibus (DP52, DP53, DP54), démarreur progressif pompe à vide (DP62).')
ajout(tag='DPR-ARM-PUP', nom='Pupitre de commande Lavorazione', type='armoire', parent='DPR-ELEC', armoire=ARM['PUP'], folio='500-521', criticite='A', fabricant='Marcheluzzo Impianti',
      notes='Écran tactile Siemens TP1200 12" (6AV2124-0MC01-0XA0), 2 régulateurs de mouillage Novatronic C2 (folio 507).')
ajout(tag='DPR-ARM-NAV', nom='Armoire mobile de la navette', type='armoire', parent='DPR-ELEC', armoire=ARM['NAV'], folio='200-252', criticite='A', fabricant='Marcheluzzo Impianti',
      notes='Schéma E4A VS.1 du 16/01/2014. Automate Siemens S7-300 CPU 314-2 PN/DP, liaison Wi-Fi industrielle Siemens IWLAN ; variateurs en Profibus.')
ajout(tag='DPR-AUT-Q1', nom='Automate Siemens S7-300 CPU 315-2 PN/DP (ligne de fabrication)', type='organe', parent='DPR-ARM-Q1', armoire=ARM['Q1'], repere='CPU 315-2', folio='340', criticite='A',
      fabricant='Siemens', modele='6ES7 315-2EH14-0AB0', notes='Cartes SM321 DI32 (321-1BL00-0AA0) ×2, SM322 DO32 (322-1BL00-0AA0) ; Profibus + Ethernet vers Quadro 2. Sauvegarder le programme avant toute intervention.')
ajout(tag='DPR-AUT-Q2', nom='Station E/S déportée ET200M (Quadro 2)', type='organe', parent='DPR-ARM-Q2', armoire=ARM['Q2'], repere='IM153-4PN', folio='440', criticite='A',
      fabricant='Siemens', modele='6ES7 153-4AA01-0XB0', notes='Cartes SM321 / SM322 et entrées analogiques AI8.')
ajout(tag='DPR-HMI-PUP', nom='Écran tactile du pupitre TP1200', type='organe', parent='DPR-ARM-PUP', armoire=ARM['PUP'], folio='508-510', criticite='A', fabricant='Siemens', modele='6AV2124-0MC01-0XA0')
ajout(tag='DPR-REG-M156', nom='Régulateur de mouillage du mélangeur (Novatronic C2)', type='organe', parent='DPR-ARM-PUP', armoire=ARM['PUP'], repere='M156', folio='507', criticite='A', fabricant='Novatronic', modele='C2',
      notes='Électrovannes et régulation de l\'eau du mélangeur.')
ajout(tag='DPR-REG-M159', nom="Régulateur de mouillage de l'étireuse (Novatronic C2)", type='organe', parent='DPR-ARM-PUP', armoire=ARM['PUP'], repere='M159', folio='507', criticite='A', fabricant='Novatronic', modele='C2',
      notes='Avec plastomètre et sonde PT100.')
ajout(tag='DPR-AUT-NAV', nom='Automate de la navette S7-300 CPU 314-2 PN/DP', type='organe', parent='DPR-ARM-NAV', armoire=ARM['NAV'], repere='CPU 314-2', criticite='A', fabricant='Siemens',
      notes='Liaison sans fil IWLAN avec la ligne.')

# ------------------------------------------------------------------ machines (nouveaux tags lisibles)
MACH = {  # tag extraction → (nouveau tag, nom, atelier, armoire, criticité, notes)
    'DPR-E5-DOSA': ('DPR-PRE-DOS1', "Caisson doseur d'argile", 'DPR-PRE', 'Q1', 'A', 'cassone dosatore'),
    'DPR-E5-OLI': ('DPR-PRE-HUIL', 'Huileur (graissage automatique)', 'DPR-PRE', 'Q1', 'B', 'oliatore — machine desservie à préciser'),
    'DPR-E5-NAS1': ('DPR-PRE-CVY1', 'Convoyeur à bande n°1', 'DPR-PRE', 'Q1', 'A', 'nastro 1, frein SEW'),
    'DPR-E5-REP': ('DPR-PRE-REP', 'Répartiteur double', 'DPR-PRE', 'Q1', 'A', 'ripartitore doppio'),
    'DPR-E5-LAM': ('DPR-PRE-LAM', 'Laminoir (cylindres rapide et lent)', 'DPR-PRE', 'Q1', 'A', 'laminatoio ; 2 × 110 kW sur démarreurs progressifs, outils de rectification des cylindres'),
    'DPR-E5-NAS2': ('DPR-PRE-CVY2', 'Convoyeur à bande n°2', 'DPR-PRE', 'Q1', 'A', 'nastro 2, frein SEW'),
    'DPR-E5-NAS3': ('DPR-PRE-CVY3', 'Convoyeur à bande n°3', 'DPR-PRE', 'Q1', 'A', 'nastro 3, frein SEW'),
    'DPR-E5A-NAS4': ('DPR-FAC-CVY4', 'Convoyeur à bande n°4', 'DPR-FAC', 'Q2', 'A', 'nastro 4, frein SEW'),
    'DPR-E5A-NAS5': ('DPR-FAC-CVY5', 'Convoyeur à bande n°5', 'DPR-FAC', 'Q2', 'A', 'nastro 5, frein SEW'),
    'DPR-E5A-NAS6': ('DPR-FAC-CVY6', 'Convoyeur à bande n°6', 'DPR-FAC', 'Q2', 'A', 'nastro 6, frein SEW'),
    'DPR-E5A-MEL': ('DPR-FAC-MEL', 'Mélangeur (malaxeur humidificateur)', 'DPR-FAC', 'Q2', 'A', 'mescolatore ; mouillage M156'),
    'DPR-E5A-MELETI': ('DPR-FAC-MELDEG', "Mélangeur-dégazeur de l'étireuse", 'DPR-FAC', 'Q2', 'A', 'mescolatore mattoniera ; mouillage M159'),
    'DPR-E5A-ETI': ('DPR-FAC-ETI', 'Étireuse (mouleuse)', 'DPR-FAC', 'Q2', 'A', 'mattoniera ; moteur principal 315 kW'),
    'DPR-E5A-PVIDE': ('DPR-FAC-PVIDE', 'Pompe à vide', 'DPR-FAC', 'Q2', 'A', 'pompa vuoto'),
    'DPR-NAV': ('DPR-NAV', 'Navette (chariot transbordeur) avec tapis 9A/9B/10/11 et lanceurs', 'DPR-TRS', 'NAV', 'A', 'navetta'),
}
for ancien, (t, n, at, arm, cr, notes) in MACH.items():
    ajout(tag=t, nom=n, type='machine', parent=at, armoire=ARM[arm], criticite=cr, fabricant='Marcheluzzo Impianti', notes=notes)

# ------------------------------------------------------------------ moteurs et organes relevés sur les schémas
CORR = {  # corrections après recoupement des deux lectures indépendantes (E5/E5A et LAVORAZIONE)
    'DPR-E5A-M157': {'notes_plus': 'Puissance : 3 kW / 6,2 A au folio 415, mais 4 kW / 8 A au bornier folio 470 — lire la plaque.'},
    'DPR-E5-318Q1': {'notes_plus': 'Moteur M149 selon la seconde lecture ; départ marqué « réserve » au folio 318 — vérifier sur place.'},
    'DPR-E5-318Q2': {'notes_plus': 'Moteur M150 selon la seconde lecture ; départ marqué « réserve » au folio 318 — vérifier sur place.'},
}
def nouveau_tag(t):
    t = t.replace('/', '-')
    for pre in ('DPR-E5A-', 'DPR-E5-'):
        if t.startswith(pre):
            rest = t[len(pre):]
            return 'DPR-' + rest if rest.startswith('M') and rest[1:2].isdigit() else 'DPR-Q' + ('2' if pre == 'DPR-E5A-' else '1') + '-' + rest
    if t.startswith('DPR-NAV-M') and t[9:10].isdigit():
        return 'DPR-' + t[8:]
    return t
for fichier, arm in (('E5.csv', 'Q1'), ('E5A.csv', 'Q2'), ('NAV.csv', 'NAV')):
    for r in lire(fichier):
        if r['type'] not in ('moteur', 'organe') or r['tag'] in ('DPR-E5A-ET200',):
            continue
        parent = MACH[r['parent']][0] if r['parent'] in MACH else r['parent']
        if r['tag'] == 'DPR-E5A-QPVIDE':
            parent = 'DPR-FAC-PVIDE'
        notes = r['notes']
        if r['tag'] in CORR:
            notes = (notes + ' — ' if notes else '') + CORR[r['tag']]['notes_plus']
        modele = r['modele']
        if modele.startswith('FR-AF740'):
            notes = (notes + ' — ' if notes else '') + f'Variateur noté « {modele} » sur le schéma (probablement FR-A740 / FR-F740) : lire la plaque.'
        ajout(tag=nouveau_tag(r['tag']), nom=r['nom'], type=r['type'], parent=parent, armoire=ARM[arm], repere=r['repere'], puissance_kw=r['puissance_kw'],
              vitesse=r['vitesse'], folio=r['folio'], criticite=r['criticite'] or 'B', fabricant=r['fabricant'] or ('Mitsubishi' if modele.startswith('FR-') else ('Siemens' if modele.startswith('3RW') else '')),
              modele=modele, notes=notes)

# ------------------------------------------------------------------ cercleuse (catalogue Messersì MS500)
ajout(tag='DPR-CND-CER-01', nom='Cercleuse des paquets de briques', type='machine', parent='DPR-CND', criticite='A', fabricant='Messersì Packaging', notes='Tête de cerclage MS500 ; schéma électrique à recevoir')
ajout(tag='DPR-CND-CER-01-TETE', nom='Cercleuse — tête de cerclage MS500', type='organe', parent='DPR-CND-CER-01', criticite='A', fabricant='Messersì Packaging', modele='MS500',
      notes='Catalogue pièces REV.13 / 12-2013 ; 202 références dans l\'onglet Pièces, dont 20 conseillées en stock.')

tags = [l['tag'] for l in L]
assert len(tags) == len(set(tags)), 'tag en double'
for l in L:
    assert not l['parent'] or l['parent'] in tags, f"parent introuvable : {l['parent']} pour {l['tag']}"

# ------------------------------------------------------------------ plans préventifs adaptés aux machines réelles
SECU = "Consignation de l'armoire (sectionneur local SZ cadenassé) avant toute intervention ; EPI : casque, gants, chaussures, lunettes ; jamais d'intervention sur bande ou cylindre en mouvement."
P = []
def plan(nom, tag, freq, duree, etapes, secu=SECU):
    P.append([nom, tag, freq, duree, '\n'.join(f'{i}. {e}' for i, e in enumerate(etapes, 1)), secu])
plan('PM Quotidien — Caisson doseur', 'DPR-PRE-DOS1', 1, 15, ["Absence de blocs / corps étrangers dans le caisson", "Chaîne doseuse (M137) : maillons, tension, lattes cassées (O/N)", "Piocheur (M138) : dents, bruit", "Variateurs 313I1 / 314I1 : pas de défaut affiché"])
plan('PM Quotidien — Laminoir', 'DPR-PRE-LAM', 1, 20, ["Graisser les paliers des cylindres", "Écartement des cylindres à la jauge — noter en mm", "Températures paliers (infrarouge) — noter °C", "Huileur (M139) : niveau d'huile et débit", "Démarreurs progressifs 316A1 / 317A1 : pas de défaut"])
plan('PM Hebdomadaire — Laminoir', 'DPR-PRE-LAM', 7, 60, ["Courroies et poulies des 2 moteurs 110 kW : tension, usure", "Usure / ovalisation des cylindres — noter", "Outils de rectification (M149 / M150) : course et état", "Serrage bâti et paliers", "Niveau d'huile des réducteurs"])
plan('PM Semestriel — Laminoir', 'DPR-PRE-LAM', 182, 480, ["Mesurer le diamètre des cylindres en 3 points", "Rectifier les cylindres (outils de rectification) si hors tolérance", "Remplacer les courroies usées", "Vidange des réducteurs", "Contrôle isolement des moteurs M144 / M145 (mégohmmètre)"])
plan('PM Quotidien — Mélangeur', 'DPR-FAC-MEL', 1, 20, ["Graisser les paliers des arbres", "Pompe à huile (M155/1) : pression / débit", "Mouillage M156 : débit d'eau, buses non bouchées, régulateur Novatronic sans alarme", "Nettoyer la pâte durcie"])
plan('PM Mensuel — Mélangeur', 'DPR-FAC-MEL', 30, 120, ["Usure des pales — remplacer hors tolérance", "Jeu pales / auge", "Serrage des bras", "Variateur M155 : ventilation propre, codes défauts relevés"])
plan("PM Quotidien — Mélangeur-dégazeur de l'étireuse", 'DPR-FAC-MELDEG', 1, 20, ["Pompe à huile (M158/1) : pression", "Mouillage M159 : débit, plastomètre, sonde PT100", "Étanchéité de la chambre de dégazage"])
plan('PM Quotidien — Étireuse', 'DPR-FAC-ETI', 1, 30, ["Pompe à huile (M163) : niveau et pression", "Ventilateur moteur (M163/1) en marche", "Valeur du vide — noter (mbar)", "Filière : propreté, usure des noyaux", "Variateur M160 (315 kW) : pas d'alarme, température"])
plan('PM Mensuel — Étireuse', 'DPR-FAC-ETI', 30, 240, ["Usure de l'hélice — noter", "Aspo 1 et 2 (M161 / M162) : état", "Courroies / accouplement moteur principal", "Nettoyage du variateur et de ses filtres"])
plan('PM Quotidien — Pompe à vide', 'DPR-FAC-PVIDE', 1, 15, ["Niveau d'eau / d'huile de la pompe", "Vide atteint — noter (mbar)", "Filtre d'aspiration propre", "Démarreur progressif 421A1 sans défaut"])
for i, t in enumerate(['DPR-PRE-CVY1', 'DPR-PRE-CVY2', 'DPR-PRE-CVY3', 'DPR-FAC-CVY4', 'DPR-FAC-CVY5', 'DPR-FAC-CVY6'], 1):
    plan(f'PM Hebdomadaire — Convoyeur n°{i}', t, 7, 20, ["Centrage et tension de la bande", "Rouleaux bloqués ou bruyants — noter le nombre", "Racleurs et jonction de bande", "Frein SEW : réglage de l'entrefer, redresseur BGE", "Arrêt d'urgence à câble : essai"])
plan('PM Hebdomadaire — Répartiteur double', 'DPR-PRE-REP', 7, 20, ["Mécanisme et bande du répartiteur", "Moteur M143 : bruit, échauffement", "Fins de course de position : essai"])
plan('PM Hebdomadaire — Navette', 'DPR-NAV', 7, 60, ["Essai des fins de course nord / sud (231MC2, 231MC3) et d'urgence (231MC4, 232MC1)", "Détecteurs de rotation (PX) : propreté, distance", "Frein SEW du moteur de translation (M130)", "Rails et galets : usure, propreté", "Arrêt d'urgence à câble (231MC1) et coup de poing (11S2) : essai", "Liaison Wi-Fi IWLAN : pas de coupure signalée"])
plan('PM Mensuel — Navette', 'DPR-NAV', 30, 120, ["Tapis 9A / 9B / 10 / 11 : bandes, rouleaux", "Lanceurs 10 et 11 (M133 à M136)", "Serrage des borniers de l'armoire mobile (hors tension)", "Câbles et alimentation de la navette"])
for t, n in (('DPR-ARM-Q1', 'Quadro 1'), ('DPR-ARM-Q2', 'Quadro 2'), ('DPR-ARM-NAV', 'navette')):
    plan(f'PM Mensuel — Armoire {n}', t, 30, 60, ["Dépoussiérage (aspiration, pas d'air comprimé sur les cartes)", "Filtres de ventilation", "Codes défauts des variateurs et démarreurs — relever", "Échauffement des borniers (caméra thermique si disponible)"],
         "Électricien habilité uniquement ; armoire consignée ; vérifier l'absence de tension.")
plan('PM Trimestriel — Sauvegarde automates', 'DPR-AUT-Q1', 91, 60, ["Sauvegarde du programme des CPU 315-2 et 314-2 (navette)", "Sauvegarde du projet de l'écran TP1200", "Vérifier la pile / carte mémoire des CPU", "Noter la version et ranger la copie hors de l'usine"], "Ne rien modifier au programme sans l'accord du responsable.")
plan('PM Hebdomadaire — Cercleuse MS500', 'DPR-CND-CER-01', 7, 30, ["Nettoyer les canaux de feuillard", "Lame et lame de soudage : usure", "Résistance de soudage : température", "Ressorts de pinces", "Essai de cerclage et tension"])
for p in P:
    assert p[1] in tags, p

# ------------------------------------------------------------------ pièces : consommables des machines + catalogue MS500
PI = [
    ['Bande transporteuse (largeur à relever)', '', 'DPR-PRE-CVY1', 'mètre', 0, 0],
    ['Rouleau porteur de convoyeur', '', 'DPR-PRE-CVY1', 'pièce', 0, 10],
    ['Rouleau de retour de convoyeur', '', 'DPR-PRE-CVY1', 'pièce', 0, 5],
    ['Racleur de bande (lame)', '', 'DPR-PRE-CVY1', 'pièce', 0, 4],
    ['Garniture / disque de frein SEW de convoyeur', '', 'DPR-PRE-CVY1', 'pièce', 0, 1],
    ['Redresseur de frein SEW BGE', 'BGE', 'DPR-PRE-CVY1', 'pièce', 0, 1],
    ['Roulement de palier — laminoir', 'réf. sur palier', 'DPR-PRE-LAM', 'pièce', 0, 2],
    ['Courroie — moteurs du laminoir', 'réf. sur courroie', 'DPR-PRE-LAM', 'jeu', 0, 1],
    ['Pale d\'usure du mélangeur', '', 'DPR-FAC-MEL', 'pièce', 0, 0],
    ['Buse de mouillage', '', 'DPR-FAC-MEL', 'pièce', 0, 4],
    ['Hélice / pièces d\'usure de l\'étireuse', '', 'DPR-FAC-ETI', 'jeu', 0, 0],
    ['Noyaux de filière (B8 / B12 / hourdis)', '', 'DPR-FAC-ETI', 'jeu', 0, 1],
    ['Huile réducteurs (grade selon plaque)', 'ISO VG 220/320', 'DPR-PRE-LAM', 'litre', 0, 0],
    ['Graisse paliers EP2', '', 'DPR-PRE-LAM', 'kg', 0, 0],
    ['Fin de course de translation de la navette', 'à relever', 'DPR-NAV', 'pièce', 0, 1],
    ['Détecteur de proximité (PX) de la navette', 'à relever', 'DPR-NAV', 'pièce', 0, 2],
]
with open(SCH / 'MS500_pieces.csv', encoding='utf-8') as f:
    for r in csv.DictReader(f, delimiter=';'):
        PI.append([r['nom'], r['reference'], 'DPR-CND-CER-01-TETE', r['unite'], 0, r['seuil']])
for p in PI:
    assert p[2] in tags, p

SORTIE.mkdir(parents=True, exist_ok=True)
def ecrire(nom, entetes, rows):
    with open(SORTIE / nom, 'w', newline='', encoding='utf-8') as f:
        w = csv.writer(f, delimiter=';'); w.writerow(entetes); w.writerows(rows)
ecrire('equipements_preparation.csv', ENT, [[l[c] for c in ENT] for l in L])
ecrire('plans_preparation.csv', ['nom', 'tag', 'frequence_j', 'duree_min', 'checklist', 'securite'], P)
ecrire('pieces_preparation.csv', ['nom', 'reference', 'tag', 'unite', 'stock', 'seuil_conseille'], PI)
mot = [l for l in L if l['type'] == 'moteur']
kw = sum(float(l['puissance_kw'] or 0) for l in mot)
print(f"{len(L)} équipements ({sum(l['type'] == 'machine' for l in L)} machines, {len(mot)} moteurs = {kw:.1f} kW, "
      f"{sum(l['type'] == 'organe' for l in L)} organes), {len(P)} plans, {len(PI)} pièces → {SORTIE}")
