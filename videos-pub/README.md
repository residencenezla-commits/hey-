# Vidéos publicitaires — Briqueterie DPR Axxam

| Fichier | Format | Pour |
|---|---|---|
| `dpr-axxam-pub-vertical-9x16.mp4` | 1080 × 1920, 24 s | Reels Instagram/Facebook, TikTok, YouTube Shorts, statuts WhatsApp |
| `dpr-axxam-pub-horizontal-16x9.mp4` | 1920 × 1080, 24 s | YouTube, Facebook, site web (bouton « Le film de l'usine ») |

Les vidéos sont **muettes** : ajoutez une musique libre de droits au moment de la
publication (bibliothèque audio de TikTok, Instagram, YouTube ou CapCut).

## Déroulé (24 s)

1. **0–4 s** — Un mur de briques se construit : « La force de la terre », logo dpr AXXAM.
2. **4–7,5 s** — Photo de notre carrière d'argile : « De la carrière… »
3. **7,5–11 s** — Four animé, flammes et briques qui cuisent : « …au four », ≈ 820 °C (température relevée sur la supervision du four).
4. **11–15 s** — Produits rouges : B8, B12, hourdis et leurs formats.
5. **15–19,5 s** — « −23 % d'énergie de chauffage avec des murs en briques » (étude CNERIB pour l'ABA, 2019).
6. **19,5–24 s** — Logo, 2018 · 140 000 t/an, téléphones, adresse, www.dpraxxam.com, membre de l'ABA.

## Modifier et régénérer

Les textes et l'animation sont dans `source/pub.html`. Après modification :

```bash
cd videos-pub/source
python3 frames.py "$PWD" 1080 1920 full images-v   # 720 images verticales
python3 frames.py "$PWD" 1920 1080 full images-h   # 720 images horizontales
ffmpeg -framerate 30 -i images-v/f%04d.jpg -c:v libx264 -crf 21 -pix_fmt yuv420p -movflags +faststart ../dpr-axxam-pub-vertical-9x16.mp4
ffmpeg -framerate 30 -i images-h/f%04d.jpg -c:v libx264 -crf 21 -pix_fmt yuv420p -movflags +faststart ../dpr-axxam-pub-horizontal-16x9.mp4
```

(Nécessite Python avec Playwright et Chromium, et ffmpeg.)
