# Inbox — Webmail collaboratif natif pour Dolibarr

Inbox est un module personnalisé pour Dolibarr qui intègre un webmail complet directement dans votre ERP. Il se connecte à n'importe quelle boîte IMAP/SMTP et permet à votre équipe de lire, répondre, étiqueter et annoter des emails sans quitter Dolibarr.

---

## Fonctionnalités

- **Interface à 4 panneaux** — barre latérale des dossiers, liste des messages, vue email, panneau contexte ERP
- **Comptes multiples** — boîtes partagées (équipe) ou personnelles (par utilisateur)
- **Rendu HTML des emails** — affichage sécurisé dans une iframe sandbox
- **Blocage des images distantes** — images bloquées par défaut, révélables à la demande
- **Envoi et réponse** — avec délai d'annulation configurable avant l'envoi réel
- **Pièces jointes** — liste intégrée avec téléchargement en un clic
- **Déplacement vers la corbeille / suppression** — dossier de corbeille configurable par compte
- **Tags** — étiquettes définies par l'administrateur avec synchronisation facultative du mot-clé IMAP (visible depuis d'autres clients mail)
- **Commentaires internes** — notes d'équipe attachées à un message, jamais transmises au destinataire
- **Suivi lu/non-lu** — drapeau `\Seen` persisté sur le serveur IMAP
- **Navigation dans les dossiers** — parcourez tous les dossiers IMAP du compte
- **Actualisation automatique** — intervalle de polling configurable
- **OAuth2 / XOAUTH2** — authentification sans mot de passe pour Gmail et Microsoft Exchange Online

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

### 4 — Installation existante : appliquer la migration ALTER TABLE

Si vous mettez à jour depuis une version antérieure au support OAuth2, appliquez la migration de schéma manuellement :

```bash
# Docker (adaptez les identifiants si nécessaire)
docker compose exec -T mariadb mariadb -u root -p<motdepasse> <nombase> \
  -e "ALTER TABLE llx_inbox_account ADD COLUMN IF NOT EXISTS auth_type varchar(20) DEFAULT 'password' AFTER status;
      ALTER TABLE llx_inbox_account ADD COLUMN IF NOT EXISTS oauth_service varchar(50) DEFAULT '' AFTER auth_type;"
```

Ou exécutez directement le fichier `sql/llx_inbox_account-alter.sql` contre votre base de données.

---

## Configuration

