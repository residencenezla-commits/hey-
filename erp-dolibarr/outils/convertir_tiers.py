#!/usr/bin/env python3
"""Convertit la liste Excel « Clients / Fournisseurs selon le plan comptable » de
SARL DPR AXXAM en fichier CSV importable par Dolibarr (init/20-tiers.php).

Usage :
    python3 outils/convertir_tiers.py Liste_Clients_Fournisseurs_Plan_Comptable_2026_DPR_AXXAM.xlsx

Règles :
- un tiers Dolibarr par code tiers PC Compta (C005, FL012, FS004…), pour garder
  la correspondance exacte avec la comptabilité ;
- un code présent dans plusieurs comptes (ex. 41100 et 41901) devient un seul
  tiers, avec tous ses comptes ;
- comptes 41x = client, comptes 40x = fournisseur ;
- les lignes qui ne sont pas de vrais tiers (soldes reportés, regroupements)
  sont écartées et listées à l'écran.
"""
import csv
import sys
from pathlib import Path

import openpyxl

FEUILLES = ("FOURNISSEURS_40", "CLIENTS_41")
PAS_DES_TIERS = ("SOLDE SONELGAZ 2021", "AVANCE CLIENTS 2014 -2019", "CONCESSIONS")
SORTIE = Path(__file__).resolve().parent.parent / "init" / "tiers-dpr-axxam.csv"
COLONNES = ["code", "nom", "client", "fournisseur", "comptes", "compte_client", "compte_fournisseur",
            "adresse", "ville", "nif", "rc", "ai", "etranger", "soldes"]


def texte(v):
    return "" if v is None else " ".join(str(v).split())


def montant(v):
    return f"{float(v or 0):,.2f}".replace(",", " ").replace(".", ",")


def main(fichier):
    wb = openpyxl.load_workbook(fichier, read_only=True, data_only=True)
    tiers, ecartes = {}, []
    for feuille in FEUILLES:
        for i, r in enumerate(wb[feuille].iter_rows(values_only=True)):
            if i == 0 or not r[2]:
                continue
            compte, intitule, code, nom = texte(r[0]), texte(r[1]), texte(r[2]), texte(r[3])
            if nom.upper() in PAS_DES_TIERS:
                ecartes.append(f"{code} {nom} ({compte})")
                continue
            t = tiers.setdefault(code, {c: "" for c in COLONNES} | {"code": code, "nom": nom, "client": 0,
                                                                     "fournisseur": 0, "_comptes": [], "_soldes": []})
            for champ, val in (("adresse", r[4]), ("ville", r[5]), ("nif", r[6]), ("rc", r[7]), ("ai", r[8])):
                if not t[champ] and texte(val):
                    t[champ] = texte(val)
            if compte.startswith("41"):
                t["client"] = 1
                t["compte_client"] = t["compte_client"] or compte
            else:
                t["fournisseur"] = 1
                t["compte_fournisseur"] = t["compte_fournisseur"] or compte
            if compte == "40100":
                t["etranger"] = 1
            t["_comptes"].append(compte)
            t["_soldes"].append(f"{compte} {intitule} : {montant(r[12])} DA ({texte(r[13]).lower()})")

    with open(SORTIE, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=COLONNES, delimiter=";")
        w.writeheader()
        for t in sorted(tiers.values(), key=lambda x: x["code"]):
            t["comptes"] = ",".join(dict.fromkeys(t["_comptes"]))
            t["soldes"] = " | ".join(t["_soldes"])
            t["etranger"] = t["etranger"] or 0
            w.writerow({c: t[c] for c in COLONNES})

    print(f"{len(tiers)} tiers écrits dans {SORTIE}")
    print(f"  clients : {sum(t['client'] for t in tiers.values())}, "
          f"fournisseurs : {sum(t['fournisseur'] for t in tiers.values())}, "
          f"les deux : {sum(1 for t in tiers.values() if t['client'] and t['fournisseur'])}")
    if ecartes:
        print("Lignes écartées (pas des tiers) : " + ", ".join(ecartes))


if __name__ == "__main__":
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    main(sys.argv[1])
