# FantasyRealm Online — Back

API de l'application **FantasyRealm Online** (gestion de personnages, accessoires, comptes joueurs
et employeurs, favoris, contact…).
C'est la partie **back** du projet : une API **Symfony** sécurisée par **JWT**, avec une base
**MySQL** pour les données métier et une base **MongoDB** pour le journal d'activité (logs).
Tout tourne dans **Docker**, pas besoin d'installer PHP ni MySQL sur la machine.

Le front qui consomme cette API est dans le repo **FantasyRealmFront**.

## Stack

- **Symfony 7.4** / **PHP 8.3**
- **MySQL 8** (données métier) via **Doctrine ORM** + migrations
- **MongoDB** (journal d'activité / logs)
- **JWT** (`lexik/jwt-authentication-bundle`) pour l'authentification
- **NelmioCorsBundle** pour le CORS, **NelmioApiDocBundle** pour la doc API
- **Mailpit** pour tester les mails en local
- **PHPUnit** pour les tests
- **Docker Compose** pour tout l'environnement local
- Déploiement sur **Railway**

## Prérequis

- **Docker Desktop** → [installation officielle](https://www.docker.com/products/docker-desktop/)
  (la commande `docker compose` est déjà incluse, rien à installer en plus).

C'est tout : PHP, Composer, MySQL et MongoDB tournent dans les conteneurs.

## Installation en local

Toutes les commandes se lancent depuis la racine du projet.

### 1. Démarrer les conteneurs

```bash
docker compose up -d --build
```

Cela lance : `php`, `nginx`, `mysql`, `phpmyadmin`, `mongo` et `mailer` (Mailpit).

### 2. Installer les dépendances PHP

Les dépendances (`vendor/`) ne sont pas versionnées, il faut les installer **dans le conteneur php** :

```bash
docker compose exec php composer install
```

### 3. Générer les clés JWT

Les clés (`config/jwt/*.pem`) sont ignorées par Git, il faut donc les générer.
La passphrase est déjà fournie dans `.env.dev` (fichier versionné), la commande la lit toute seule :

```bash
docker compose exec php php bin/console lexik:jwt:generate-keypair --skip-if-exists
```

### 4. Jouer les migrations de base de données

Crée toutes les tables dans la base `FantasyRealmBDD` :

```bash
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```

L'API est prête sur **http://localhost:8080**. 🎉

## Services accessibles en local

| Service              | URL / Port                | Rôle                                      |
| -------------------- | ------------------------- | ----------------------------------------- |
| API (nginx)          | http://localhost:8080     | Point d'entrée de l'API                   |
| phpMyAdmin           | http://localhost:8081     | Interface pour la base MySQL              |
| Mailpit              | http://localhost:8025     | Boîte mail de test (mails sortants)       |
| MySQL                | localhost:3307            | Base de données métier                    |
| MongoDB              | localhost:27017           | Journal d'activité (logs)                 |

> Identifiants MySQL en local : utilisateur `root`, mot de passe `root`, base `FantasyRealmBDD`
> (voir `docker-compose.yml`).

## Variables d'environnement

Symfony charge les fichiers `.env` en cascade :

- **`.env`** — valeurs par défaut (versionné). C'est ici que sont configurés `DATABASE_URL`,
  `MONGODB_URL`, `MAILER_DSN`, etc. Les noms d'hôtes correspondent aux conteneurs Docker
  (ex. `mysql`, `mongo`, `mailer`).
- **`.env.dev`** — secrets d'environnement de dev (versionné) : `APP_SECRET` et `JWT_PASSPHRASE`.
  Pour un projet réel ces valeurs ne seraient pas commitées, mais elles le sont ici pour que le
  correcteur puisse lancer le projet sans configuration manuelle.
- **`.env.local`** — surcharges locales éventuelles (ignoré par Git).

Aucune configuration manuelle n'est nécessaire pour démarrer en local.

## Tests

Les tests tournent aussi dans le conteneur php :

```bash
docker compose exec php php bin/console doctrine:database:create --env=test --if-not-exists
docker compose exec php php bin/console doctrine:migrations:migrate --env=test --no-interaction
docker compose exec php vendor/bin/phpunit
```

## Structure du projet

```
FantasyRealmBack/
├── src/
│   ├── Controller/Api/   # Contrôleurs de l'API (Auth, Character, Accessory, Favorite…)
│   ├── Entity/           # Entités Doctrine (User, Character, Accessory, Comment)
│   ├── Repository/       # Repositories Doctrine
│   └── Service/          # Services métier (ActivityLogger → logs MongoDB)
├── config/               # Configuration Symfony (packages, routes, jwt…)
├── migrations/           # Migrations Doctrine (schéma MySQL)
├── docker/               # entrypoint, nginx et supervisord pour l'image de prod
├── nginx/                # Config nginx pour le dev
├── tests/                # Tests PHPUnit
├── docker-compose.yml    # Environnement de dev
├── Dockerfile            # Image PHP de dev
├── Dockerfile.prod       # Image de prod (Railway)
└── README.md
```

## Déploiement

Le back est déployé sur **Railway** à partir de `Dockerfile.prod`.
Au démarrage, l'`entrypoint` (`docker/entrypoint.sh`) génère les clés JWT si besoin, vide le cache
et joue les migrations automatiquement. La config Railway est dans `railway.json`.