Allez dans **Accueil → Configuration → Modules → Inbox** (ou cliquez sur l'icône clé à molette à côté du module).

### Comptes email

Chaque compte stocke les identifiants IMAP (réception) et SMTP (envoi).

| Champ | Description |
|---|---|
| Libellé | Nom affiché dans la barre latérale |
| Email | Adresse expéditeur pour les emails sortants |
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
4. **Créez ou éditez un compte inbox** : dans la page de configuration Inbox, choisissez `OAuth2` comme mode d'authentification, puis sélectionnez le fournisseur dans la liste déroulante. Le champ mot de passe est ignoré dans ce mode.

> La liste déroulante OAuth2 n'affiche que les fournisseurs déjà configurés avec des identifiants **et** disposant de scopes compatibles IMAP. Si la liste est vide, commencez par l'étape 2.

### Tags

Allez dans l'onglet **Tags** de la page de configuration Inbox. Les tags sont définis globalement par un administrateur et peuvent être assignés à n'importe quel message.

| Champ | Description |
|---|---|
| Libellé | Nom d'affichage du tag |
| Couleur | Code couleur hexadécimal (utilisé pour le badge dans l'interface) |
| Keyword IMAP | Mot-clé ASCII optionnel stocké sur le serveur IMAP (sans espace, synchronisé entre clients mail) |

### Paramètres globaux

| Paramètre | Défaut | Description |
|---|---|---|
| Délai d'annulation d'envoi | 10 s | Secondes avant l'envoi effectif — permet à l'utilisateur d'annuler |
| Intervalle d'actualisation automatique | 0 (désactivé) | Secondes entre deux actualisations automatiques de la liste (0 = désactivé) |
| Bloquer les images distantes | Oui | Bloque les images externes dans les emails par défaut |

---

## Schéma de base de données

Le module crée cinq tables (toutes préfixées par `llx_`) :

| Table | Rôle |
|---|---|
| `llx_inbox_account` | Identifiants et paramètres des comptes IMAP/SMTP |
| `llx_inbox_tag` | Tags définis par l'administrateur |
| `llx_inbox_message_tag` | Association many-to-many : tags par message |
| `llx_inbox_email` | Métadonnées de messages en cache (UID, sujet, expéditeur, drapeaux…) |
| `llx_inbox_comment` | Commentaires internes d'équipe attachés à un message |

---

## Permissions

| Clé de permission | ID | Défaut | Description |
|---|---|---|---|
| `inbox.read` | 104501 | **Oui** | Lire les emails et accéder au webmail |
| `inbox.write` | 104502 | Non | Envoyer des emails et gérer les comptes |
| `inbox.delete` | 104503 | Non | Supprimer ou déplacer des messages vers la corbeille |
| `inbox.setup` | 104504 | Non | Accéder aux pages de configuration du module |

---

## Présentation de l'interface

Le webmail est accessible depuis l'entrée de menu **Inbox** en haut de page. L'écran est divisé en quatre panneaux :

```
┌─────────────┬──────────────────┬──────────────────────────┬────────────────┐
│  Barre lat. │  Liste messages  │  Vue email               │  Contexte ERP  │
│             │                  │                          │                │
│ Boîtes      │ Lu / non-lu      │ Sujet, expéditeur, date  │ Pièces liées   │
│ Dossiers    │ Extrait sujet    │ Bandeau images distantes │                │
│             │ Date / tags      │ Corps HTML (sandboxé)    │ Commentaires   │
│             │                  │ Barre pièces jointes     │ internes       │
│             │                  │ Formulaire de réponse    │                │
└─────────────┴──────────────────┴──────────────────────────┴────────────────┘
```

**Barre latérale** — liste tous les dossiers IMAP du compte actif. Cliquez sur un dossier pour charger ses messages.

**Liste des messages** — affiche les messages avec indicateur lu/non-lu, expéditeur, sujet, date et tags assignés. Faites défiler pour charger davantage (pagination par offset).

**Vue email** — affiche le message sélectionné. Les images distantes sont bloquées par défaut ; un bandeau jaune permet à l'utilisateur de les révéler à la demande. Les pièces jointes apparaissent dans une barre sous le corps du message. Le formulaire de réponse s'affiche via le bouton Répondre.

**Contexte ERP** — panneau réservé à la liaison de documents Dolibarr (devis, factures, etc.) et à l'ajout de commentaires internes d'équipe sur un message.

---

## Notes techniques

### Bibliothèque IMAP

Le module utilise **`bytestream/horde-imap-client`** (v2.34) plutôt que l'extension PHP `imap`. Cette bibliothèque communique directement sur un socket TCP/TLS, supporte IMAP4rev1, et implémente `CONDSTORE`/`QRESYNC` pour une synchronisation delta efficace.

### XOAUTH2

Quand `auth_type = 'oauth2'`, la méthode `IMAPClient::connect()` appelle `resolveOAuthToken()`, qui :

1. Extrait le suffixe `keyforprovider` depuis `oauth_service` (ex. `GOOGLE-pro` → `keyforprovider = 'pro'`).
2. Charge le jeton stocké depuis `llx_oauth_token` via `DoliStorage`.
3. Rafraîchit le jeton d'accès s'il est expiré (en préservant le refresh token).
4. Retourne un objet `Horde_Imap_Client_Password_Xoauth2` passé au client Horde via le paramètre `xoauth2_token`.

### Envoi SMTP

Les emails sortants sont envoyés via la classe **`CMailFile`** de Dolibarr, qui gère la connexion SMTP, TLS et l'authentification nativement. Les identifiants SMTP du compte sont temporairement injectés dans `$conf->global` le temps de l'envoi.

### Suivi lu/non-lu

Les messages sont récupérés avec `peek => true` (ne marque pas automatiquement comme lu sur le serveur). Quand l'utilisateur clique sur un message, un appel AJAX fire-and-forget vers `ajax/mark_seen.php` envoie une commande IMAP `STORE` (`+FLAGS \Seen`).

### Blocage des images distantes

Avant d'injecter le HTML dans l'iframe sandboxée, JavaScript remplace les attributs `src="https://..."` par `data-original-src`. Un clic sur **Afficher les images** restaure les attributs `src` originaux via manipulation DOM à l'intérieur de l'iframe (autorisé par `allow-same-origin` dans le sandbox).

---

## Structure des fichiers

```
inbox/
├── admin/
│   ├── setup.php          # Gestion des comptes + paramètres globaux
│   └── tags.php           # Gestion des tags
├── ajax/
│   ├── get_folders.php    # Lister les dossiers IMAP
│   ├── get_emails.php     # Liste paginée des messages
│   ├── get_email_body.php # Corps complet du message + liste pièces jointes
│   ├── get_attachment.php # Télécharger une pièce jointe
│   ├── mark_seen.php      # Positionner le drapeau \Seen sur le serveur IMAP
│   ├── trash_email.php    # Déplacer vers la corbeille / supprimer
│   ├── send_email.php     # Envoyer / répondre via SMTP
│   ├── get_tags.php       # Lister tous les tags disponibles
│   ├── get_message_tags.php   # Tags d'un message donné
│   ├── add_message_tag.php    # Assigner un tag à un message
│   ├── remove_message_tag.php # Retirer un tag d'un message
│   ├── get_comments.php   # Commentaires internes d'un message
│   ├── add_comment.php    # Poster un commentaire interne
│   └── delete_comment.php # Supprimer un commentaire interne
├── class/
│   ├── imapclient.class.php      # Wrapper Horde IMAP + résolution token OAuth2
│   ├── inboxaccount.class.php    # ORM compte (fetch / create)
│   ├── inboxemail.class.php      # Métadonnées email en cache
│   ├── inboxtag.class.php        # ORM tag
│   ├── inboxmessagetag.class.php # ORM association tag-message
│   └── inboxcomment.class.php    # ORM commentaire interne
├── core/modules/
│   └── modInbox.class.php        # Descripteur de module (menus, permissions, tables)
├── css/
│   └── inbox.css                 # Styles de l'interface
├── js/
│   └── app.js                    # Logique frontend (single-page)
├── langs/
│   ├── en_US/inbox.lang          # Traductions anglaises
│   └── fr_FR/inbox.lang          # Traductions françaises
├── lib/
│   └── inbox.lib.php             # Helper onglets admin
├── sql/
│   ├── llx_inbox_account.sql          # Table comptes
│   ├── llx_inbox_tag.sql              # Table tags
│   ├── llx_inbox_message_tag.sql      # Table association tag-message
│   ├── llx_inbox_email.sql            # Table emails en cache
│   ├── llx_inbox_comment.sql          # Table commentaires
│   └── llx_inbox_account-alter.sql    # Migration : colonnes auth_type / oauth_service
├── vendor/                        # Dépendances Composer (non commitées)
├── composer.json
├── index.php                      # Page principale du webmail
├── README.md
└── README-FR.md
```

---

## Licence

Ce module est distribué sous la licence **GNU General Public License v3 (GPL-3.0)**, identique à celle de Dolibarr.
