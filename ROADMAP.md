# Roadmap: LogHub 0.1

Objectif de la 0.1 : une première version **installable par quelqu'un d'autre que nous**. Un binaire
(image Docker) + Postgres, un bundle Symfony sur Packagist, et une UI qui permet de trouver un log ou une
trace en quelques secondes sans lire le code.

Cible : self-hosted, une organisation, < 100 000 logs/jour (voir [specs/01-product-scope.md](specs/01-product-scope.md)).

## Déjà en place

- Engine Rust mono-binaire (port 8080) : ingestion logs (`/api/v1/logs`), OTLP traces (`/v1/traces`),
  API admin/recherche, UI React embarquée, rétention quotidienne.
- UI : login master key, Overview (histogramme, métriques), Logs (recherche, filtres `in`/`not_in`,
  facettes, attributs custom, colonnes, export CSV, panneau de détail), Traces (liste, waterfall,
  log → trace et trace → logs), Projects / API keys.
- Bundle `loghub/symfony-bundle` : handler Monolog bufferisé, enrichers, redaction, tracing OTel opt-in,
  client API et commandes `loghub:*`.

## Légende

- **P0** : bloquant pour taguer 0.1
- **P1** : attendu dans 0.1, peut glisser en 0.1.x
- **P2** : bonus si le temps le permet, sinon 0.2

---

## 1. Release et distribution (P0)

Sans ça, personne ne peut installer LogHub.

- [x] **CI GitHub Actions** : `cargo fmt --check`, `clippy -D warnings`, `cargo test` (service Postgres),
      `npm run typecheck` + tests Vitest + build UI, `phpunit` du bundle.
- [ ] **Image Docker publiée** (GHCR) multi-arch `amd64`/`arm64`, taguée `0.1.0` et `latest`, construite par la CI sur tag.
- [ ] **Bundle sur Packagist** : split du sous-dossier `packages/symfony-bundle` vers son propre dépôt
      (ou `splitsh`), contrainte de version `^0.1`, plus besoin du path repository.
- [ ] **Version unique** : `Cargo.toml`, `/health`, footer de l'UI et `composer.json` alignés ; `CHANGELOG.md`.
- [ ] **`compose.yaml` de production** documenté (image publiée au lieu de `build:`, `MASTER_KEY` obligatoire,
      volume Postgres, exemple de reverse proxy TLS Caddy/Traefik).
- [ ] **Doc d'upgrade** : les migrations tournent au démarrage, sauvegarde Postgres conseillée avant upgrade.

## 2. Onboarding (P0)

Le premier quart d'heure décide si l'outil est adopté.

- [ ] **Premier lancement guidé** : aucun projet → écran « Créer votre premier projet » → clé créée →
      snippet `loghub.yaml` + `monolog.yaml` + `.env.local` prêt à copier, avec la vraie URL et la clé.
- [ ] **États vides utiles** sur Logs / Traces / Overview : « Aucun log reçu pour ce projet » + lien
      vers le snippet d'installation, et « aucun résultat » distinct de « aucune donnée » (proposer
      d'élargir la période ou de retirer les filtres).
- [ ] **Indicateur « dernier log reçu »** par projet (liste Projects + détail) pour vérifier que l'ingestion marche.
- [ ] **Commande `loghub:test`** dans le bundle (portée depuis `demo/src/Command/TestLoghubCommand`) :
      envoie un log de test et affiche le résultat de l'engine (401, 413, OK...).

## 3. Fonctionnalités manquantes (P1)

Écarts entre les specs V1 et l'existant.

- [ ] **Auto-refresh** sur Logs, Traces et Overview : Off / 5 s / 10 s / 30 s / 1 min, dans l'URL,
      mis en pause quand l'onglet est caché ou le panneau de détail ouvert ([spec 7.4](specs/07-ui-ux.md)).
- [ ] **Gestion des projets** : renommer, supprimer (avec confirmation par saisie du nom, purge des logs et spans).
- [ ] **Rétention par projet** (spec 1.4) : colonne `retention_days` nullable sur `projects`, fallback sur
      `RETENTION_DAYS`, éditable dans l'UI.
- [ ] **Facettes traces** : `service.name`, `environment`, `status` sur la page Traces, comme pour les logs.
- [ ] **Page Settings** minimale : version, rétention globale, limites d'ingestion (lecture seule, depuis la config).
- [ ] **Vraie page 404** à la place du composant `Soon`.

## 4. UX : ajustements et confort (P1)

