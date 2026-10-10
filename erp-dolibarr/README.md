# ERP Dolibarr — Briqueterie SARL DPR Axxam

Dolibarr est un logiciel de gestion (ERP) **open source et gratuit**, en français.
Ce dossier l'installe déjà paramétré pour la briqueterie : ventes, achats, stocks,
caisse et fabrication.

## Ce qui est prêt dès l'installation

| Élément | Paramétrage |
|---|---|
| Société | SARL DPR AXXAM, capital 294 000 000 DA, RC 08B0185858-06/00, NIF 000806018585831, siège Village Tizi, Tissa, 06020 Semaoun (Béjaïa), usine ZAC Helouane ; devise **dinar (DZD)**, langue française, gérant Ahcene DJENNADI |
| Clients et fournisseurs | **421 tiers repris de PC Compta** (282 clients, 139 fournisseurs) avec leur code (C005, FL012…), compte général (41100, 40101…), RC, NIF, AI et adresse ; solde au 08/10/2026 en note privée ; une catégorie par compte |
| Factures et devis | Modèle **DLG** (comme PC Compta) pour les factures et bons de livraison ; RC, NIF et AI du client imprimés sous son adresse ; capital, RC et NIF de DPR AXXAM en pied de page ; mention « Arrêtée la présente facture à la somme de : … dinars algériens » en toutes lettres |
| Comptabilité | plan comptable, 31 journaux et 8 748 écritures 2026 de PC Compta ; droit de timbre automatique ; export au format PC Compta (voir plus bas) |
| TVA | 19 % par défaut (0 % et 9 % disponibles) |
| Numérotation | Factures **FA2610-0001**, avoirs AV…, acomptes AC… |
| Produits vendus | **B8** (brique 8 trous), **B12** (brique 12 trous), **HOURDIS** 16 — vendus à la pièce |
| Achats | Argile (tonne), film de palettisation (kg), palettes, gasoil (litre), pièces de rechange ; services : gaz naturel (m³), électricité, transport, entretien |
| Entrepôts | **PF** parc produits finis · **MP** matières premières · **MAG** magasin pièces de rechange |
| Caisse | Compte « Caisse usine » en DZD |
| Catégories clients | Particuliers · Entreprises du bâtiment · Revendeurs / dépôts · Promoteurs et marchés publics |
| Catégories fournisseurs | Énergie · Pièces de rechange · Transport · Emballage · Argile |
| Modules actifs | Tiers, devis, commandes, bons de livraison, factures, achats, produits, stocks, banque/caisse, nomenclatures et ordres de fabrication, charges sociales et fiscales, salaires, agenda, import/export |

Tous les prix sont à **0** : vous les saisissez vous-même (voir plus bas).

## Installation

Il faut un ordinateur ou un serveur toujours allumé, avec **Docker**.

### Sur un PC Windows (le plus simple)

1. Installez **Docker Desktop** (docker.com), redémarrez, lancez-le.
2. Copiez ce dossier `erp-dolibarr` sur le PC (par exemple `C:\DPR-AXXAM\erp-dolibarr`).
3. Double-cliquez sur **`demarrer.bat`**. La première fois, le Bloc-notes s'ouvre :
   choisissez les 3 mots de passe, enregistrez, fermez, puis double-cliquez à nouveau
   sur `demarrer.bat`.
4. Après 2 à 3 minutes, le navigateur ouvre `http://localhost:8080`. Identifiant
   `admin`, mot de passe choisi dans `.env`.

Les autres postes du réseau de l'usine ouvrent `http://ADRESSE-IP-DU-PC:8080`
(mettez cette adresse dans `DOLI_URL_ROOT` du fichier `.env`).

