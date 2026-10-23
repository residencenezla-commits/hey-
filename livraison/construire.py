#!/usr/bin/env python3
"""Construit les paquets de livraison DPR AXXAM dans livraison/dist/ :

  DPR-AXXAM_site-web.zip              site Internet prêt à déposer chez un hébergeur
  DPR-AXXAM_ERP-Dolibarr.zip          ERP paramétré (sans mots de passe ni données)
  DPR-AXXAM_videos-publicitaires.zip  vidéos 9:16 et 16:9
  DPR-AXXAM_Guide.pdf                 guide de livraison
  DPR-AXXAM_livraison-complete.zip    les quatre éléments ci-dessus

Usage : python3 livraison/construire.py
(le guide PDF nécessite Playwright et Chromium)
"""
import asyncio
import os
import shutil
import zipfile
from pathlib import Path

RACINE = Path(__file__).resolve().parent.parent
DIST = RACINE / "livraison" / "dist"
EXCLUS_NOMS = {".env", "donnees", "__pycache__", ".DS_Store", ".gitkeep"}

LISEZ_MOI_SITE = """SITE INTERNET — BRIQUETERIE SARL DPR AXXAM
==========================================

Mise en ligne :
1. Déposez TOUT le contenu de ce dossier (index.html, images/, videos/, favicon.svg,
   robots.txt, sitemap.xml) dans le dossier public de votre hébergement
   (souvent « public_html » ou « www »), par cPanel ou par FTP.
2. Activez le certificat https (Let's Encrypt), puis ouvrez https://www.dpraxxam.com

Aperçu sans hébergement : double-cliquez sur index.html.
(La carte Google et les polices s'affichent avec une connexion Internet.)

Réglages : en bas du fichier index.html, bloc « RÉGLAGES »
(photo aérienne, vidéo YouTube). Détails dans DPR-AXXAM_Guide.pdf.
"""


def ajouter_dossier(z, dossier, prefixe, filtre=None):
    for chemin in sorted(dossier.rglob("*")):
        rel = chemin.relative_to(dossier)
        if any(p in EXCLUS_NOMS for p in rel.parts) or chemin.is_dir():
            continue
        if filtre and not filtre(rel):
            continue
        z.write(chemin, f"{prefixe}/{rel.as_posix()}")


def zip_site():
    cible = DIST / "DPR-AXXAM_site-web.zip"
    with zipfile.ZipFile(cible, "w", zipfile.ZIP_DEFLATED) as z:
        ajouter_dossier(z, RACINE / "site-briqueterie", "DPR-AXXAM_site-web")
        z.writestr("DPR-AXXAM_site-web/LISEZ-MOI.txt", LISEZ_MOI_SITE)
    return cible


def zip_erp():
    cible = DIST / "DPR-AXXAM_ERP-Dolibarr.zip"
    pas_de_sauvegarde = lambda rel: not (rel.parts[0] == "sauvegardes" and rel.suffix == ".sql")
    with zipfile.ZipFile(cible, "w", zipfile.ZIP_DEFLATED) as z:
        ajouter_dossier(z, RACINE / "erp-dolibarr", "DPR-AXXAM_ERP-Dolibarr", pas_de_sauvegarde)
        z.writestr("DPR-AXXAM_ERP-Dolibarr/sauvegardes/", "")
    return cible


def zip_videos():
    cible = DIST / "DPR-AXXAM_videos-publicitaires.zip"
    dossier = RACINE / "videos-pub"
    with zipfile.ZipFile(cible, "w", zipfile.ZIP_STORED) as z:  # MP4 déjà compressé
        for nom in ("dpr-axxam-pub-vertical-9x16.mp4", "dpr-axxam-pub-horizontal-16x9.mp4", "README.md"):
            z.write(dossier / nom, f"DPR-AXXAM_videos-publicitaires/{nom}")
    return cible


async def guide_pdf():
    from playwright.async_api import async_playwright
    cible = DIST / "DPR-AXXAM_Guide.pdf"
    chromium = os.environ.get("CHROMIUM", "/opt/pw-browsers/chromium")
    async with async_playwright() as p:
        options = {"executable_path": chromium} if Path(chromium).exists() else {}
        navigateur = await p.chromium.launch(**options)
        page = await navigateur.new_page()
        await page.goto((RACINE / "livraison" / "guide.html").as_uri(), wait_until="networkidle")
        await page.pdf(path=str(cible), format="A4", print_background=True, prefer_css_page_size=True,
                       display_header_footer=True, header_template="<span></span>",
                       footer_template='<div style="font-size:7pt;color:#8a7a70;width:100%;text-align:center">'
                                       'DPR AXXAM — Guide de livraison · page <span class="pageNumber"></span>'
                                       ' / <span class="totalPages"></span></div>')
        await navigateur.close()
    return cible


def main():
    if DIST.exists():
        shutil.rmtree(DIST)
    DIST.mkdir(parents=True)
    paquets = [zip_site(), zip_erp(), zip_videos(), asyncio.run(guide_pdf())]
    complet = DIST / "DPR-AXXAM_livraison-complete.zip"
    with zipfile.ZipFile(complet, "w", zipfile.ZIP_STORED) as z:
        for f in paquets:
            z.write(f, f"DPR-AXXAM_livraison/{f.name}")
    for f in paquets + [complet]:
        print(f"{f.stat().st_size / 1_048_576:7.1f} Mo  {f.relative_to(RACINE)}")


if __name__ == "__main__":
    main()