- [ ] **Navigation clavier dans la table des logs** : `j`/`k` ou flèches pour changer de ligne, `Entrée`
      pour ouvrir, `Échap` pour fermer, `/` pour focus la recherche ; aide `?` listant les raccourcis.
- [ ] **Navigation précédent/suivant** dans le panneau de détail sans le fermer.
- [ ] **Contexte d'un log** : « Voir les logs autour » (± 1 min, même service), filtres conservés.
- [ ] **Temps relatif + absolu** : « il y a 3 min » avec l'horodatage exact au survol ; choix UTC / heure locale.
- [ ] **Sélection de période sur l'histogramme** (brush) pour zoomer, + bouton « revenir à la période précédente ».
- [ ] **Partage** : bouton « Copier le lien » sur un log, une trace et une recherche (l'état est déjà dans l'URL).
- [ ] **Stacktraces lisibles** : frames repliées par défaut pour `vendor/`, chemins raccourcis.
- [ ] **Waterfall** : zoom sur une sous-partie, mise en évidence du chemin critique et des spans en erreur.
- [ ] **Persistance locale** des colonnes et de la largeur des colonnes par projet (`localStorage`).
- [ ] **Responsive** : vérifier Logs / Traces à 375 px (panneau de détail en plein écran, spec 7.9).
- [ ] **Accessibilité** : audit clavier + lecteur d'écran sur les combobox de filtres, contrastes des badges de sévérité.

## 5. Robustesse et sécurité (P1)

- [ ] **Rate limiting** sur les routes master key (anti brute force du login) et par clé projet sur l'ingestion.
- [ ] **Session UI** : bouton « Se déconnecter partout » impossible avec une master key unique, donc documenter
      clairement la rotation de `MASTER_KEY` et l'expiration côté UI (déconnexion après N jours d'inactivité).
- [ ] **Limites lisibles côté bundle** : en cas de 413/422, logguer dans `error_log` la limite renvoyée
      (`max_bytes`, `max_records`) et découper automatiquement le batch plutôt que de tout perdre.
- [ ] **Graceful shutdown** de l'engine vérifié (requêtes d'ingestion en cours terminées, purge interrompue proprement).
- [ ] **Logs structurés de l'engine** (`RUST_LOG`, format JSON optionnel) pour l'exploitation.

## 6. Performance (P2)

À traiter seulement si les mesures le justifient (cible < 100 000 logs/jour).

- [ ] **Jeu de données de bench** (1 M logs, 100 k spans) + script de mesure des requêtes types.
- [ ] **Recherche texte** : index `pg_trgm` sur `message` si l'`ILIKE` dépasse ~500 ms sur 7 jours.
- [ ] **Découverte des attributs** : aujourd'hui limitée aux 10 000 derniers logs, envisager un catalogue
      de clés alimenté à l'ingestion.
- [ ] **Partitionnement par jour** de `logs`/`spans` pour une purge en `DROP PARTITION` (spec 10.3).

## 7. Intégrations (P2)

- [ ] **OTLP logs** (`POST /v1/logs`, protobuf) : ouvre LogHub aux apps non-PHP sans nouveau SDK.
- [ ] **OTLP/JSON** pour `/v1/traces` (aujourd'hui `415`).
- [ ] **Span links** stockés et affichés (aujourd'hui ignorés).

---

## Hors scope 0.1

Gardés pour plus tard, l'architecture doit juste ne pas les bloquer ([specs/10-future-evolution.md](specs/10-future-evolution.md)) :

- comptes utilisateurs, rôles, organisations (la master key reste l'unique accès UI) ;
- alerting, dashboards personnalisés, métriques ;
- vues sauvegardées côté serveur, langage de requête type KQL ;
- live tail temps réel (SSE/Mercure) : l'auto-refresh couvre le besoin en 0.1 ;
- livraison durable côté bundle (spool disque, Messenger, Kafka) ;
- moteurs alternatifs (ClickHouse, OpenSearch).

## Critères de sortie 0.1

1. Un utilisateur qui ne connaît pas le projet installe l'engine depuis l'image publiée et le bundle
   depuis Packagist, et voit son premier log en **moins de 10 minutes** en suivant le README.
2. Tous les P0 sont faits, les P1 restants sont listés dans le CHANGELOG comme connus.
3. CI verte sur `main`, tag `v0.1.0`, image et paquet publiés.
4. Le parcours démo (`demo/`) marche de bout en bout : requête → logs → trace → logs liés.