| Double-clic | Effet |
|---|---|
| `demarrer.bat` | Démarre l'ERP et ouvre le navigateur |
| `arreter.bat` | Arrête l'ERP (les données restent dans `donnees/`) |
| `sauvegarder.bat` | Enregistre une sauvegarde dans `sauvegardes/` |
| `exporter_pccompta.bat` | Crée le fichier d'import PC Compta dans `exports/` |
| `importer_pccompta.bat` | Recharge un export de PC Compta (glisser le fichier dessus) |
| `tableau_de_bord.bat` | Calcule et ouvre le tableau de bord de gestion |
| `dossier_comptable.bat` | Bilan, TCR, balances, grand livre et G50 (Excel + page à imprimer) |
| `outils/bons_chargement.html` | Bons de chargement payés d'avance (2 parties par feuille A4 : bon client + souche) |

### Sur un serveur Linux

```bash
cd erp-dolibarr
cp .env.exemple .env      # puis ouvrez .env et changez les 3 mots de passe
docker compose up -d      # premier démarrage : 2 à 3 minutes
docker compose logs -f web   # attendre « You can connect to the running Dolibarr »
```

> **Sécurité.** Si l'ERP est accessible depuis Internet, il doit passer par une
> adresse en **https** (certificat). Ne laissez jamais les mots de passe d'exemple.

## À compléter le premier jour

1. **Accueil › Configuration › Société/Organisation** : vérifiez RC, NIF et capital
   (déjà saisis), ajoutez l'AI (article d'imposition), le NIS et le logo.
2. **Accueil › Configuration › Banques** : ajoutez vos comptes bancaires
   (banque, agence, RIB).
3. **Produits** : ouvrez B8, B12 et HOURDIS et saisissez le **prix de vente HT**.
   Pour les achats, saisissez le prix d'achat sur l'onglet *Prix fournisseurs*.
4. **Produits › Stocks** : saisissez le stock de départ de chaque produit (*Corriger le stock*).
5. **Utilisateurs & Groupes** : créez un compte par personne (commercial, magasinier,
   comptable…) avec seulement les droits utiles.

## Le quotidien de la briqueterie dans Dolibarr

| Activité | Où | Remarque |
|---|---|---|
| Nouveau client | Tiers › Nouveau client | Renseigner NIF/RC pour les entreprises |
| Devis à un client | Commerce › Propositions › Nouvelle | Transformable en commande puis en facture |
| Commande client | Commerce › Commandes › Nouvelle | |
| Chargement d'un camion | Commande › *Créer expédition* | Le stock du parc **PF** baisse à la validation du bon de livraison |
| Facturer | Commande ou expédition › *Créer facture* | Ou Facturation › Factures clients › Nouvelle |
| Encaisser (espèces, chèque, virement) | Facture › *Saisir règlement* | Le montant arrive dans la caisse ou la banque choisie |
| Production du jour | Produits › B8 › Stock › *Corriger le stock* (+ quantité) | Ou, plus complet : GPAO › Ordres de fabrication |
| Commande à un fournisseur | Commerce › Commandes fournisseurs | Le stock monte à la réception |
| Facture fournisseur (gaz, gasoil, pièces) | Facturation › Factures fournisseurs › Nouvelle | Puis *Saisir règlement* |
| Dépenses de caisse | Banques › Caisse usine › *Nouvelle écriture* | |
| Impayés clients | Accueil (tableau de bord) ou Facturation › Factures impayées | |
| Situation du stock | Produits › Stocks | Par entrepôt, valorisé |

### Fabrication détaillée (facultatif)

Le module **GPAO** permet de décrire la composition d'un produit (nomenclature :
argile, gaz, palettes… pour 1 000 briques) puis de lancer des **ordres de
fabrication** : la production consomme les matières et ajoute les briques au parc
PF. Les quantités dépendent de votre usine : à saisir avec le responsable de production.

## Clients et fournisseurs repris de la comptabilité

La liste vient du fichier « Liste Clients Fournisseurs Plan Comptable 2026 DPR AXXAM ».
Chaque tiers garde son **code PC Compta** : c'est son code client ou fournisseur
et son compte auxiliaire dans Dolibarr ; les nouveaux tiers se créent avec un code
libre, à saisir dans la même logique (C…, FL…, FS…).

Pour mettre à jour la liste depuis un nouvel export Excel :

