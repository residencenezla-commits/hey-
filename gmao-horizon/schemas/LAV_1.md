# LAV — Schéma « LAVORAZIONE DPR ALGERIA », pages PDF 1 à 48

## Page de garde (page 1 = folio 300)
- Constructeur : Marcheluzzo Impianti, Via Brenta 7, 36033 Castelnovo di Isola Vicentina (VI), Italie
- Client : D.P.R. — Commessa 1361_100 — Description : SALA MACCHINE — Destination : ALGERIA
- Schéma électrique **E5**, matricule 140125, version VS.1 16/01/2014, dessinateur STEFANO, émission décembre 2013
- Cartouche : Impianto « DPR ALGERIA », Denominazione **« QUADRO 1 LAVORAZIONE »**, fichier 140125_V0.0
- Alimentation 3x400V 50Hz, commandes 24Vdc, auxiliaires 230Vac, IP54, RAL 7035, EN 60204-1 / EN 439-1
- Capteurs Siemens / SICK, variateurs **Mitsubishi**

**Pas de sommaire / index des folios.** Les folios se suivent par numéro (case « FOGLIO / SEGUE » du cartouche). Le n° de folio sert de préfixe aux repères (ex. 313Q1 = disjoncteur Q1 du folio 313).

**Deuxième armoire dans le même PDF** : la page 44 est la page de garde d'un autre schéma : **E5A « QUADRO 2 LAVORAZIONE »**, matricule 140126, mêmes caractéristiques (folios 400+). Pour l'assistant qui traite les pages 49-95 : les folios 401-403 (pages 45-47) sont la légende et le folio 407 (page 48) est l'arrivée générale de Quadro 2. Les folios suivants commencent au 408.

## Liste des folios (pages 1-48)
| Page | Folio | Titre / contenu | Moteurs | kW |
|---|---|---|---|---|
| 1 | 300 | Page de garde E5 Quadro 1 | 0 | |
| 2-4 | 301-303 | Légende des symboles (M moteur, Q salvamotore/termica, K teleruttore, SZ sezionatore, F fusibile, PX proximity, MC finecorsa…) | 0 | |
| 5 | 307 | Arrivée : interrupteur général 307SZ1 Siemens VL630N 3x630A (3VL57631DE360AA0), bobine minimum de tension 307K1, Pilz PNOZ X7 307PILZ1, arrêts d'urgence 307MC1/MC2 | 0 | |
| 6 | 308 | Prise, néons, ventilateurs armoire 308V1/V2 (thermostat 308T1), alim. 24VDC 308TR2 Siemens SITOP PSU300S 10A | 0 | |
| 7 | 309 | Voltmètre / ampèremètre (309V1, 309A1, TA) | 0 | |
| 8 | 310 | Transfo auxiliaire 310TR1 500VA 220V, contacteur auxiliaires 310K1 | 0 | |
| 9 | 311 | Arrêts d'urgence externes, Pilz 311PILZ1 PNOZ X7, 311K1 | 0 | |
| 10 | 312 | Alimentation détecteur de métaux (M142) | 0 | |
| 11 | 313 | Catenaria argilla M137 + variateur 313I1 | 1 | 4 |
| 12 | 314 | Aspo argilla M138 + variateur 314I1 | 1 | 5.5 |
| 13 | 315 | Oliatore M139, Nastro 1 M140 (+ frein SEW), Sparpagliatore doppio M143 | 3 | 10.59 |
| 14 | 316 | Laminatoio veloce M144, soft starter 316A1 | 1 | 110 |
| 15 | 317 | Laminatoio lento M145, soft starter 317A1 (avant/arrière) ; alimentation armoire « HANDLE » 317F2 3x40A (M146) | 1 | 110 |
| 16 | 318 | Avanzamento utensili 1 M149 et 2 M150 (inversion) | 2 | 0.36 |
| 17 | 319 | Nastro 2 M151, Nastro 3 M152 (+ freins SEW) | 2 | 6 |
| 18 | 320 | Vide (barres) | 0 | |
| 19-21 | 330-332 | Commande 220V des contacteurs | 0 | |
| 22 | 333 | Vide | 0 | |
| 23 | 340 | **Automate Siemens S7-300 CPU 315-2 PN/DP (6ES7 315-2EH14-0AB0)**, SM321 DI32 (321-1BL00-0AA0) x2, SM322 DO32 (322-1BL00-0AA0) ; Profibus + Ethernet vers Quadro 2 | 0 | |
| 24 | 341 | Voyants / sirène | 0 | |
| 25-32 | 344-351 | Entrées automate (modules marqués 6ES7 131-4BF00-0AA0 = ET200S DI) : thermiques, contrôles rotation, sectionneurs, urgences | 0 | |
| 33 | 359 | Pilz 359PILZ1 urgence laminoir | 0 | |
| 34-37 | 360-363 | Sorties automate, relais 6ES7 924-0BD10-0BB0 | 0 | |
| 38-43 | 370-375 | Borniers | 0 | |
| 44 | 400 | Page de garde E5A **Quadro 2 Lavorazione** | 0 | |
| 45-47 | 401-403 | Légende Quadro 2 | 0 | |
| 48 | 407 | Arrivée Quadro 2 : 407SZ1 Siemens VL1600N 3x1600A (3VL87161DE300AA0), 407K1, Pilz 407PILZ1, urgences 407MC1-MC4 | 0 | |

**Total Quadro 1 : 11 moteurs, 246.45 kW** (dont 2 x 110 kW pour le laminoir). 2 variateurs Mitsubishi FR-E740-120SC-EC (Profibus FR-A7NP-E), 2 démarreurs progressifs Siemens 3RW4445-6BC44.

## Machines (Quadro 1)
- CAS1 Caisson doseur 1 (cassone 1) : M137 catenaria, M138 aspo
- OLI Graisseur (oliatore) : M139
- CB1 / CB2 / CB3 Convoyeurs à bande 1-3 : M140, M151, M152 (freins SEW BGE)
- SPAR Répartiteur double : M143
- LAM Laminoir : M144 (rapide 110 kW), M145 (lent 110 kW), M149/M150 avance outils de rectification

## Points douteux
- Les moteurs n'ont pas de repère « nnnM1 » : seul le numéro dans un cadre (M137, M138…) les identifie, il a été repris comme repère / tag. Numéros sautés (M141, M147, M148) : ils ne figurent pas sur ces pages. M142 = détecteur de métaux, M146 = alimentation armoire « HANDLE » : ce ne sont pas des moteurs.
- 316Q1 / 317Q1 : référence Siemens écrite « XXX » sur le schéma (réglage inconnu).
- 319Q3 : réglage écrit « 3.5-8A » alors que la réf. 3RV2011-1HA10 est 5.5-8A (probable coquille).
- Folio 330 : la bobine du répartiteur est repérée « 215K4 » au lieu de 315K4 (coquille).
- Laminoir : « laminatoio veloce / lento » pris comme les deux cylindres d'un seul laminoir. Les textes parlent parfois de « laminatoi » au pluriel, il pourrait donc s'agir de deux machines.
- L'oliatore n'est rattaché à aucune machine sur le schéma (probablement le graissage du laminoir, non confirmé).
- Le folio 332 cite « bagnatura cassone 3 » (363K3) : le caisson 3 n'a pas de moteur dans Quadro 1, il doit être dans Quadro 2.
- Pas de « vitesse » en tr/min sur le schéma : la colonne indique variable / fixe / démarreur progressif.
