# Inbox — Hub de messagerie collaboratif natif pour Dolibarr

Inbox est un module personnalisé pour Dolibarr qui intègre un hub de messagerie complet directement dans votre ERP. Il se connecte à n'importe quelle boîte IMAP/SMTP ou compte WhatsApp Business, et permet à votre équipe de lire, répondre, étiqueter et annoter des messages sans quitter Dolibarr.

---

## Fonctionnalités

- **Interface à 4 panneaux** — barre latérale, liste des messages, vue message, panneau contexte ERP
- **Comptes multiples** — boîtes partagées (équipe) ou personnelles (par utilisateur)
- **Architecture multi-provider** — IMAP/SMTP, WhatsApp Business Cloud API ; extensible à Graph, Gmail, SMS…
- **Rendu HTML des emails** — affichage sécurisé dans une iframe sandbox
- **Blocage des images distantes** — images bloquées par défaut, révélables à la demande
- **Envoi et réponse** — avec délai d'annulation configurable avant l'envoi réel
- **Pièces jointes** — liste intégrée avec téléchargement en un clic
- **Déplacement vers la corbeille / suppression** — dossier de corbeille configurable par compte
- **Tags** — étiquettes définies par l'administrateur avec synchronisation facultative du mot-clé IMAP
- **Commentaires internes** — notes d'équipe attachées à un message, jamais transmises au destinataire
- **Suivi lu/non-lu** — drapeau `\Seen` persisté sur le serveur IMAP (ou en base pour WhatsApp)
- **Navigation dans les dossiers** — parcourez tous les dossiers IMAP du compte
- **Actualisation automatique** — intervalle de polling configurable
- **OAuth2 / XOAUTH2** — authentification sans mot de passe pour Gmail et Microsoft Exchange Online
- **WhatsApp Business** — réception via webhook Meta, envoi de messages texte, téléchargement des médias

---

## Prérequis

| Composant | Version |
|---|---|
| Dolibarr | 17.0 ou supérieur |
| PHP | 7.4 ou supérieur (8.1+ recommandé) |
| Composer | toute version récente |
| MariaDB / MySQL | 5.7 / 10.3 ou supérieur |
| Extension PHP `imap` | **non requise** (bibliothèque socket Horde utilisée à la place) |

---

## Installation

### 1 — Copier le module

Placez le répertoire `inbox/` dans `htdocs/custom/` :

```
dolibarr/htdocs/custom/inbox/
```

### 2 — Installer les dépendances PHP

```bash
cd htdocs/custom/inbox
composer install --no-dev
```

Cela installe `bytestream/horde-imap-client` (v2.34), la seule dépendance d'exécution. L'extension PHP `imap` n'est pas nécessaire.

### 3 — Activer le module

Connectez-vous en tant qu'administrateur Dolibarr, allez dans **Accueil → Configuration → Modules/Applications**, trouvez **Inbox** (famille CRM) et cliquez sur **Activer**.

L'activation du module crée automatiquement les tables de base de données nécessaires.

### 4 — Appliquer les migrations de schéma (mises à jour)

Lors d'une mise à jour depuis une ancienne version, appliquez les migrations manquantes du dossier `sql/` :

```bash
# Docker — adaptez les identifiants si nécessaire
docker compose exec -T mariadb mariadb -u <user> -p<motdepasse> <base> \
  < htdocs/custom/inbox/sql/alter_inbox_account_add_provider_type.sql

docker compose exec -T mariadb mariadb -u <user> -p<motdepasse> <base> \
  < htdocs/custom/inbox/sql/alter_inbox_account_add_config.sql

docker compose exec -T mariadb mariadb -u <user> -p<motdepasse> <base> \
  < htdocs/custom/inbox/sql/create_inbox_whatsapp_message.sql
```

---

## Configuration