```bash
python3 outils/convertir_tiers.py "Liste_Clients_Fournisseurs_Plan_Comptable_2026_DPR_AXXAM.xlsx"
docker compose exec web php /var/www/scripts/docker-init.d/20-tiers.php
```

Les tiers existants sont mis à jour (pas de doublons). Trois lignes du fichier
ne sont pas des tiers et sont écartées : « SOLDE SONELGAZ 2021 »,
« AVANCE CLIENTS 2014 -2019 » et « Concessions ». Les soldes ne sont **pas**
repris comme factures : ils figurent pour information dans la note privée.

## Comptabilité, sur le modèle de PC Compta

La comptabilité en partie double de Dolibarr est activée et réglée **comme dans PC Compta**,
à partir de l'export « DLG_COMPTA4 SARL DPR AXXAM consolidé 2026 » :

| Élément | Repris de PC Compta |
|---|---|
| Plan comptable | 347 comptes SCF de la société |
| Journaux | les 31 journaux avec les mêmes codes : 01 réouverture, 02 CPA El Kseur, 03 caisse briqueterie, 073 ventes briqueterie, 15 achats locaux briqueterie… |
| Banques et caisses | CPA 4083 (51201, journal 02), CPA 6599 (51211, 11), CPA 26216391 (51202, 23), BNA (51230, 13), Al Baraka (51240, 17), Société Générale (51250, 24), caisse briqueterie (53000, 03), caisse carrosserie (53000, 18) |
| Comptes par défaut | clients 41100 (auxiliaire = code tiers), fournisseurs 40101, ventes de briques 70110, TVA collectée 44570, TVA déductible 44563, timbre 44720, remises 70900, avances 41900 / 40900 |
| Écritures | les **8 748 lignes** de 2026 (2 582 pièces), verrouillées ; la balance est contrôlée compte par compte contre PC Compta à chaque chargement |

### Droit de timbre (module « Timbre DZ »)

À la validation d'une facture **payée en espèces**, le timbre est calculé tout seul sur le
TTC, arrondi au dinar supérieur : 1 % jusqu'à 30 000 DA, 1,5 % jusqu'à 100 000 DA, 2 % au-delà.
Ce barème redonne exactement le timbre de vos **471 factures** de 2026. Il s'imprime sur la
facture (« Timbre fiscal ») et passe au compte 44720.

Le même module écrit le **montant en lettres** au bas des factures et devis, par exemple
« Arrêtée la présente facture à la somme de : soixante mille trois cent quatre-vingt-treize
dinars algériens, toutes taxes comprises. » Le texte se modifie dans *Accueil › Configuration ›
Modules › Factures* (« Mention complémentaire »), en gardant la clé `__MONTANT_EN_LETTRES__`.

### Bons de chargement payés d'avance

Pour un client qui paie ses briques à l'avance, ouvrez `outils/bons_chargement.html` dans le
navigateur (sans Internet ni ERP) : client, produit, quantité par bon, prix TTC, nombre de bons,
validité. Chaque feuille A4 porte le **bon client** en haut (bon au porteur, sans prix, à
présenter au chargement) et la **souche** en bas (avec le prix et le montant réglé en lettres),
avec le même numéro. Le numéro suivant est proposé automatiquement sur le même poste. Au
chargement, le magasinier remplit date, matricule, chauffeur et visa, puis perfore le bon.
Enregistrez aussi le règlement dans l'ERP (facture ou acompte du client).

Version **Word** à remplir au stylo (2 bons par feuille A4, numérotés, cachet de la société,
date d'enlèvement, chauffeur, matricule, visa du magasinier et cadre « SERVI » pour la
comptabilité) :

