#!/usr/bin/env python3
"""Fabrique les images de filigrane (slogan en fond des bons d'enlèvement).
Usage : python3 outils/filigranes.py   → modules/timbredz/img/filigrane-<slogan>-<densité>.png
Variante « -diagonale » : slogan en travers du bon, du numéro jusqu'en bas à gauche.
Densités : moyen (≈ 45 % de la couleur brique) et fort (≈ 70 %), réglées pour rester visibles à l'impression."""
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

IMG = Path(__file__).resolve().parent.parent / 'modules' / 'timbredz' / 'img'
POLICE = '/usr/share/fonts/truetype/liberation/LiberationSerif-BoldItalic.ttf'
SLOGANS = {'force': 'La force de la terre', 'bien': "Le bien-être dans l'habitat"}
DENSITES = {'moyen': (115, 85), 'fort': (180, 135)}   # opacité : grand texte, petits textes
BRIQUE = (180, 71, 43)

def filigrane(txt, a_grand, a_petit, W=2000, H=1100, angle=12):
    f, f2 = ImageFont.truetype(POLICE, 170), ImageFont.truetype(POLICE, 64)
    petit = txt + '   ·   ' + txt
    d0 = ImageDraw.Draw(Image.new('RGBA', (10, 10)))
    b1, b2 = d0.textbbox((0, 0), txt, font=f, stroke_width=3), d0.textbbox((0, 0), petit, font=f2)
    lw = max(b1[2] - b1[0], b2[2] - b2[0]) + 40
    lh = (b1[3] - b1[1]) + 2 * (b2[3] - b2[1]) + 260
    cal = Image.new('RGBA', (lw, lh), (0, 0, 0, 0)); d = ImageDraw.Draw(cal)
    d.text(((lw - (b2[2] - b2[0])) // 2 - b2[0], 10 - b2[1]), petit, font=f2, fill=BRIQUE + (a_petit,))
    # le grand slogan est épaissi (contour) pour mieux sortir à l'impression
    d.text(((lw - (b1[2] - b1[0])) // 2 - b1[0], (lh - (b1[3] - b1[1])) // 2 - b1[1]), txt, font=f,
           fill=BRIQUE + (a_grand,), stroke_width=3, stroke_fill=BRIQUE + (a_grand,))
    d.text(((lw - (b2[2] - b2[0])) // 2 - b2[0], lh - 10 - (b2[3] - b2[1]) - b2[1]), petit, font=f2, fill=BRIQUE + (a_petit,))
    cal = cal.rotate(angle, resample=Image.BICUBIC, expand=True)
    k = min(W / cal.width, H / cal.height) * 0.97
    cal = cal.resize((int(cal.width * k), int(cal.height * k)), Image.LANCZOS)
    out = Image.new('RGBA', (W, H), (0, 0, 0, 0))
    out.paste(cal, ((W - cal.width) // 2, (H - cal.height) // 2), cal)
    return out

if __name__ == '__main__':
    for cle, txt in SLOGANS.items():
        for dens, (ag, ap) in DENSITES.items():
            nom = IMG / f'filigrane-{cle}-{dens}.png'
            filigrane(txt, ag, ap).save(nom, optimize=True)
            print('écrit', nom.name)
            # variante en grande diagonale : du numéro (en haut à droite) jusqu'en bas à gauche du bon
            nom = IMG / f'filigrane-{cle}-{dens}-diagonale.png'
            filigrane(txt, ag, ap, W=2000, H=1250, angle=30).save(nom, optimize=True)
            print('écrit', nom.name)
