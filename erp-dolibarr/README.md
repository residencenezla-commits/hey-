# ERP Dolibarr — Briqueterie SARL DPR Axxam

Dolibarr est un logiciel de gestion (ERP) **open source et gratuit**, en français.
Ce dossier l'installe déjà paramétré pour la briqueterie : ventes, achats, stocks,
caisse et fabrication.

## Ce qui est prêt dès l'installation

| Élément | Paramétrage |
|---|---|
| Société | SARL DPR AXXAM, Algérie, devise **dinar (DZD)**, langue française, gérant Ahcene DJENNADI, adresses de l'usine et du siège, téléphone, e-mail |
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

Il faut un ordinateur ou un serveur toujours allumé, avec **Docker** installé.
Ce peut être un PC du bureau (accès depuis le réseau de l'usine) ou un serveur
loué chez un hébergeur (accès depuis partout).

```bash
cd erp-dolibarr
cp .env.exemple .env      # puis ouvrez .env et changez les 3 mots de passe
docker compose up -d      # premier démarrage : 2 à 3 minutes
docker compose logs -f web   # attendre « You can connect to the running Dolibarr »
```

Ouvrez ensuite `http://adresse-du-serveur:8080` et connectez-vous avec
l'identifiant `admin` et le mot de passe choisi dans `.env`.

> **Sécurité.** Si l'ERP est accessible depuis Internet, il doit passer par une
> adresse en **https** (certificat). Ne laissez jamais les mots de passe d'exemple.

## À compléter le premier jour

1. **Accueil › Configuration › Société/Organisation** : RC, NIF, AI (article
   d'imposition), NIS, capital, logo. Ils s'impriment sur les factures.
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

## Comptabilité

La comptabilité légale reste tenue dans votre logiciel actuel (PC Compta).
Dolibarr sert à la gestion de tous les jours ; les factures et règlements
s'exportent (Outils › Exports) pour le comptable.

## Sauvegarde

Tout est dans le dossier `donnees/` (base de données, PDF, pièces jointes).
Sauvegardez-le chaque jour, par exemple sur un disque externe :

```bash
docker compose exec mariadb mariadb-dump -u dolidbuser -p dolidb > sauvegarde-$(date +%F).sql
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