```bash
node outils/bons_enlevement_word.js 1 10 BONS_ENLEVEMENT.docx        # bons 0001 à 0010
CLIENT="KERDJA BILEL" PRODUIT=B8 QUANTITE=7040 node outils/bons_enlevement_word.js 1 10
# 50 bons au même client, tous pré-remplis, + bordereau de remise avec le montant réglé :
TOUS=1 PRIX=20.50 CLIENT="..." PAYE="10/10/2026" PRODUIT=B8 QUANTITE=7040 node outils/bons_enlevement_word.js 1 50
# slogan en filigrane derrière chaque bon : FILIGRANE=force (« La force de la terre ») ou FILIGRANE=bien (« Le bien-être dans l'habitat »), DENSITE=moyen ou fort (défaut), DIAGONALE=1 pour la grande diagonale ; images : python3 outils/filigranes.py
```

### Factures et bons de livraison au format DLG

Les factures et les bons de livraison s'impriment par défaut avec le modèle **« dlg »**, qui
reprend la présentation des documents édités par DLG / PC Compta :

- **Numéro** : « FACTURE No:FA2610-0001 » souligné.
- **Bloc client** : code PC Compta, adresse, No R.C, No I.F. et No Art.
- **Informations** : date, No BC (référence client), No BL (bons de livraison liés), règlement.
- **Tableau** : Référence / Désignation / Un / TVA % / Remise % / Quantité / Prix HT / Montant HT.
- **Totaux** : récapitulatif de la TVA par taux, Total HT, Remise, Total TVA, droit de timbre,
  **Net à payer**, puis « ARRETEE LA PRESENTE FACTURE A LA SOMME DE : … » en lettres.
- **Pied de page** : capital, RC, article d'imposition et NIF de DPR AXXAM.

Le bon de livraison ajoute le véhicule (champ *Numéro de suivi* de l'expédition) et trois cases
de signature : magasinier, chauffeur, client. Une proforma (facture brouillon) porte le titre
« FACTURE PROFORMA », un avoir « AVOIR ». Les anciens modèles restent disponibles dans la liste
*Modèle* de chaque document.

### Le quotidien comptable

1. Les factures, règlements et achats sont saisis dans l'ERP.
2. **Comptabilité › Liaison** : *Lier automatiquement* (chaque ligne prend le compte de son produit).
3. **Comptabilité › Journaux** : ouvrir le journal (073 ventes briqueterie, 03 caisse…) et
   *Enregistrer dans le grand livre*. Exemple réel testé : facture de 2 000 B8 en espèces →
   41100/C023 60 393 au débit ; 70110 50 000, 44570 9 500, 44720 893 au crédit ; puis caisse
   53000 au débit / 41100 au crédit.
4. **Envoyer à PC Compta** : double-clic sur `exporter_pccompta.bat` (ou `./exporter_pccompta.sh`).
   Le fichier `exports/EXPORT_PCCOMPTA_….xls` est au **format binaire exact** des fichiers
   d'import de PC Compta (vérifié : le générateur réécrit à l'octet près vos fichiers PC Compta) :
   FOLIO = mois, PIECE à 6 chiffres qui continue la numérotation de PC Compta par journal,
   libellés en majuscules (40 caractères), tables TAB_JRN, TAB_COM et TAB_AUX (RC, NIF, AI des tiers).
   Une écriture n'est exportée qu'une fois. Options : `--du=2026-10-01 --au=2026-10-31`,
   `--journal=073`, `--essai` (fichier sans marquer les écritures).
5. **Recharger PC Compta dans l'ERP** (après des saisies faites dans PC Compta : paie,
   carrosserie, OD…) : faites glisser le nouvel export `DLG_COMPTA….xls/.xlsx` sur
   `importer_pccompta.bat` (ou `./importer_pccompta.sh fichier`). Les écritures venues de
   PC Compta sont remplacées, celles saisies dans l'ERP restent, et celles que l'ERP avait
   déjà envoyées à PC Compta ne sont pas doublées. Le fichier est **refusé** s'il n'a pas
   le NIF de DPR AXXAM.

### Tableau de bord de gestion

