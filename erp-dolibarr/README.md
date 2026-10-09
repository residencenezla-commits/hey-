# ERP Dolibarr — Briqueterie SARL DPR Axxam

Dolibarr est un logiciel de gestion (ERP) **open source et gratuit**, en français.
Ce dossier l'installe déjà paramétré pour la briqueterie : ventes, achats, stocks,
caisse et fabrication.

## Ce qui est prêt dès l'installation

| Élément | Paramétrage |
|---|---|
| Société | SARL DPR AXXAM, capital 294 000 000 DA, RC 08B0185858-06/00, NIF 000806018585831, siège Village Tizi, Tissa, 06020 Semaoun (Béjaïa), usine ZAC Helouane ; devise **dinar (DZD)**, langue française, gérant Ahcene DJENNADI |
| Clients et fournisseurs | **421 tiers repris de PC Compta** (282 clients, 139 fournisseurs) avec leur code (C005, FL012…), compte général (41100, 40101…), RC, NIF, AI et adresse ; solde au 08/10/2026 en note privée ; une catégorie par compte |
| Factures | RC, NIF et AI du client imprimés sous son adresse ; capital, RC et NIF de DPR AXXAM en pied de page |
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

## Comptabilité

La comptabilité légale reste tenue dans votre logiciel actuel (PC Compta).
Dolibarr sert à la gestion de tous les jours ; les factures et règlements
s'exportent (Outils › Exports) pour le comptable.

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
