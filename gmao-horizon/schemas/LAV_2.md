# LAV : extraction des pages 49 à 95 (schéma Marcheluzzo, DPR Algeria, commessa 1361_100)

Page 1 : garde E5, « SALA MACCHINE », QUADRO 1 LAVORAZIONE, matricola 140125, 16/01/2014. Fournisseur des variateurs : Mitsubishi.
Pages 49 à 85 : **E5A QUADRO 2 LAVORAZIONE** (fichier 140126), folios 408 à 474.
Pages 86 à 95 : **E5B PULPITO LAVORAZIONE** (fichier 140127, matricola 140127), folios 500 à 521.

| Page | Folio | Titre / contenu | Moteurs | kW |
|---|---|---|---|---|
| 49 | 408 | Prise, néons, ventilateurs d'armoire 408V1-V3, alimentation 24 V SITOP PSU100S 10 A | 0 | 0 |
| 50 | 409 | Voltmètre et ampèremètre, TA | 0 | 0 |
| 51 | 410 | Transformateur 1000 VA 220 V, auxiliaires 220 V | 0 | 0 |
| 52 | 411 | Relais de sécurité Pilz PNOZ X7, arrêt d'urgence | 0 | 0 |
| 53 | 412 | Alimentation des mouillages M156 et M159 | 0 | 0 |
| 54 | 413 | Convoyeur 4 (M153) et convoyeur 5 (M154), avec frein BGE-SEW | 2 | 11.4 |
| 55 | 414 | Mélangeur M155, variateur FR-A740-04320 | 1 | 160 |
| 56 | 415 | Pompe à huile du mélangeur M155/1, convoyeur 6 M157 avec frein | 2 | 4.37 |
| 57 | 417 | Mélangeur de l'étireuse M158, variateur FR-A740-04320 | 1 | 160 |
| 58 | 418 | Pompe à huile du mélangeur de l'étireuse M158/1 | 1 | 0.37 |
| 59 | 419 | Étireuse M160, variateur FR-A740-07700 | 1 | 315 |
| 60 | 420 | Aspo 1 et 2 (M161, M162), pompe à huile de l'étireuse M163, ventilateur de l'étireuse M163/1 | 4 | 12.7 |
| 61 | 421 | Pompe à vide M164 (démarreur progressif 3RW44), départ armoire pompe à vide M164/1 | 1 | 30 |
| 62 | 422 | Page vide | 0 | 0 |
| 63-64 | 430-431 | Commandes 220 V des contacteurs | 0 | 0 |
| 65 | 440 | Configuration des E/S | 0 | 0 |
| 66 | 441 | Voyants et sirène | 0 | 0 |
| 67-75 | 444-452 | Entrées TOR (thermiques, sectionneurs, contrôles de rotation, câbles d'arrêt d'urgence, PTC, filtres et niveaux d'huile) | 0 | 0 |
| 76 | 459 | Entrée analogique : pression d'huile de l'étireuse 459PA1 | 0 | 0 |
| 77-80 | 460-463 | Sorties TOR et relais 6ES7 924-0BD10-0BB0 | 0 | 0 |
| 81-85 | 470-474 | Borniers | 0 | 0 |
| 86-89 | 500-503 | Garde E5B et légende | 0 | 0 |
| 90 | 507 | 2 régulateurs Novatronic C2 : mouillage du mélangeur (M156) et de l'étireuse (M159, avec plastomètre et PT100) | 0 | 0 |
| 91-93 | 508-510 | Boutons du pupitre, voyants, pupitre tactile Siemens TP1200 12" (6AV2124-0MC01-0XA0) | 0 | 0 |
| 94-95 | 520-521 | Borniers du pupitre | 0 | 0 |

**Total : 13 moteurs, 693.8 kW. 3 variateurs Mitsubishi et 1 démarreur progressif Siemens.**

## Automate (Quadro 2)
- Station d'E/S déportées Siemens ET200M, IM 153-4PN (6ES7 153-4AA01-0XB0), reliée par câble Ethernet au pupitre. La CPU n'apparaît pas dans les pages 49-95 : elle est probablement dans le Quadro 1 (pages 1-48).
- Cartes :
  - 2 cartes SM321 DI32 (321-1BL00-0AA0) ;
  - 1 carte SM322 DO32 (322-1BL00-0AA0) ;
  - 1 carte AI8 x 13 bit (marquée « SM322 332-1KF02-0AB0 », probablement SM331 331-1KF02-0AB0) ;
  - 1 carte SM321 DI16 (321-1BH02-0AA0).
- Les variateurs communiquent en Profibus (cartes FR-A7NP-E), de même que le démarreur progressif (3RW4900-0KC00).

## Points douteux
- Les cartouches des folios d'entrées 444-451 indiquent la carte 6ES7 131-4BF00-0AA0 (ET200S), alors que le folio 440 montre des cartes SM321. Les folios de sorties 460-463 indiquent 321-1BL00 au lieu de 322-1BL00.
- Les variateurs sont marqués « FR-AF740-… ». J'ai relevé la référence FR-A740-…-EC, qui est très probablement la bonne.
- Le fusible du folio 414 est marqué « 24F1 », probablement pour 414F1.
- La référence du contacteur 413K4 n'est pas indiquée.
- Le moteur M163/1 a pour contacteur 420K4 au folio 420, mais sa bobine est repérée 420K5 au folio 431.
- « Mescolatore » et « impastatore » désignent la même machine. M158, « mescolatore mattoniera », est le mélangeur monté sur l'étireuse. Je l'ai placé dans une machine séparée, DPR-LAV-MESCMATT : à fusionner avec la machine MATT si vous préférez.
- Les repères M155/1, M158/1, M163/1 et M164/1 sont conservés avec la barre oblique dans les tags.
- M164/1 n'est pas un moteur : c'est l'alimentation 3x40A de l'armoire de la pompe à vide. Il n'est donc pas compté.
- M156 et M159 sont des systèmes de mouillage (électrovannes et régulateurs Novatronic), pas des moteurs.
- Les folios 416 et 400-407 sont absents de cette partie. Le folio 416 est sauté dans la numérotation (415 renvoie à 417).
