# NAV — Quadro mobile navetta (schéma E4A)

## Page de garde (folio 200)
- Constructeur : Marcheluzzo Impianti, Castelnovo di Isola Vicentina (VI), Italie
- Client : D.P.R. — Impianto « DPR ALGERIA » — Destination Algérie
- Commessa 1361_100 — Ordine DIC.2013 — Matricola 140124 — Descrizione (page de garde) : SALA MACCHINE
- Schéma électrique n° E4A, version VS.1 du 16/01/2014, émis décembre 2013, dessinateur Stefano, fichier 140124_V0.0
- Dénomination cartouche : **QUADRO MOBILE NAVETTA** (armoire embarquée sur la navette)
- Alim. 3x400V 50Hz ; commande 24Vdc ; auxiliaires 230Vac ; armoire Rittal RAL 7035 IP54, entrée câbles par le fond
- Capteurs : Siemens / Sick ; photocellules Sick ; variateurs Mitsubishi

## Folios (27 pages)
| Folio | Contenu |
|---|---|
| 200 | Page de garde |
| 201-203 | Légende des symboles |
| 207 | Alimentation 400V, sectionneur général 207SZ1 Siemens 3x32A |
| 208 | Prise armoire, ventilateur armoire 208V1 + thermostat 208T1, alim. 24Vdc 208TR2 Siemens SITOP PSU100S 5A |
| 210 | Transfo auxiliaires 210TR1 300VA 220V, contacteur 210K1 |
| 211 | Chaîne d'arrêt d'urgence 11S2 → 211K1 → 210K1 |
| 212 | Tapis 9A (M128) et 9B (M129) 2,2 kW, inverseur 212K1/212K2 |
| 213 | Translation navette M130 3 kW sur variateur 213I1 + frein SEW BGE |
| 214 | Tapis 10 et 11 (inverseurs 214K1-K4) — bornes marquées « scorta » |
| 215 | Lanceurs 10SX/10DX/11SX/11DX (M133-M136) 2,2 kW sur variateur commun 215I1 |
| 216 | Vide (barres R1 S1 T1) |
| 220-221 | Commande 220V des contacteurs (interverrouillages) |
| 229 | API Siemens CPU 314-2 PN/DP + SM321 + module IWLAN Siemens |
| 230-234 | Entrées API I20.0 à I24.7 |
| 240-241 | Sorties API Q20.x/Q21.x → relais 240K1-K8, 241K1-K8 (Siemens 6ES7 924-0BD10-0BB0) |
| 250-253 | Borniers |

## Synthèse
- 1 machine (la navette, avec ses tapis 9A/9B, 10, 11 et 4 lanceurs embarqués/commandés)
- **9 moteurs**, puissance totale **17,7 kW** : translation 3 kW + tapis 9A/9B 2×2,2 + tapis 10/11 2×0,75 + lanceurs 4×2,2
- 22 organes : 4 fins de course translation (231MC2 nord, 231MC3 sud, 231MC4 / 232MC1 urgence), 1 tirette à câble 231MC1, 8 détecteurs de contrôle de rotation (PX), 7 sectionneurs locaux, 1 AU 11S2
- API : Siemens S7-300 CPU 314-2 PN/DP (6ES7 314-6EH04-0AB0), SM321 DI16 (321-1BH02-0AA0), liaison sans fil IWLAN 6GK5746-1AA30-4AA0 + antenne ANT793-4MN 5 GHz ; Profibus vers variateurs
- Variateurs Mitsubishi Electric : 213I1 FR-E740-095SC-EC (translation, nœud DP55) ; 215I1 FR-E740-060SC-EC (4 lanceurs, 1 seul à la fois via 215K1-K4, nœud DP56) ; cartes Profibus FR-A7NP-E KIT-SC-E ; filtres FFR-MSH-170-30A-RF1
- Disjoncteurs moteurs Siemens 3RV20, contacteurs Siemens 3RT20 bobines 220V

## Points douteux
- Folio 214 : sorties U4/V4/W4 et U5/V5/W5 marquées « SCORTA/RESERVE », mais la commande (220), les thermiques (230) et le bornier (250) les attribuent aux tapis 10 et 11 (0,75 kW). De même 240K3-K6 marqués « libero » au folio 240 alors qu'utilisés au folio 220. Pas de n° moteur M pour ces deux tapis → tags construits sur 214Q1/214Q2.
- 213Q1 : référence 3RV2021-1JA10 mais réglage indiqué 4,5-6,3 A (la réf. Siemens 1JA correspond normalement à 7-10 A) ; à vérifier sur place. 214Q2 « 3RV2011-1ACA0 » probablement faute de frappe pour 1CA10.
- Repères probablement erronés sur le schéma : « 232P12 » (232PX1 ?), « 23PX1 » (233PX1 ?), 232PX3 libellé « cg nastro 10 sx » mais placé avec le lanceur 10SX.
- Folios 231 vs bornier 252 : fins de course libellés nord/sud au folio 231 mais sx/dx (gauche/droite) au bornier ; I21.4 (213SZ1) marqué « LIBERO » mais bornier « sezionatore traslazione ». Le bornier 252 cite aussi « sezionatore nastro 9A/9B » (230.7, 231.1) et « livello infrarosso » (233.5) marqués scorta/libero sur les folios d'entrées.
- Numéros de fils du bornier 250 pour les lanceurs (214.7…214.18) ne correspondent pas aux fils 215.x du folio 215.
- L'AU 11S2 porte un repère d'un autre schéma (folio 11) : bouton externe à la navette.
- Le frein de translation (SEW, redresseur BGE) n'a pas de puissance indiquée ; il est inclus dans la ligne moteur M130.
