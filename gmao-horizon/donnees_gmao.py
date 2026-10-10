#!/usr/bin/env python3
"""Exporte la liste type de l'atelier de préparation (construire_dossier.py) en CSV pour le module
Maintenance de l'ERP : erp-dolibarr/modules/gmao/donnees/*.csv (chargés au premier démarrage)."""
import csv, os, runpy, tempfile
from pathlib import Path
ICI = Path(__file__).resolve().parent
SORTIE = ICI.parent / 'erp-dolibarr' / 'modules' / 'gmao' / 'donnees'
cwd = os.getcwd(); os.chdir(tempfile.mkdtemp())            # le classeur produit au passage est jeté
g = runpy.run_path(str(ICI / 'construire_dossier.py')); os.chdir(cwd)
EMP, M, P, PI = g['EMP'], g['M'], g['P'], g['PI']

tag_emp = {nom: 'DPR-' + code for code, nom, *_ in EMP}
typ = {'Site': 'site', 'Bâtiment / atelier': 'atelier', 'Zone': 'zone', 'Local technique': 'zone'}
tag_nom = {}
lignes = []
for code, nom, t, parent, desc in EMP:
    lignes.append(['DPR-' + code, nom, typ.get(t, 'zone'), tag_emp.get(parent, ''), '', '', '', '', '', 'B', '', '', desc])
for tag, nom, cat, emp, parent, fab, mod, ns, crit, desc in M:
    tag_nom[nom] = tag
    if parent:
        t = 'moteur' if cat == 'Moteur électrique' else 'organe'
        lignes.append([tag, nom, t, tag_nom[parent], '', '', '', '', '', crit, '', '', desc])
    else:
        t = 'armoire' if cat == 'Électricité' else 'machine'
        lignes.append([tag, nom, t, tag_emp[emp], 'ARM-PRE' if t != 'armoire' else '', '', '', '', '', crit, '', '', f'{cat} — {desc}'])
def tag_de(nom):  # nom exact, sinon machine dont le nom commence par celui-ci (« Convoyeur à bande n°1 (… → …) »)
    if nom in tag_nom: return tag_nom[nom]
    for n, t in tag_nom.items():
        if n.startswith(nom + ' ('): return t
    raise KeyError(nom)
SORTIE.mkdir(parents=True, exist_ok=True)
def ecrire(nom, entetes, rows):
    with open(SORTIE / nom, 'w', newline='', encoding='utf-8') as f:
        w = csv.writer(f, delimiter=';'); w.writerow(entetes); w.writerows(rows)
ecrire('equipements_preparation.csv', ['tag', 'nom', 'type', 'parent', 'armoire', 'repere', 'puissance_kw', 'vitesse', 'folio', 'criticite', 'fabricant', 'modele', 'notes'], lignes)
freq = {'Quotidien': 1, 'Hebdomadaire': 7, 'Mensuel': 30, 'Tous les 3 mois': 91, 'Tous les 6 mois': 182}
ecrire('plans_preparation.csv', ['nom', 'tag', 'frequence_j', 'duree_min', 'checklist', 'securite'],
       [[nom, tag_de(eq), freq[f], {'15 min': 15, '20 min': 20, '45 min': 45, '1 h': 60, '2 h': 120, '3 h': 180, '1 jour': 480}.get(d, ''), chk, sec]
        for nom, _, f, eq, d, chk, sec, _ in P])
ecrire('pieces_preparation.csv', ['nom', 'reference', 'tag', 'unite', 'seuil_conseille'],
       [[n, '' if r.startswith('à compléter') else r, tag_de(e), u, s] for n, r, e, u, _, _, s in PI])
print(len(lignes), 'équipements,', len(P), 'plans,', len(PI), 'pièces →', SORTIE)
