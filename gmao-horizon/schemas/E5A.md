# Schéma E5A : QUADRO 2 LAVORAZIONE (DPR ALGERIA)

Source : `MOULEUSE_SALLE_DE_CONTROLE_E5A.pdf`, 47 pages, folios 400 à 474. Le nom du fichier parle de « salle de contrôle », mais le cartouche indique **QUADRO 2 LAVORAZIONE**. C'est une armoire de puissance : départs moteurs, variateurs et station d'E/S.

## Page de garde (folio 400)
- Constructeur : Marcheluzzo Impianti, Castelnovo di Isola Vicentina (VI), Italie
- Client : D.P.R., destination ALGERIE ; installation « DPR ALGERIA »
- N° de commande (commessa) : 1361_100 ; ordre : DIC.2013 ; matricule : 140126
- Description : SALA MACCHINE ; dénomination du cartouche : QUADRO 2 LAVORAZIONE
- N° du schéma : E5A ; version VS.1 du 16/01/2014 ; émis en décembre 2013 ; dessinateur : Stefano
- Alimentation 3 x 400 V 50 Hz ; commande 24 Vdc ; auxiliaires 230 Vac ; armoire Rittal RAL 7035, IP54, entrée des câbles par le fond
- Capteurs Siemens/Sick, variateurs Mitsubishi ; normes EN 60204-1 / EN 439-1

## Liste des folios
| Folio | Page | Titre / contenu | Moteurs |
|---|---|---|---|
| 400 | 1 | Page de garde | 0 |
| 401-403 | 2-4 | Légende des symboles | 0 |
| 407 | 5 | Arrivée 400 V, sectionneur général 407SZ1 Siemens VL1600N 3x1600A, bobine de déclenchement 407K1, relais de sécurité Pilz PNOZ X7 407PILZ1, portes 407MC1-4 | 0 |
| 408 | 6 | Prise, néons, ventilateurs de l'armoire 408V1-V3, alimentation 24 Vdc 408TR2 Siemens SITOP PSU100S 10A | 0 (ventilateurs d'armoire, non comptés) |
| 409 | 7 | Voltmètre et ampèremètre (TA 409TA1-3, ILME) | 0 |
| 410 | 8 | Transformateur auxiliaire 410TR1 1000VA, 410K1 | 0 |
| 411 | 9 | Arrêts d'urgence 411S1/S2, Pilz PNOZ X7 411PILZ1 | 0 |
| 412 | 10 | Arrosage du mélangeur (M156) et du mélangeur de l'étireuse (M159), 412F1 | 0 |
| 413 | 11 | Convoyeurs à bande 4 (M153) et 5 (M154) avec freins BGE-SEW | 2 (11.4 kW) |
| 414 | 12 | Mélangeur M155, variateur 414I1 | 1 (160 kW) |
| 415 | 13 | Pompe du mélangeur M155/1, convoyeur à bande 6 M157 (frein BGE-SEW) | 2 (3.37 kW) |
| 417 | 14 | Dégazeur de l'étireuse M158, variateur 417I1 | 1 (160 kW) |
| 418 | 15 | Pompe M158/1 | 1 (0.37 kW) |
| 419 | 16 | Étireuse M160, variateur 419I1 | 1 (315 kW) |
| 420 | 17 | Aspo 1 M161, aspo 2 M162, pompe de l'étireuse M163, ventilateur de l'étireuse M163/1 | 4 (12.7 kW) |
| 421 | 18 | Pompe à vide M164 (démarreur progressif 3RW44) et départ vers l'armoire de la pompe à vide M164/1 | 1 (30 kW) |
| 422 | 19 | Départ « GRU / PILAN EAU ELECTRIQUE » M165, 422F1 3x40A | 0 (aucun moteur dessiné) |
| 430-431 | 20-21 | Commandes 220 V des contacteurs (460K/461K → K de puissance) | 0 |
| 432 | 22 | Relais de niveau Omron 61F-GP-N2 432L1-L4 | 0 |
| 433 | 23 | Sonde de niveau d'argile MBA210 | 0 |
| 440 | 24 | Composition de la station d'E/S IM153-4PN | 0 |
| 441 | 25 | Voyants et sirène | 0 |
| 444-455 | 26-37 | Entrées TOR I20.0 à I31.7 | 0 |
| 459 | 38 | Entrée analogique AIW0 : pression d'huile de l'étireuse 459PA1 | 0 |
| 460-463 | 39-42 | Sorties Q20.0 à Q23.7 via relais Siemens 6ES7 924-0BD10-0BB0 | 0 |
| 470-474 | 43-47 | Borniers (moteurs, pupitre « AL PULPITO ») | 0 |

Total : **7 machines, 13 moteurs, environ 692.8 kW** (en comptant le convoyeur à bande 6 à 3 kW). On compte 3 variateurs Mitsubishi et 1 démarreur progressif Siemens.

## Automate
- Station d'E/S déportée Siemens IM 153-4PN (ET200M), réf. 6ES7 153-4AA01-0XB0. Elle est reliée par Ethernet au « pulpito lavorazione ».
- Cartes : SM321 DI32 321-1BL00-0AA0 (x3), SM322 DO32 322-1BL00-0AA0, AI8x13bit 332-1KF02-0AB0 (étiquetée « SM322 » sur le dessin), SM321 DI16 321-1BH02-0AA0.
- **La CPU ne figure pas dans ce schéma** : elle est probablement dans le pupitre ou dans une autre armoire.
- Réseau Profibus : variateurs DP52, DP53 et DP54 (cartes FR-A7NP-E KIT-SC-E), démarreur progressif DP62 (3RW4900-0KC00).
- Sécurité : Pilz PNOZ X7 (407PILZ1, 411PILZ1).

## Points douteux
1. **Modèles des variateurs** : ils sont écrits « FR-AF740-04320-EC » et « FR-AF740-07700-EC ». La référence Mitsubishi habituelle est FR-F740-…, ce qui laisse penser à une coquille. Je les ai recopiés tels qu'écrits.
2. **Fusible du variateur 414I1** : il est repéré « 24F1 » au lieu de « 414F1 », probablement une coquille.
3. **Puissance du convoyeur à bande 6 (M157)** : 3 kW / 6.2 A au folio 415, mais 4 kW / 8 A sur le bornier du folio 470. J'ai retenu la valeur de 3 kW du folio de puissance.
4. **Pompe M158/1** : elle est libellée « POMPA MESCOLATORE » au folio 418. La commande 460K5 et le thermique I20.4 indiquent qu'il s'agit de la « pompa olio impastatore mattoniera ». Je l'ai donc rattachée au mélangeur de l'étireuse.
5. **Contacteur du ventilateur de l'étireuse** : il est repéré 420K4 au folio 420, mais sa bobine est repérée 420K5 au folio 431.
6. **Contacteur 413K4** (frein du convoyeur à bande 5) : le modèle n'est pas indiqué.
7. **Fusibles 421F1** : la marque n'est pas indiquée.
8. **Termes « mescolatore » et « impastatore »** : ils désignent la même machine (M155). « Degasatore mattoniera » (M158) correspond à « impastatore mattoniera ».
9. **« Aspo 1/2 »** (piocheur) : je les ai rattachés à l'étireuse.
10. **Départs sans moteur dessiné**, non comptés comme moteurs : M156 et M159 (arrosage), M165 (GRU / pilan eau électrique). M164/1 (armoire de la pompe à vide) figure dans le CSV comme ligne organe.
11. **Criticité** : elle est estimée, A pour les moteurs principaux, les pompes à huile et les aspo, B pour le ventilateur de l'étireuse.
