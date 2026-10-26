#!/usr/bin/env python3
"""Référentiel RÉEL de la maintenance DPR AXXAM, tiré des schémas électriques Marcheluzzo
(E5 « Quadro 1 Lavorazione », E5A « Quadro 2 Lavorazione », E5B pupitre, E4A « Quadro mobile navetta »)
et du catalogue Messersì MS500, complété par l'offre Marcheluzzo 2015-063 (séchoir, four, robots, levage) et le plan
d'implantation 86S-13-027E (moyens de protection). Les relevés page par page sont dans schemas/*.csv et schemas/*.md.

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
    ('DPR-SEC', 'Séchoir tunnel', 'Schémas à recevoir ; offre 2015-063 : séchoir rapide DT7, 2 lignes de 22 wagons, 486 t/jour, 5,3 h'),
    ('DPR-FOU', 'Four tunnel (84 m)', 'Schémas à recevoir ; offre 2015-063 : 88,8 × 4,2 m, 26 + 1 wagons, 423 t/jour, cycle 17 h'),
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

# ------------------------------------------------------------------ offre Marcheluzzo 2015-063 (150 000 t/an) et plan 86S-13-027E
# Données du contrat, pas des schémas : l'usine construite diffère parfois (étireuse 315 kW installés contre 250 kW à l'offre),
# chaque fiche porte donc la mention « à vérifier sur site ».
OFFRE = 'Offre Marcheluzzo 2015-063 REF. {} — à vérifier sur site (plaque, nombre, puissance).'
def offre(ref, **k):
    k['notes'] = OFFRE.format(ref) + (' ' + k['notes'] if k.get('notes') else '')
    k.setdefault('fabricant', 'Marcheluzzo Impianti')
    k.setdefault('criticite', 'B')
    ajout(**k)
ajout(tag='DPR-HSE', nom='Moyens de protection et sécurité du site', type='atelier', parent='DPR-SITE', criticite='A',
      notes='Aspiration des poussières, détection, levage, incendie, gaz : tout ce qui protège les personnes.')
# préparation : levage et protection (REF. 133 à 146)
offre('133', tag='DPR-PRE-AIM', nom="Aimant permanent sur tapis d'argile", type='machine', parent='DPR-PRE', notes='Retient la ferraille avant le laminoir.')
offre('137', tag='DPR-PRE-DMET', nom="Détecteur de métaux sur le tapis d'argile", type='machine', parent='DPR-PRE', criticite='A', notes='Protège les cylindres du laminoir.')
offre('136', tag='DPR-HSE-ASP', nom='Aspirateur de poussière des laminoirs (tuyauterie et hottes)', type='machine', parent='DPR-HSE', criticite='A',
      notes='Protection respiratoire collective de l\'atelier préparation (poussière de silice).')
offre('145', tag='DPR-HSE-PONT', nom='Pont roulant 7 t du laminoir', type='machine', parent='DPR-HSE', criticite='A',
      notes='Plan 86S-13-023D « Carroponte » (DWG). Appareil de levage : vérification périodique obligatoire par organisme agréé.')
offre('146', tag='DPR-HSE-GRUE', nom='Grue à flèche — montage de la filière et du gueulard', type='machine', parent='DPR-HSE', criticite='A', notes='Appareil de levage.')
offre('105/108', tag='DPR-PRE-TOUR', nom='Tours à charioter les cylindres (rectification)', type='machine', parent='DPR-PRE', notes='Type wdh 100pa.')
# coupe et chargement du vert (REF. 200 à 217)
offre('200-202', tag='DPR-FAC-COUP', nom='Ligne de pré-coupe et coupeur à harpe', type='machine', parent='DPR-FAC', criticite='A', notes='Coupes de 200 et 300 mm ; vérin pneumatique d\'escamotage du tapis.')
offre('202', tag='DPR-FAC-CADRE', nom='Cadre de chargement du wagon séchoir avec lame de chargement articulée', type='machine', parent='DPR-FAC', criticite='A',
      notes='Wagon de translation + lame articulée réglée par ressorts pneumatiques (torpress) qui dépose le module de briques crues sur l\'étage du wagon. Écran « Manuels ligne de chargement ».')
ajout(tag='DPR-FAC-CADRE-INM212', nom='Variateur INM212 — lame de chargement', type='organe', parent='DPR-FAC-CADRE', repere='INM212', criticite='A',
      fabricant='Mitsubishi Electric', modele='série FR-E700 (plaque à relever)',
      notes='Étiquette « M212 / P54 » sur la façade (P54 : probablement le n° de nœud Profibus). Défaut relevé le 26/10/2026 : E.THT (surcharge du variateur). Variateur très empoussiéré. Schéma de la ligne de chargement à recevoir.')
offre('203', tag='DPR-FAC-TRIT', nom='Triturateur mono-arbre des déchets verts', type='machine', parent='DPR-FAC')
offre('204', tag='DPR-FAC-ASC1', nom='Ascenseur de chargement du wagon séchoir', type='machine', parent='DPR-FAC', criticite='A', notes='Zone de chute de charge : accès interdit en marche.')
offre('205', tag='DPR-FAC-ASC2', nom='Ascenseur de déchargement du wagon séchoir', type='machine', parent='DPR-FAC', criticite='A', notes='Zone de chute de charge : accès interdit en marche.')
offre('206', tag='DPR-FAC-POUS', nom='Pousseur de déchargement du sec', type='machine', parent='DPR-FAC')
offre('207-208', tag='DPR-FAC-BAV', nom='Tapis de récupération et remontée des bavures', type='machine', parent='DPR-FAC', notes='Tapis 900 × 20 000 mm et tapis vertical vers le malaxeur.')
# séchoir rapide DT7 (REF. 350 à 413)
offre('350/354/360', tag='DPR-SEC-TRB', nom='Transbordeurs du séchoir (entrée, sortie, service)', type='machine', parent='DPR-SEC', criticite='A', notes='Pousseur à bord et dispositif de centrage.')
offre('351/357', tag='DPR-SEC-PORT', nom='Portes d\'entrée et de sortie des 2 séchoirs', type='machine', parent='DPR-SEC')
offre('352/353/355/359', tag='DPR-SEC-POUS', nom='Pousseurs et recherches à chaîne du séchoir', type='machine', parent='DPR-SEC', criticite='A', notes='Deux lignes de séchage, 22 + 22 wagons, 5,3 h, 90 °C max.')
offre('400', tag='DPR-SEC-GEN', nom='Générateur de chaleur à gaz du séchoir (3 000 000 kcal/h)', type='machine', parent='DPR-SEC', criticite='A',
      notes='Gaz naturel 300 mbar, 160 °C max, rampe gaz et armoire propres.')
# rampe gaz et brûleur du générateur, composition type d'après Beralmar (Notes sur le séchage, § 1.12.3, normes EN 676 et EN 298)
BER = "Composition type Beralmar § 1.12.3 (EN 676 / EN 298) : repère, marque et réglage à relever sur la rampe réelle."
for n, (t, nom, cr) in enumerate([
        ('VAN', 'vanne de sectionnement à sphère (coupure manuelle)', 'A'), ('FIL', 'filtre à gaz', 'B'), ('MANO', 'manomètre à cadran', 'B'),
        ('REG', 'régulateur de pression avec sécurité de surpression (OPSO)', 'A'), ('DECH', 'vanne de décharge', 'B'),
        ('EV', 'électrovanne double de sécurité classe A, normalement fermée', 'A'), ('VREG', 'vanne de régulation (modulation du débit)', 'A'),
        ('CTF', 'contrôle d\'étanchéité des électrovannes', 'A'), ('PRG', 'pressostats gaz mini / maxi', 'A'),
        ('VCOMB', 'ventilateur d\'air de combustion', 'A'), ('ALL', 'électrode et transformateur d\'allumage', 'B'),
        ('FLAM', 'détection de flamme (cellule UV ou sonde d\'ionisation) et relais de flamme', 'A'),
    ], 1):
    ajout(tag=f'DPR-SEC-GEN-{t}', nom=f'Générateur du séchoir — {nom}', type='organe', parent='DPR-SEC-GEN', repere=str(n), criticite=cr, notes=BER)
offre('401', tag='DPR-SEC-VCEN', nom='Ventilateur centrifuge principal du séchoir', type='moteur', parent='DPR-SEC', puissance_kw='110', criticite='A', notes='240 000 m³/h, 500 Pa, 150 °C.')
offre('402', tag='DPR-SEC-VEXT', nom='Ventilateurs axiaux d\'extraction de l\'air humide (2 + 2)', type='moteur', parent='DPR-SEC', puissance_kw='74', notes='4 × 18,5 kW, 60 000 m³/h chacun.')
offre('403', tag='DPR-SEC-VREC', nom='Ventilateurs de recirculation interne (11 + 11)', type='moteur', parent='DPR-SEC', puissance_kw='121', notes='22 × 5,5 kW, 60 000 m³/h chacun.')
offre('405-409', tag='DPR-SEC-VAN', nom='Vannes motorisées d\'air du séchoir (récupération, expulsion, air chaud, air humide)', type='organe', parent='DPR-SEC')
offre('410-412', tag='DPR-SEC-CAPT', nom='Capteurs du séchoir (5 pression, 22 température/humidité, 2 PT100)', type='organe', parent='DPR-SEC', criticite='A')
offre('413', tag='DPR-ARM-SEC', nom='Armoire électrique du séchoir', type='armoire', parent='DPR-ELEC', criticite='A')
# empileuse (REF. 500 à 504)
offre('500-501', tag='DPR-CND-EMP', nom='Tapis de poumon et ligne de programmation de l\'empileuse', type='machine', parent='DPR-CND')
offre('502', tag='DPR-CND-ROB1', nom='Robot Fanuc de chargement du wagon four (empileuse)', type='machine', parent='DPR-CND', criticite='A', fabricant='Fanuc',
      notes='Cellule robot : porte de cellule à verrouillage, jamais d\'entrée robot sous tension.')
# four tunnel (REF. 551 à 616)
offre('551', tag='DPR-FOU-VFUM', nom='Ventilateur d\'aspiration des fumées', type='moteur', parent='DPR-FOU', puissance_kw='37', criticite='A', notes='80 000 m³/h, variateur.')
offre('552', tag='DPR-FOU-VRCH', nom='Ventilateurs de recyclage chaud (2)', type='moteur', parent='DPR-FOU', puissance_kw='22', notes='2 × 11 kW, 300 °C.')
offre('553', tag='DPR-FOU-VREF', nom='Ventilateurs de refroidissement rapide (2)', type='moteur', parent='DPR-FOU', puissance_kw='11', notes='2 × 5,5 kW, 6 lances par côté.')
offre('554', tag='DPR-FOU-VREC', nom='Ventilateur de récupération haute / basse température', type='moteur', parent='DPR-FOU', puissance_kw='22.5', criticite='A', notes='60 000 m³/h vers le séchoir.')
offre('555-556', tag='DPR-FOU-VCP', nom='Ventilateurs de contre-pression (porte et voûte, 3 + 3)', type='moteur', parent='DPR-FOU', puissance_kw='11.1', notes='3 × 1,5 kW + 3 × 2,2 kW.')
offre('557-558', tag='DPR-FOU-BRU', nom='Brûleurs en voûte (10 groupes, 110 points de feu)', type='machine', parent='DPR-FOU', criticite='A',
      notes='Groupe « Jolly » + 9 groupes de 11 points ; gaz naturel, 1 100 °C max, 340 kcal/kg.')
ajout(tag='DPR-FOU-GAZ', nom='Panoplie gaz du four et poste de détente', type='organe', parent='DPR-FOU', criticite='A', notes=OFFRE.format('panoplie gaz') + ' Arrivée 0,3 bar, ≈ 890 m³/h séchoir + four.')
offre('600-614', tag='DPR-FOU-MAN', nom='Manutention des wagons four (pousseurs hydrauliques, transbordeurs, treuils, portes)', type='machine', parent='DPR-FOU', criticite='A',
      notes='60 wagons, 26 + 1 dans le four ; portes de pré-four, d\'entrée et de sortie.')
offre('615-616', tag='DPR-ARM-FOU', nom='Armoires électriques du four et de la manutention', type='armoire', parent='DPR-ELEC', criticite='A')
# déchargement et emballage (REF. 700 à 710)
offre('700/703', tag='DPR-CND-ROB2', nom='Robots Fanuc de déchargement du cuit (2)', type='machine', parent='DPR-CND', criticite='A', fabricant='Fanuc',
      notes='Cellule robot : porte de cellule à verrouillage, jamais d\'entrée robot sous tension.')
offre('702/705', tag='DPR-CND-CER-02', nom='Cercleuses horizontale et verticale de la ligne de paquets', type='machine', parent='DPR-CND')
offre('704/706/707', tag='DPR-CND-TRP', nom='Transporteurs pas-à-pas et à chaînes des paquets finis', type='machine', parent='DPR-CND')
offre('708', tag='DPR-CND-NETW', nom='Nettoyeur des wagons four (ventilateur et filtre)', type='machine', parent='DPR-CND')
offre('710', tag='DPR-ARM-CND', nom='Armoire électrique du déchargement', type='armoire', parent='DPR-ELEC', criticite='A')
# moyens de protection du site (plan 86S-13-027E et analyse des risques)
for t, n, cr, nt in (
    ('DPR-HSE-EXT', 'Extincteurs et RIA du site', 'A', 'Poste de garde, administration, armoires électriques, four, gaz, atelier, dépôt d\'huile.'),
    ('DPR-HSE-DGAZ', 'Détection gaz et vannes de coupure (séchoir, four, poste de détente)', 'A', 'Coupure générale du gaz repérée et accessible.'),
    ('DPR-HSE-AU', 'Arrêts d\'urgence et arrêts à câble de la ligne', 'A', 'Coups de poing et câbles des tapis, navette, robots.'),
    ('DPR-HSE-CEL', 'Clôtures et portes verrouillées des cellules robots', 'A', 'Empileuse et déchargement.'),
    ('DPR-HSE-FOSSE', 'Garde-corps de la fosse à argile (−3,000 m) et passerelles des tapis', 'A', 'Passerelles latérales du tapis REF. 120.'),
    ('DPR-HSE-CIRC', 'Circulation des camions et chariots (sens unique, marquage, miroirs)', 'B', 'Boucle de circulation du plan 86S-13-027E.'),
    ('DPR-HSE-EPI', 'Stock d\'équipements de protection individuelle', 'A', 'Casques, chaussures, gants, lunettes, bouchons, masques FFP2/FFP3, vêtements anti-chaleur.'),
):
    ajout(tag=t, nom=n, type='machine', parent='DPR-HSE', criticite=cr, notes=nt)

# ------------------------------------------------------------------ air comprimé (schéma Marcheluzzo C1361AS700 005 rév. A du 21/12/2015)
AIR = 'Schéma C1361AS700 005 « Impianto aria schematico » (indicatif : à vérifier sur site).'
ajout(tag='DPR-UTL', nom='Utilités (air comprimé, eau, gaz)', type='atelier', parent='DPR-SITE', criticite='A', notes='Réseaux qui alimentent toute la ligne.')
ajout(tag='DPR-UTL-COMP', nom="Salle des compresseurs (2 compresseurs 7,5 bar, réservoirs)", type='machine', parent='DPR-UTL', criticite='A',
      notes=AIR + ' Offre 2015-063 : 2 compresseurs à 7,5 bar, un en marche et un en secours ou en alternance. Réservoirs = appareils à pression : contrôle réglementaire.')
ajout(tag='DPR-UTL-AIR', nom='Réseau d\'air comprimé en tube 2"1/2 (boucle de la halle)', type='machine', parent='DPR-UTL', criticite='A',
      notes=AIR + ' Départ de la salle des compresseurs, collecteur le long de la halle, 13 points de raccordement A1 à A13.')
for n, (zone, desservi) in enumerate([
        ('ligne des paquets', 'cercleuses et transporteurs des paquets finis'), ('déchargement du cuit', 'robots de déchargement'),
        ('ligne de déchargeuse', 'déchargement du séchoir'), ('étireuse', 'coupe et vérin d\'escamotage du tapis'),
        ("malaxeur d'argile", 'mélangeurs'), ('ligne de chargement', 'chargement des wagons séchoir'),
        ('four', 'vannes pneumatiques et refroidissement rapide'), ('ligne de déchargement', 'déchargement des wagons'),
        ('empileuse', 'pince et pousseur pneumatique'), ('laminoir (1er point)', 'laminoir'),
        ('caissons doseurs', 'caissons doseurs'), ('laminoir (2e point)', 'laminoir'), ('filtre à poussière', 'décolmatage des manches'),
    ], 1):
    ajout(tag=f'DPR-AIR-A{n}', nom=f'Point de raccordement air A{n} — {zone}', type='organe', parent='DPR-UTL-AIR', repere=f'A{n}', criticite='B',
          notes=f'Alimente : {desservi}. Vanne d\'isolement, filtre-régulateur et purge à relever sur place.')

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
plan('PM Hebdomadaire — Cadre et lame de chargement', 'DPR-FAC-CADRE', 7, 30, ["Lame de chargement : déformation, frottement sur les étages du wagon", "Ressorts pneumatiques (torpress) : pression d'air, fuites", "Roues, guides et chaîne / réducteur du wagon de translation : jeu, graissage", "Fins de course et détecteurs de position de la lame", "Variateur INM212 : historique des alarmes relevé, ventilation propre"])
# séchoir, four, robots et moyens de protection (offre 2015-063)
GAZ = "Gaz : permis de feu, détecteur de gaz portable, jamais de flamme nue près de la rampe ; EPI anti-chaleur, gants, lunettes ; consignation électrique."
ROBOT = "Robot à l'arrêt et consigné (coffret cadenassé), porte de cellule ouverte ; jamais d'entrée en cellule en mode automatique."
LEVAGE = "Zone balisée sous la charge ; élingues et crochets contrôlés ; personne sous la charge ; casque et chaussures."
plan('PM Quotidien — Générateur de chaleur du séchoir', 'DPR-SEC-GEN', 1, 15, ["Flamme stable, pas d'alarme sur l'armoire", "Pression gaz rampe — noter (mbar)", "Température de soufflage — noter (°C, 160 max)", "Odeur de gaz : contrôle au détecteur portable"], GAZ)
plan('PM Mensuel — Brûleur et rampe gaz du séchoir', 'DPR-SEC-GEN', 30, 60, ["Filtre à gaz : perte de charge (manomètres amont / aval), cartouche à changer si encrassée", "Pression après régulateur — noter (mbar)", "Électrode d'allumage : écartement, propreté", "Cellule UV / sonde d'ionisation : nettoyer la lentille, signal de flamme — noter", "Essai d'arrêt pour défaillance de flamme : la remise en marche doit exiger un réarmement manuel", "Noter chaque mise en sécurité du mois et sa cause"], GAZ)
plan('PM Annuel — Rampe gaz et générateur du séchoir', 'DPR-SEC-GEN', 365, 240, ["Étanchéité de la rampe à l'eau savonneuse / détecteur", "Électrovannes de sécurité : essai de fermeture et du contrôle d'étanchéité", "Régulateur : essai du blocage de surpression (OPSO)", "Pressostats gaz et air : essai de déclenchement", "Électrode et cellule de flamme : nettoyage"], GAZ)
plan('PM Hebdomadaire — Ventilateurs du séchoir', 'DPR-SEC-VCEN', 7, 60, ["Ventilateur principal 110 kW : vibrations, température paliers", "Courroies et carter de protection en place", "22 ventilateurs de recirculation : bruit, arrêt d'un ventilateur", "4 extracteurs d'air humide : état"])
plan('PM Mensuel — Capteurs du séchoir', 'DPR-SEC-CAPT', 30, 60, ["Comparer les 22 sondes température/humidité à un appareil de référence", "Nettoyer les sondes encrassées", "Capteurs de pression : prises non bouchées"])
plan('Mesure Mensuelle — Rendement du séchoir', 'DPR-SEC-CAPT', 30, 30, ["Température et humidité de l'air ambiant à la prise d'air (appareil vérifié)", "Température et humidité de l'air à l'évacuation des extracteurs, au point de sortie", "Tonnage sec du jour et humidité des briques à l'entrée et à la sortie (pesée / étuve)", "Saisir dans le classeur « Consommation du séchoir » : kcal / kg d'eau (repère : 920 à l'offre, ≈ 835 relevé du 10/10/2026)", "Si l'air sort sous 60 % d'humidité : revoir le réglage extraction / recirculation"], "Sans risque particulier ; casque et chaussures dans la halle.")
plan('PM Hebdomadaire — Pousseurs et transbordeurs du séchoir', 'DPR-SEC-POUS', 7, 60, ["Fins de course et centrage des transbordeurs", "Chaînes des recherches : tension, graissage", "Portes : étanchéité et mécanisme", "Rails et roues des wagons séchoir"])
plan('PM Quotidien — Four (tournée)', 'DPR-FOU-BRU', 1, 30, ["Courbe de cuisson : températures des zones conformes (1 100 °C max)", "Points de feu : flamme présente sur chaque groupe", "Pression du four (contre-pression porte / voûte)", "Fuite gaz : contrôle au détecteur portable sur la panoplie"], GAZ)
plan('PM Mensuel — Brûleurs en voûte', 'DPR-FOU-BRU', 30, 240, ["Nettoyer les injecteurs des 110 points de feu", "Joints à siège conique : état", "Flexibles gaz et air : fissures, date de péremption", "Robinets gradués : manœuvre"], GAZ)
plan('PM Hebdomadaire — Ventilateurs du four', 'DPR-FOU-VFUM', 7, 60, ["Ventilateur des fumées (37 kW) : vibrations, paliers", "Recyclage chaud et récupération : courroies, carters", "Refroidissement rapide : lances et vannes pneumatiques", "Variateurs : codes défauts relevés"])
plan('PM Mensuel — Wagons four et manutention', 'DPR-FOU-MAN', 30, 240, ["Pousseurs hydrauliques : niveau d'huile, fuites, flexibles", "Portes de pré-four, entrée, sortie : étanchéité", "Wagons : roues, réfractaire, joints de sable (relever les wagons à réparer)", "Treuils et câbles : usure"])
plan('PM Hebdomadaire — Robots Fanuc', 'DPR-CND-ROB2', 7, 45, ["Pince mécanique : usure des doigts, vérins", "Câbles et faisceau du robot", "Batterie de sauvegarde du contrôleur (alarme)", "Essai des portes de cellule et des arrêts d'urgence"], ROBOT)
plan('PM Semestriel — Robots Fanuc (graissage)', 'DPR-CND-ROB1', 182, 180, ["Graissage des axes selon le manuel Fanuc", "Remplacer les batteries du codeur si échéance", "Sauvegarde des programmes du robot"], ROBOT)
plan('PM Mensuel — Aspiration des poussières', 'DPR-HSE-ASP', 30, 60, ["Manches / filtres : colmatage, déchirure", "Dépression aux hottes du laminoir", "Vider la trémie à poussière (masque FFP3)", "Ventilateur : vibrations"])
plan('PM Hebdomadaire — Détecteur de métaux et aimant', 'DPR-PRE-DMET', 7, 15, ["Essai avec la pièce test : arrêt du tapis", "Nettoyer l'aimant permanent", "Noter les objets métalliques retirés"])
plan('Contrôle Annuel — Pont roulant 7 t (organisme agréé)', 'DPR-HSE-PONT', 365, 240, ["Visite par organisme agréé et rapport classé", "Câble, crochet et linguet", "Freins de levage et de translation", "Fins de course haut / bas", "Boîte à boutons et arrêt d'urgence"], LEVAGE)
plan('PM Mensuel — Pont roulant et grue', 'DPR-HSE-PONT', 30, 30, ["Essai à vide des mouvements et des fins de course", "Câble : fils cassés, écrasement", "Crochet et linguet", "Élingues : étiquette de charge, état"], LEVAGE)
plan('Contrôle Mensuel — Extincteurs et RIA', 'DPR-HSE-EXT', 30, 60, ["Chaque extincteur à sa place, accessible, plombé", "Manomètre dans le vert", "Date de la vérification annuelle", "RIA : dévidoir et vanne"], "Sans risque particulier.")
plan('Essai Mensuel — Détection gaz et coupure', 'DPR-HSE-DGAZ', 30, 30, ["Essai des détecteurs fixes (gaz étalon)", "Essai de la vanne de coupure générale", "Signalisation de la coupure lisible"], GAZ)
plan('Essai Hebdomadaire — Arrêts d\'urgence', 'DPR-HSE-AU', 7, 30, ["Essai d'un arrêt à câble par tapis (rotation)", "Essai des coups de poing de la ligne", "Réarmement : la machine ne redémarre pas seule"])
plan('Contrôle Mensuel — Garde-corps, passerelles et circulation', 'DPR-HSE-FOSSE', 30, 30, ["Garde-corps de la fosse à argile : complets et fixés", "Passerelles des tapis : caillebotis, plinthes", "Marquage au sol et miroirs de la boucle camions", "Éclairage des zones de passage"], "Harnais si travail près du vide.")
plan('Contrôle Mensuel — Stock d\'EPI', 'DPR-HSE-EPI', 30, 30, ["Compter les EPI en stock (casques, chaussures, gants, lunettes, bouchons, masques FFP2/FFP3)", "Remplacer les EPI usés signalés par les chefs d'équipe", "Bouchons et masques disponibles à l'entrée des ateliers"], "Sans risque particulier.")
AIRSEC = "Isoler et purger la partie du réseau avant d'ouvrir un raccord ; jamais d'air comprimé dirigé vers une personne ; lunettes et bouchons d'oreilles en salle des compresseurs."
plan('PM Quotidien — Compresseurs', 'DPR-UTL-COMP', 1, 15, ["Pression du réseau — noter (bar, 7,5 attendu)", "Heures de marche des 2 compresseurs — noter", "Purger les condensats des réservoirs (si purge manuelle)", "Alarmes du compresseur et du sécheur"], AIRSEC)
plan('PM Mensuel — Compresseurs', 'DPR-UTL-COMP', 30, 60, ["Filtre à air d'aspiration : nettoyer", "Niveau d'huile", "Purgeurs automatiques : essai", "Alterner le compresseur en marche et celui en secours", "Température de refoulement"], AIRSEC)
plan('PM Annuel — Réservoirs d\'air (appareils à pression)', 'DPR-UTL-COMP', 365, 120, ["Soupapes de sûreté : essai et plombage", "Inspection visuelle intérieure / extérieure (corrosion)", "Manomètres : étalonnage", "Classer le rapport de contrôle réglementaire"], AIRSEC)
plan('PM Mensuel — Réseau d\'air et points A1 à A13', 'DPR-UTL-AIR', 30, 90, ["Recherche de fuites sur la boucle 2\"1/2 et aux 13 points (machine en marche, à l'écoute ou à l'eau savonneuse)", "Filtres-régulateurs des points : cartouches, purge", "Pression à chaque point — noter les points sous 6 bar", "Flexibles et raccords rapides : état"], AIRSEC)
# repères du traité Beralmar (séchage ch. 1.7-1.16, cuisson ch. 2.4-2.15) : mesures périodiques
plan('Mesure Trimestrielle — Vitesse et répartition de l\'air du séchoir', 'DPR-SEC-VREC', 91, 120, ["Anémomètre : vitesse de l'air entre les wagons, en haut et en bas, dans chaque secteur — repère 8 à 12 m/s, au moins 7 m/s", "Écart de température haut / bas de charge — repère 3 à 4 °C", "Pression dans la gaine de soufflage et dans le tunnel : légère surpression", "Portes et contre-portes d'entrée : joints, entrées d'air parasite"], "Ventilateurs en marche : rester sur les passerelles, ne pas entrer dans le tunnel.")
plan('Mesure Mensuelle — Analyse des fumées du four', 'DPR-FOU-BRU', 30, 60, ["Analyseur : O₂, CO₂, CO près de la zone de feu et à la cheminée", "Excès d'air λ = 1 + 0,8979 × O₂ / (21 − O₂) — noter", "O₂ anormalement haut : chercher une fuite (rigoles, joints, portes) ; O₂ et CO hauts ensemble : répartition du gaz entre les points de feu", "Température des fumées au ventilateur de tirage : au-dessus de 60 °C (point de rosée)"], GAZ)
plan('PM Mensuel — Étanchéité du four (air parasite)', 'DPR-FOU-MAN', 30, 120, ["Rigoles à sable : niveau, sable non calcaire 1 à 7 mm, circulation du sable", "Joints entre wagons (fibre céramique) et tôles sous châssis", "Portes d'entrée et de sortie : étanchéité", "Pression de la fosse et du canal : équilibrées ; point neutre après le dernier groupe de feu"], GAZ)
plan('Essai Trimestriel — Arrêt du tirage coupe les brûleurs', 'DPR-FOU-VFUM', 91, 30, ["Avec le chef de four, simuler l'arrêt du ventilateur de tirage", "Vérifier la coupure de tous les groupes de brûleurs et l'alarme", "Noter le résultat et le temps de réaction"], GAZ)
plan('Mesure Semestrielle — Bilan thermique du four', 'DPR-FOU-VFUM', 182, 240, ["Débit et température du tirage et de la récupération (tube de Pitot, thermocouple)", "Températures haut / bas de charge au préchauffage (wagon instrumenté)", "Peser un wagon vide (revêtement + châssis) — repère : revêtement ≤ 0,5 kg par kg de produit", "Consommation de gaz du four seul — repère offre 340 kcal/kg cuit"], GAZ)
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
    ['Électrode d\'allumage du brûleur du séchoir', 'à relever', 'DPR-SEC-GEN-ALL', 'pièce', 0, 1],
    ['Cellule UV / sonde d\'ionisation du brûleur', 'à relever', 'DPR-SEC-GEN-FLAM', 'pièce', 0, 1],
    ['Cartouche du filtre à gaz', 'à relever', 'DPR-SEC-GEN-FIL', 'pièce', 0, 1],
    ['Transformateur d\'allumage', 'à relever', 'DPR-SEC-GEN-ALL', 'pièce', 0, 1],
    ['Courroies du ventilateur principal du séchoir', 'réf. sur courroie', 'DPR-SEC-VCEN', 'jeu', 0, 1],
    ['Moteur 5,5 kW de ventilateur de recirculation', 'à relever', 'DPR-SEC-VREC', 'pièce', 0, 1],
    ['Sonde température / humidité du séchoir', 'à relever', 'DPR-SEC-CAPT', 'pièce', 0, 2],
    ['Sonde PT100', '', 'DPR-SEC-CAPT', 'pièce', 0, 1],
    ['Injecteur de brûleur en voûte', 'à relever', 'DPR-FOU-BRU', 'pièce', 0, 5],
    ['Flexible gaz de brûleur', 'à relever', 'DPR-FOU-BRU', 'pièce', 0, 5],
    ['Flexible air de brûleur', 'à relever', 'DPR-FOU-BRU', 'pièce', 0, 5],
    ['Joint à siège conique de brûleur', '', 'DPR-FOU-BRU', 'pièce', 0, 10],
    ['Courroies du ventilateur des fumées', 'réf. sur courroie', 'DPR-FOU-VFUM', 'jeu', 0, 1],
    ['Briques réfractaires de wagon four', 'à relever', 'DPR-FOU-MAN', 'pièce', 0, 0],
    ['Roue de wagon four', 'à relever', 'DPR-FOU-MAN', 'pièce', 0, 4],
    ['Huile hydraulique des pousseurs', 'HM 46 (à confirmer)', 'DPR-FOU-MAN', 'litre', 0, 0],
    ['Batteries du contrôleur Fanuc', 'à relever', 'DPR-CND-ROB2', 'jeu', 0, 1],
    ['Doigts de pince de robot', 'à relever', 'DPR-CND-ROB2', 'jeu', 0, 1],
    ['Manches filtrantes de l\'aspiration des poussières', 'à relever', 'DPR-HSE-ASP', 'pièce', 0, 0],
    ['Pièce test du détecteur de métaux', '', 'DPR-PRE-DMET', 'pièce', 0, 1],
    ['Élingue textile avec étiquette de charge', '', 'DPR-HSE-PONT', 'pièce', 0, 2],
    ['Extincteur poudre 6 kg (rechange)', '', 'DPR-HSE-EXT', 'pièce', 0, 2],
    ['Extincteur CO₂ 5 kg (armoires électriques)', '', 'DPR-HSE-EXT', 'pièce', 0, 1],
    ['Bouchons d\'oreilles', 'SNR ≥ 30 dB', 'DPR-HSE-EPI', 'paire', 0, 200],
    ['Masque FFP3 (poussière de silice)', 'EN 149', 'DPR-HSE-EPI', 'pièce', 0, 100],
    ['Gants anti-chaleur', 'EN 407', 'DPR-HSE-EPI', 'paire', 0, 10],
    ['Lunettes de protection', 'EN 166', 'DPR-HSE-EPI', 'pièce', 0, 20],
    ['Casque de chantier', 'EN 397', 'DPR-HSE-EPI', 'pièce', 0, 10],
    ['Gilet haute visibilité', 'EN ISO 20471', 'DPR-HSE-EPI', 'pièce', 0, 10],
    ['Filtre à air de compresseur', 'à relever', 'DPR-UTL-COMP', 'pièce', 0, 2],
    ['Filtre à huile de compresseur', 'à relever', 'DPR-UTL-COMP', 'pièce', 0, 2],
    ['Séparateur air/huile', 'à relever', 'DPR-UTL-COMP', 'pièce', 0, 1],
    ['Huile de compresseur', 'selon constructeur', 'DPR-UTL-COMP', 'litre', 0, 0],
    ['Purgeur automatique de condensats', 'à relever', 'DPR-UTL-COMP', 'pièce', 0, 1],
    ['Cartouche de filtre de ligne (filtre-régulateur)', 'à relever', 'DPR-UTL-AIR', 'pièce', 0, 4],
    ['Raccord rapide air comprimé', 'à relever', 'DPR-UTL-AIR', 'pièce', 0, 10],
    ['Flexible air comprimé', 'à relever', 'DPR-UTL-AIR', 'mètre', 0, 20],
]
with open(SCH / 'MS500_pieces.csv', encoding='utf-8') as f:
    for r in csv.DictReader(f, delimiter=';'):
        PI.append([r['nom'], r['reference'], 'DPR-CND-CER-01-TETE', r['unite'], 0, r['seuil']])
for p in PI:
    assert p[2] in tags, p

# ------------------------------------------------------------------ alarmes automate relevées sur les écrans
AL = [
    ['INM212-E.THT', "Variateur INM212 lame de chargement : E.THT surcharge du variateur (relais thermique électronique)", 'DPR-FAC-CADRE-INM212',
     "Le courant de sortie est resté au-dessus du courant nominal du variateur assez longtemps pour faire chauffer ses transistors (tenue : 150 % pendant 60 s). Causes : lame qui force (étage mal positionné, brique coincée, lame tordue, guides ou chaîne durs, frein qui ne se libère pas) ; "
     "torpress sans air ; temps d'accélération ou de décélération trop courts ; boost de couple mal réglé ; variateur encrassé ou ventilateur arrêté, armoire trop chaude ; variateur trop petit pour la charge.",
     "Ne pas réarmer à répétition : laisser refroidir quelques minutes. Consigner et faire tourner la lame à la main : elle doit aller librement. Nettoyer le variateur (aspiration, pas d'air comprimé sur les cartes), vérifier son ventilateur et la température de l'armoire. "
     "Mesurer le courant moteur en marche (moniteur du variateur) et le comparer à la plaque. Si la mécanique est libre et le courant reste haut : allonger les temps d'accélération / décélération (Pr.7, Pr.8) et revoir le boost de couple (Pr.0) avec l'automaticien, après avoir noté les valeurs d'origine."],
    ['INM212', "ALARME VARIATEUR INM212 LAME DE CHARGEMENT", 'DPR-FAC-CADRE-INM212',
     "Défaut du variateur qui entraîne la lame de chargement : 1) lame bloquée ou freinée (étage du wagon mal positionné par l'ascenseur, brique ou bavure coincée, lame tordue) → surcharge ou surintensité ; "
     "2) ressorts pneumatiques (torpress) sans air : la tête du cadre ne se soulève pas ; 3) moteur ou câble : isolement, phase coupée ; 4) frein du motoréducteur qui ne se libère pas ; "
     "5) liaison Profibus avec l'automate coupée ; 6) variateur trop chaud (ventilation, poussière) ; 7) tension réseau basse ou coupure.",
     "Lire et noter le code affiché sur le variateur (ex. E.OC, E.THM, E.OLT, E.OP) avant de réarmer. Consigner, vérifier que la lame et le wagon sont libres et alignés, pression d'air des torpress, "
     "frein et moteur (isolement), ventilation du variateur. Réarmer, essai en manuel à vitesse réduite. Si l'alarme revient : électricien, relever l'historique des défauts du variateur, ouvrir une intervention."],
]
SORTIE.mkdir(parents=True, exist_ok=True)
def ecrire(nom, entetes, rows):
    with open(SORTIE / nom, 'w', newline='', encoding='utf-8') as f:
        w = csv.writer(f, delimiter=';'); w.writerow(entetes); w.writerows(rows)
ecrire('equipements_preparation.csv', ENT, [[l[c] for c in ENT] for l in L])
ecrire('plans_preparation.csv', ['nom', 'tag', 'frequence_j', 'duree_min', 'checklist', 'securite'], P)
ecrire('pieces_preparation.csv', ['nom', 'reference', 'tag', 'unite', 'stock', 'seuil_conseille'], PI)
for a in AL:
    assert a[2] in tags, a
ecrire('alarmes_preparation.csv', ['code', 'texte', 'tag', 'causes', 'remede'], AL)
mot = [l for l in L if l['type'] == 'moteur']
kw = sum(float(l['puissance_kw'] or 0) for l in mot)
print(f"{len(L)} équipements ({sum(l['type'] == 'machine' for l in L)} machines, {len(mot)} moteurs = {kw:.1f} kW, "
      f"{sum(l['type'] == 'organe' for l in L)} organes), {len(P)} plans, {len(PI)} pièces → {SORTIE}")