Allez dans **Accueil → Configuration → Modules → Inbox** (ou cliquez sur l'icône clé à molette à côté du module).

### Comptes email (IMAP/SMTP)

Chaque compte stocke les identifiants IMAP (réception) et SMTP (envoi).

| Champ | Description |
|---|---|
| Libellé | Nom affiché dans la barre latérale |
| Email | Adresse expéditeur pour les messages sortants |
| Type de provider | `imap` (défaut), `whatsapp` |
| Partagé | Si activé, le compte est visible par tous les utilisateurs internes |
| Serveur / port / sécurité IMAP | Paramètres du serveur de réception |
| Serveur / port / sécurité SMTP | Paramètres du serveur d'envoi |
| Login | Identifiant IMAP/SMTP (généralement l'adresse email) |
| Mode d'authentification | `Mot de passe` (standard) ou `OAuth2` (voir ci-dessous) |
| Limite synchro — nombre | Nombre maximum de messages à récupérer par actualisation (défaut : 500) |
| Limite synchro — jours | Ne récupérer que les messages plus récents que N jours (défaut : 180) |

### Authentification OAuth2 (Gmail / Microsoft Exchange Online)

OAuth2 permet à Dolibarr de se connecter à IMAP sans stocker de mot de passe. Le jeton d'accès est rafraîchi automatiquement.

**Fournisseurs supportés :**

| Fournisseur | Clé de service Dolibarr | Scope IMAP requis |
|---|---|---|
| Google (Gmail) | `GOOGLE` | `https://mail.google.com/` (abrégé : `gmail_full`) |
| Microsoft Exchange Online | `MICROSOFT3` | `https://outlook.office.com/IMAP.AccessAsUser.All` |

**Procédure de configuration :**

1. **Enregistrez une application OAuth2** chez Google ou Microsoft (les liens sont fournis sur la page OAuth2 de Dolibarr).
2. **Configurez le fournisseur** dans Dolibarr : **Accueil → Configuration → Services OAuth2** (`/admin/oauth.php`). Saisissez le Client ID, le Client Secret, et sélectionnez les scopes requis.
3. **Autorisez le jeton** : sur la même page OAuth2, cliquez sur le lien d'autorisation de votre fournisseur et terminez le flux OAuth2. Dolibarr stocke le jeton dans `llx_oauth_token`.
4. **Créez ou éditez un compte inbox** : dans la page de configuration Inbox, choisissez `OAuth2` comme mode d'authentification, puis sélectionnez le fournisseur dans la liste déroulante.

> La liste déroulante OAuth2 n'affiche que les fournisseurs déjà configurés avec des identifiants **et** disposant de scopes compatibles IMAP. Si la liste est vide, commencez par l'étape 2.

### WhatsApp Business Cloud API

Les comptes WhatsApp reçoivent les messages via un webhook Meta (modèle push) et envoient via l'API Graph. Les messages sont stockés localement dans `llx_inbox_whatsapp_message`.

#### 1 — Créer une application Meta

1. Rendez-vous sur [developers.facebook.com](https://developers.facebook.com), créez une application de type **Business**.
2. Ajoutez le produit **WhatsApp** et configurez un **Numéro de téléphone** dans la plateforme WhatsApp Business.
3. Générez un **System User Token** (permanent) avec la permission `whatsapp_business_messaging`.
4. Notez le **Phone Number ID** depuis la page WhatsApp → Getting Started.

#### 2 — Créer un compte WhatsApp dans Dolibarr

Allez dans **Accueil → Configuration → Modules → Inbox**, cliquez sur **Ajouter un compte**, puis sélectionnez **WhatsApp Business Cloud API** comme type de provider.

| Champ | Description |
|---|---|
| Libellé | Nom affiché dans la barre latérale |
| Phone Number ID | Phone Number ID issu de la Meta Developer Console (WhatsApp → Getting Started) |
| Jeton d'accès | Jeton permanent du System User avec la permission `whatsapp_business_messaging` |
| Verify Token | Secret arbitraire de votre choix — doit correspondre à ce que vous entrez dans la configuration webhook Meta |
| Version API | Optionnel — version de l'API Graph, défaut `v20.0` |

Enregistrez le compte. L'URL du webhook est affichée sur le formulaire d'édition une fois le compte sauvegardé.

#### 3 — Configurer le webhook Meta

Dans Meta Developer Console → WhatsApp → Configuration :

- **Callback URL** : l'URL du webhook affichée sur le formulaire d'édition du compte (format : `https://votre-domaine/custom/inbox/ajax/whatsapp_webhook.php?account_id=N`)
- **Verify Token** : la valeur de `verify_token` du config JSON
- **Webhook fields** : souscrivez à `messages`

#### 4 — Tester

Envoyez un message WhatsApp à votre numéro professionnel. Il devrait apparaître dans l'Inbox en quelques secondes.

### Tags

Allez dans l'onglet **Tags** de la page de configuration Inbox. Les tags sont des définitions globales (portée entité Dolibarr) — ils ne sont pas liés à un compte spécifique et sont disponibles pour tous les comptes.

| Champ | Description |
|---|---|
| Libellé | Nom d'affichage du tag |
| Couleur | Code couleur hexadécimal (utilisé pour le badge dans l'interface) |
| Keyword IMAP | Mot-clé ASCII optionnel stocké sur le serveur IMAP (ignoré pour WhatsApp) |

Les *assignations* de tags (quel tag est sur quel message) sont stockées par compte dans `llx_inbox_message_tag`.

> **Compatibilité de la synchronisation keyword IMAP :** les keywords IMAP sont ignorés silencieusement pour les comptes WhatsApp. Gmail ne supporte pas non plus les keywords utilisateur définis — les tags s'affichent bien dans Dolibarr mais ne sont pas synchronisés dans l'interface Gmail. La synchronisation fonctionne de manière fiable sur Dovecot, Cyrus et Exchange.

### Paramètres globaux

| Paramètre | Défaut | Description |
|---|---|---|
| Délai d'annulation d'envoi | 10 s | Secondes avant l'envoi effectif — permet à l'utilisateur d'annuler |
| Intervalle d'actualisation automatique | 0 (désactivé) | Secondes entre deux actualisations automatiques de la liste (0 = désactivé) |
| Bloquer les images distantes | Oui | Bloque les images externes dans les emails par défaut |

---

## Schéma de base de données

| Table | Rôle |
|---|---|
| `llx_inbox_account` | Identifiants et paramètres de tous les comptes (tous providers) |
| `llx_inbox_tag` | Tags définis par l'administrateur |
| `llx_inbox_message_tag` | Association many-to-many : tags par message |
| `llx_inbox_email` | Métadonnées IMAP en cache (UID, sujet, expéditeur, drapeaux…) |
| `llx_inbox_comment` | Commentaires internes d'équipe attachés à un message |
| `llx_inbox_whatsapp_message` | Stockage local des messages WhatsApp (entrants et sortants) |

### `llx_inbox_account` — colonnes notables

| Colonne | Type | Description |
|---|---|---|
| `provider_type` | `VARCHAR(30)` | `imap` (défaut) ou `whatsapp` |
| `config` | `TEXT` | Blob JSON pour les paramètres spécifiques au provider (WhatsApp : phone_number_id, access_token…) |
| `auth_type` | `VARCHAR(20)` | `password` ou `oauth2` (IMAP uniquement) |
| `oauth_service` | `VARCHAR(50)` | Clé de service OAuth2 Dolibarr (IMAP uniquement) |

### `llx_inbox_whatsapp_message`

| Colonne | Description |
|---|---|
| `wamid` | Identifiant Meta du message (`wamid.ABC…`) — unique par compte |
| `direction` | `0` = entrant, `1` = sortant |
| `from_phone` / `from_name` | Expéditeur (entrant) |
| `to_phone` | Destinataire (sortant) |
| `msg_type` | `text`, `image`, `document`, `audio`, `video`, `sticker`, `location` |
| `body` | Corps texte ou légende |
| `media_id` | Identifiant de l'objet média Meta (pour les messages non-texte) |
| `status` | `received`, `delivered`, `read`, `sent`, `failed`, `deleted` |

---

## Permissions

| Clé de permission | ID | Défaut | Description |
|---|---|---|---|
| `inbox.read` | 104501 | **Oui** | Lire les messages et accéder au hub |
| `inbox.write` | 104502 | Non | Envoyer des messages et gérer les comptes |
| `inbox.delete` | 104503 | Non | Supprimer ou déplacer des messages vers la corbeille |
| `inbox.setup` | 104504 | Non | Accéder aux pages de configuration du module |

---

## Présentation de l'interface

Le hub de messagerie est accessible depuis l'entrée de menu **Inbox** en haut de page. L'écran est divisé en quatre panneaux :

```
┌─────────────┬──────────────────┬──────────────────────────┬────────────────┐
│  Barre lat. │  Liste messages  │  Vue message             │  Contexte ERP  │
│             │                  │                          │                │
│ Comptes     │ Lu / non-lu      │ Sujet, expéditeur, date  │ Pièces liées   │
│ Dossiers /  │ Extrait          │ Bandeau images distantes │                │
│ Contacts    │ Date / tags      │ Corps du message         │ Commentaires   │
│             │                  │ Barre pièces jointes     │ internes       │
│             │                  │ Formulaire de réponse    │                │
└─────────────┴──────────────────┴──────────────────────────┴────────────────┘
```

**Barre latérale** — liste les comptes et, pour les comptes IMAP, tous leurs dossiers. Pour les comptes WhatsApp, un dossier virtuel **WhatsApp** est affiché.

**Liste des messages** — affiche les messages avec indicateur lu/non-lu, expéditeur, sujet/aperçu, date et tags assignés. Faites défiler pour charger davantage.

**Vue message** — affiche le message sélectionné. Les images distantes sont bloquées par défaut ; un bandeau jaune permet de les révéler. Les pièces jointes apparaissent dans une barre sous le corps. Le formulaire de réponse s'affiche via le bouton Répondre.

**Contexte ERP** — panneau réservé à la liaison de documents Dolibarr (devis, factures, etc.) et à l'ajout de commentaires internes d'équipe.

---

## Notes techniques

### Abstraction provider

Toute la logique spécifique à chaque protocole est isolée derrière **`InboxProviderInterface`** (`class/InboxProviderInterface.php`). La couche AJAX est agnostique au provider : chaque endpoint appelle `InboxProviderFactory::create($account)` et travaille exclusivement via l'interface.

```
InboxProviderInterface
        │
        ├── ImapProvider        (encapsule IMAPClient / Horde IMAP)
        └── WhatsAppProvider    (Meta Graph API + base de données locale)
        (futur : GraphProvider, GmailProvider, SmsProvider…)
```

**Ajouter un nouveau provider :**
1. Créez `class/MonProvider.php` implémentant `InboxProviderInterface`.
2. Ajoutez un `case 'monprovider':` dans `InboxProviderFactory::create()`.
3. Insérez des comptes avec `provider_type = 'monprovider'` et un blob JSON `config`.

### Bibliothèque IMAP

Le provider IMAP utilise **`bytestream/horde-imap-client`** (v2.34) plutôt que l'extension PHP `imap`. Cette bibliothèque communique directement sur un socket TCP/TLS, supporte IMAP4rev1, et implémente `CONDSTORE`/`QRESYNC` pour une synchronisation delta efficace.

### XOAUTH2

Quand `auth_type = 'oauth2'`, `IMAPClient::connect()` appelle `resolveOAuthToken()`, qui :

1. Extrait le suffixe `keyforprovider` depuis `oauth_service` (ex. `GOOGLE-pro` → `keyforprovider = 'pro'`).
2. Charge le jeton stocké depuis `llx_oauth_token` via `DoliStorage`.
3. Rafraîchit le jeton d'accès s'il est expiré (en préservant le refresh token).
4. Retourne un objet `Horde_Imap_Client_Password_Xoauth2` passé au client Horde.

### Envoi de messages

- **Comptes IMAP** : les emails sortants sont envoyés via la classe **`CMailFile`** de Dolibarr (SMTP). Les identifiants SMTP du compte sont temporairement injectés dans `$conf->global` le temps de l'envoi. Une copie est ajoutée au dossier Envoyés via IMAP `APPEND`.
- **Comptes WhatsApp** : `send_email.php` détecte `provider_type = 'whatsapp'`, contourne entièrement SMTP et appelle `WhatsAppProvider::sendTextMessage()`, qui poste sur `/{phone_number_id}/messages` de l'API Graph et stocke le message sortant dans `llx_inbox_whatsapp_message`.

### Webhook WhatsApp

`ajax/whatsapp_webhook.php` est un endpoint `NOLOGIN` (aucune session Dolibarr requise). Il gère :

- **GET** — handshake de vérification Meta : retourne `hub.challenge` si `hub.verify_token` correspond à la valeur dans le JSON `config` du compte.
- **POST** — événements de messages entrants et mises à jour de statut. Tous les types de messages sont supportés (`text`, `image`, `document`, `audio`, `video`, `sticker`, `location`). Les mises à jour de statut (`delivered`, `read`, `failed`) sont propagées dans `llx_inbox_whatsapp_message.status`.

### Suivi lu/non-lu

- **IMAP** : les messages sont récupérés avec `peek => true`. Quand l'utilisateur ouvre un message, un appel AJAX fire-and-forget envoie une commande IMAP `STORE +FLAGS \Seen`.
- **WhatsApp** : `markSeen()` met à jour `status = 'read'` en base de données **et** envoie un accusé de lecture à Meta (`POST /{phone_number_id}/messages` avec `status: read`).

### Blocage des images distantes

Avant d'injecter le HTML dans l'iframe sandboxée, JavaScript remplace les attributs `src="https://..."` par `data-original-src`. Un clic sur **Afficher les images** restaure les attributs `src` originaux via manipulation DOM à l'intérieur de l'iframe.

---

## Structure des fichiers

```
inbox/
├── admin/
│   ├── setup.php                      # Gestion des comptes + paramètres globaux
│   └── tags.php                       # Gestion des tags
├── ajax/
│   ├── get_folders.php                # Lister les dossiers / inbox virtuelle
│   ├── get_emails.php                 # Liste paginée des messages (plate ou threadée)
│   ├── get_email_body.php             # Corps complet + liste pièces jointes
│   ├── get_attachment.php             # Télécharger une pièce jointe / média
│   ├── mark_seen.php                  # Marquer lu / non-lu
│   ├── trash_email.php                # Déplacer vers la corbeille / supprimer
│   ├── send_email.php                 # Envoyer via SMTP (email) ou Graph API (WhatsApp)
│   ├── whatsapp_webhook.php           # Webhook Meta : vérification + événements entrants
│   ├── get_tags.php                   # Lister tous les tags disponibles
│   ├── get_message_tags.php           # Tags d'un message donné
│   ├── add_message_tag.php            # Assigner un tag à un message
│   ├── remove_message_tag.php         # Retirer un tag d'un message
│   ├── get_unread_counts.php          # Compteur non-lus par compte
│   ├── get_comments.php               # Commentaires internes d'un message
│   ├── add_comment.php                # Poster un commentaire interne
│   └── delete_comment.php             # Supprimer un commentaire interne
├── class/
│   ├── InboxProviderInterface.php     # Contrat pour tous les providers de messagerie
│   ├── InboxProviderFactory.php       # Instancie le provider adapté au compte
│   ├── ImapProvider.php               # Provider IMAP/SMTP (encapsule IMAPClient)
│   ├── WhatsAppProvider.php           # Provider WhatsApp Business Cloud API
│   ├── imapclient.class.php           # Wrapper Horde IMAP + résolution token OAuth2
│   ├── inboxaccount.class.php         # ORM compte (fetch / create)
│   ├── inboxemail.class.php           # Métadonnées email en cache
│   ├── inboxtag.class.php             # ORM tag
│   ├── inboxmessagetag.class.php      # ORM association tag-message
│   └── inboxcomment.class.php         # ORM commentaire interne
├── core/modules/
│   └── modInbox.class.php             # Descripteur de module (menus, permissions, tables)
├── css/
│   └── inbox.css                      # Styles de l'interface
├── js/
│   └── app.js                         # Logique frontend (single-page)
├── langs/
│   ├── en_US/inbox.lang               # Traductions anglaises
│   └── fr_FR/inbox.lang               # Traductions françaises
├── lib/
│   └── inbox.lib.php                  # Helper onglets admin
├── sql/
│   ├── llx_inbox_account.sql                      # Table comptes (initiale)
│   ├── llx_inbox_tag.sql                          # Table tags
│   ├── llx_inbox_message_tag.sql                  # Table association tag-message
│   ├── llx_inbox_email.sql                        # Table emails en cache
│   ├── llx_inbox_comment.sql                      # Table commentaires
│   ├── llx_inbox_account-alter.sql                # Migration : auth_type / oauth_service
│   ├── alter_inbox_account_add_provider_type.sql  # Migration : colonne provider_type
│   ├── alter_inbox_account_add_config.sql         # Migration : colonne config JSON
│   └── create_inbox_whatsapp_message.sql          # Table messages WhatsApp
├── vendor/                            # Dépendances Composer (non commitées)
├── composer.json
├── index.php                          # Page principale du hub de messagerie
├── README.md
└── README-FR.md
```

---

## Licence

Ce module est distribué sous la licence **GNU General Public License v3 (GPL-3.0)**, identique à celle de Dolibarr.
