# Éditions Passiflore — Site Web (Migration WordPress)

Ce dépôt contient uniquement le code personnalisé pour le nouveau site des Éditions Passiflore (migration PrestaShop → WordPress). Le core WordPress, les extensions tierces et les médias ne sont pas versionnés.

## Ce qui est dans ce dépôt

- `app/public/wp-content/themes/kadence-child/` — thème enfant (tout le code custom)
- `app/public/import-*.php`, `patch-photos-auteurs.php`, `supprimer-auteurs.php`, `merge_to_scf.py` — scripts d'import et de migration
- `app/public/*.csv` — données source issues de PrestaShop (livres, auteurs, redirections…)
- `app/public/tranches/` — images de tranches de livres
- `app/public/epubs/` — fichiers EPUB
- `app/public/redirections/` — mappings d'URL PrestaShop → WordPress
- `CLAUDE.md` — documentation du projet et feuille de route

Le fichier `wp-config.php` n'est **pas** versionné. Local by Flywheel le génère automatiquement à la création du site.

## Stack technique

- WordPress (core non versionné)
- WooCommerce + Kadence Theme (parent) + `kadence-child`
- Secure Custom Fields (SCF)
- Custom Post Type UI (taxonomie `auteur`)
- PHP 8.2 / Nginx (via Local by Flywheel)

## Installation locale

### Prérequis

- [Local by Flywheel](https://localwp.com/) installé
- Accès au dump SQL (`local.sql`, fourni séparément — voir [Sauvegarde de la base de données](#sauvegarde-de-la-base-de-données))
- Windows : [WSL](https://learn.microsoft.com/fr-fr/windows/wsl/) ou Git Bash pour exécuter le script

### Étapes

1. **Créer un nouveau site** dans Local by Flywheel — PHP 8.2, Nginx. Local installe WordPress et génère `wp-config.php` automatiquement.

2. **Cloner ce dépôt** par-dessus le dossier `app/public/` créé par Local :
   ```bash
   git clone <url-du-repo> "~/Local Sites/editions-passiflore"
   ```
   *(ou lier le dossier Git existant si le site Local est déjà créé)*

3. **Lancer le script d'installation** depuis le shell Local (*Open Site Shell*) :
   ```bash
   bash setup.sh
   ```
   Le script installe et active automatiquement tous les plugins requis, le thème Kadence (parent), puis active `kadence-child`.

   > **Autres environnements** : sur un serveur Linux, exécutez `bash setup.sh` depuis la racine du dépôt si WP-CLI est installé. Si WordPress est dans un sous-dossier différent : `bash setup.sh --wp-path <chemin>`.

4. **Importer la base de données** (`local.sql`) :
   - Local by Flywheel : onglet *Database* → *Import*
   - Ou en ligne de commande : `wp db import local.sql --path=app/public`

5. **Vérifier Custom Post Type UI** — s'assurer que la taxonomie `auteur` est bien présente dans CPT UI → Taxonomies. Si elle est absente après import, la recréer manuellement en suivant la spec dans `CLAUDE.md`.

### Plugins installés par le script

| Extension | Rôle |
|-----------|------|
| WooCommerce | Catalogue et e-commerce |
| Secure Custom Fields (SCF) | Champs personnalisés des livres et auteurs |
| Custom Post Type UI | Taxonomie `auteur` |
| Rank Math SEO | SEO |
| Redirection | Redirections PrestaShop → WordPress |
| WP All Import | Ré-import livres/auteurs depuis CSV |
| UpdraftPlus | Sauvegardes automatiques |
| WooCommerce Payments | Paiements |

## Sauvegarde de la base de données

Les dumps SQL sont exclus du dépôt Git (`.gitignore`). La stratégie de sauvegarde repose sur deux niveaux :

### Sauvegardes automatiques (production et dev)

**UpdraftPlus + Backblaze B2** est la combinaison recommandée :

- **Backblaze B2** : stockage objet S3-compatible, 10 Go gratuits, puis ~0,006 $/Go/mois — bien moins cher qu'AWS S3 ou Google Drive
- **UpdraftPlus** (déjà installé) : gère les sauvegardes planifiées vers B2 nativement, avec **chiffrement AES avant l'envoi**

Configuration dans UpdraftPlus → Réglages :
1. Destination : *S3-Compatible (Generic)* → entrer les identifiants Backblaze B2
2. Activer le **chiffrement** avec une phrase secrète forte (stockée dans votre gestionnaire de mots de passe — jamais dans ce dépôt)
3. Planification : hebdomadaire, conserver 4 copies

**Pourquoi c'est sécurisé :** les données sont chiffrées localement *avant* d'être envoyées vers B2. Même si le bucket était compromis, les sauvegardes seraient illisibles sans la phrase secrète.

> **Ne stockez jamais** les identifiants B2 ni la phrase secrète dans ce dépôt ou dans `wp-config.php` versionné.

### Partage du dump de développement

Le fichier `local.sql` (base locale) se partage ponctuellement via un canal sécurisé (iCloud Drive chiffré, Signal, AirDrop). Il ne doit pas circuler par email ou être déposé dans un service public.

## Documentation

La documentation complète du projet, le modèle de données et la feuille de route se trouvent dans [`CLAUDE.md`](CLAUDE.md). Ce fichier est également utilisé comme contexte par **Claude Code**.