Double-clic sur `tableau_de_bord.bat` (ou `./tableau_de_bord.sh`) : la page
`exports/TABLEAU_DE_BORD_AAAAMMJJ.html` s'ouvre dans le navigateur, sans Internet. Elle est
calculée sur le grand livre de l'ERP (écritures de PC Compta + saisies de l'ERP) :
chiffre d'affaires par mois et par compte de vente (70110 briqueterie en rouge brique),
créances clients, dettes fournisseurs, trésorerie par compte bancaire, TVA collectée et
déductible, droit de timbre, principaux clients de la briqueterie (codes PC Compta),
principales charges, et la date de la dernière écriture de chaque journal (un mois récent
peut être incomplet). Les chiffres ont été contrôlés contre un calcul indépendant fait
sur l'export de PC Compta.

### Dossier comptable : bilan, TCR, balances, grand livre, G50

Double-clic sur `dossier_comptable.bat` (ou `./dossier_comptable.sh`). Deux fichiers
arrivent dans `exports/` :

| Fichier | Contenu |
|---|---|
| `DOSSIER_COMPTABLE_2026_….xlsx` | Bilan actif (brut, amortissements, net), bilan passif, **TCR** par nature au format SCF, balance générale (soldes d'ouverture, mouvements, soldes), balance des tiers, grand livre complet, **préparation G50** mois par mois (TVA collectée, TVA déductible, timbre, IRG salaires, taxe de formation, acomptes IBS), feuille de contrôles |
| `ETATS_FINANCIERS_2026_….html` | Bilan, TCR et G50 en page imprimable (Ctrl+P → PDF), sans Internet |

Pour arrêter les états à une date : `dossier_comptable.bat --au=2026-09-30`.

Classement SCF : les comptes de tiers (40 à 48) et de trésorerie (51 à 58) vont à l'actif
ou au passif selon le **sens de leur solde** (un fournisseur débiteur est à l'actif, une
banque créditrice au passif). La feuille *Contrôles* vérifie la balance (débit = crédit),
actif = passif, résultat du TCR = classe 7 − classe 6, et qu'aucun compte n'est resté hors du bilan ou du TCR.

Sur les écritures 2026 (au 04/10/2026), le dossier donne : total du bilan
2 403 173 420,04 DA à l'actif et au passif, résultat provisoire 174 013 933,55 DA ;
la balance, la balance des tiers et la G50 ont été recalculées indépendamment sur l'export
PC Compta, sans écart.

> **À savoir.** Avant les écritures d'inventaire (stocks de fin d'exercice, amortissements
> et provisions de l'année, charges et produits constatés d'avance, IBS), le résultat est
> **provisoire**. La feuille G50 est une **aide** : vérifiez les montants et les rubriques
> avant de remplir la déclaration officielle.

La comptabilité légale (déclarations, bilan) reste tenue dans PC Compta ; l'ERP lui envoie
les écritures du quotidien et garde une copie complète et contrôlée de la comptabilité.

## Sauvegarde

Tout est dans le dossier `donnees/` (base de données, PDF, pièces jointes).
Faites une sauvegarde **chaque jour** et copiez-la hors du PC (clé USB, disque externe) :

- Windows : double-clic sur `sauvegarder.bat`
- Linux : `./sauvegarder.sh` (à planifier chaque soir avec `cron`)

Le fichier `sauvegardes/dolibarr-AAAA-MM-JJ_HHMM.sql` contient toute la base.
Pour la restaurer sur une installation neuve :

```bash
docker compose exec -T mariadb sh -c 'mariadb -u dolidbuser -p"$MARIADB_PASSWORD" dolidb' < sauvegardes/dolibarr-AAAA-MM-JJ_HHMM.sql
```

## Relancer le paramétrage

Le paramétrage briqueterie (`init/10-briqueterie.php`) s'applique tout seul au
premier démarrage. Il peut être relancé sans créer de doublons :

```bash
docker compose exec web php /var/www/scripts/docker-init.d/10-briqueterie.php
```

## Mise à jour de Dolibarr

Changez la version de l'image dans `docker-compose.yml` (par exemple
`dolibarr/dolibarr:24.0.2`), faites une sauvegarde, puis :

```bash
docker compose pull && docker compose up -d
```
