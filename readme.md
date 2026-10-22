Et que voilà un descriptif détaillé du code source de votre fichier `index.php` :

### Introduction

Ce fichier PHP est le fichier public du site web, gérant l'affichage des données et les fonctionnalités principales. 
Il gère l'authentification des utilisateurs, la gestion des créneaux, l'appel à l'API YouBook et la présentation des produits disponibles.

### Définitions Principales

- **Sessions**: La session est initialisée pour stocker les informations de l'utilisateur connecté, ce qui permet de vérifier l'état de connexion.
- **Connexion à la Base de Données**: Utilise la base de données `youbook` pour accéder aux données des produits, créneaux et utilisateurs.
- **Variables de Session**:
  - `$_SESSION['user_logged']`: Détermine si un utilisateur est connecté.
  - `$_SESSION['user_id']`: L'identifiant unique de l'utilisateur connecté.
  - `$_SESSION['user_firstname']`, `$_SESSION['user_lastname']`, `$_SESSION['user_email']`: Les informations de profil de l'utilisateur connecté.
- **Variables de Gestion de l'Authentification**:
  - `$action`: La méthode d'authentification (connexion, inscription ou déconnexion).
  - `$authError`: Message d'erreur en cas d'erreur d'authentification.
  - `$currentUser`: Contient les informations de l'utilisateur connecté si connecté.
- **Date et Créneaux**:
  - `$ladate`: Date du jour formattée.
  - `$schedulesByProduct`: Tableau associatif contenant tous les créneaux triés par produit.

### Gestion de l'Authentification

Le code gère les actions d'authentification en fonction de l'action passée via `$_POST['auth_action']`. Les actions possibles sont :
- **Connexion**: Vérifie les informations d'identification et stocke les informations de l'utilisateur connecté.
- **Inscription**: Insère un nouvel utilisateur dans la base de données et stocke ses informations.
- **Déconnexion**: Supprime les informations de l'utilisateur de la session.

### Chargement des Crèneaux

1. **Récupération des Crèneaux**:
   - Utilise une requête SQL pour récupérer tous les créneaux futurs (event_date >= today).
   - Les créneaux sont organisés dans un tableau associatif `schedulesByProduct` par ID de produit.

### Chargement des Produits de l'API YouBook

1. **Appel à l'API**:
   - Utilise `cURL` pour effectuer une requête POST à l'API YouBook pour obtenir une liste de produits.
   - Les produits sont filtrés et triés par ordre aléatoire.

2. **Formattage des Produits**:
   - Utilise `array_map` pour formatter chaque produit en prenant en compte les informations du tableau `schedulesByProduct`.
   - Calcul du stock réel en ajoutant les stocks des créneaux associés à chaque produit.

### Affichage des Produits

- Le code génère un bloc HTML pour chaque produit, incluant l'image, le nom, le prix, les options et les créneaux disponibles.
- Les produits sont triés et affichés dans une grille dynamique avec pagination.

### Fonctions et Fonctions d'Assistance

- `date('d/m/Y')`: Formate la date en français.
- `trim()`, `substr()`, `ereg_replace()`: Utilisés pour formater et nettoyer les données.
- `curl_init()`, `curl_setopt()`, `curl_exec()`, `curl_close()`: Utilisés pour interagir avec l'API YouBook.
- `date('n')`, `date('n')]`, `date('Y')`: Utilisés pour obtenir le mois, l'année et la date courante.

### Autres Fonctions et Fonctions d'Assistance

- `rand(100, 999)`: Utilisée pour générer un ID unique pour chaque produit.
- `htmlspecialchars()`: Utilisée pour prévenir les attaques XSS.
- `strip_tags()`: Utilisée pour retirer les balises HTML des descriptions.
- `strtolower()`, `strtoupper()`: Utilisées pour formater les noms et titres des produits.

Ce fichier `index.php` est le point central de votre site web, gérant l'authentification des utilisateurs, la gestion des créneaux, l'appel à l'API YouBook et la présentation des produits disponibles.


# Le youscript.js — Catalogue interactif d'activités touristiques

## Le descriptif du fichier ;)

`youscript.js` est un script JavaScript destiné à enrichir l'interface d'un catalogue d'activités touristiques pour un site web d'Office de Tourisme.

Il permet d'afficher et de manipuler des activités provenant d'une source de données PHP, avec une interface interactive comprenant un catalogue, un panier, des favoris, une recherche instantanée et des fiches détaillées.

Le script a été conçu selon une architecture modulaire orientée composants, avec une gestion centralisée de l'état, un système d'événements personnalisé et une sauvegarde locale des préférences de navigation.

## Les Fonctionnalités principales !!

### Catalogue d'activités
- Affichage dynamique des activités sous forme de cartes. 
- Présentation des images, descriptions, tarifs et disponibilités.
- Affichage des catégories, tags et badges, notamment pour les visites guidées.
- Gestion des options personnalisables des activités.
- Mise en évidence des activités mises en avant.

### Recherche et filtres ( finir car il y a encore des bugs et des manques )
- Recherche instantanée dans les noms, descriptions, tags et options.
- Classement des résultats selon leur pertinence.
- Suggestions de recherche prédéfinies.
- Mise en évidence des termes recherchés.
- Filtrage par tags.
- Tri par pertinence, prix croissant, prix décroissant ou nom.
- Navigation au clavier dans les résultats.

### Gestion du panier
- Ajout et suppression d'activités.
- Modification des quantités.
- Calcul automatique du nombre d'articles et du montant total.
- Prise en compte des options sélectionnées.
- Contrôle de la disponibilité et du stock lors de l'ajout.
- Mise à jour dynamique des informations du panier.
- Animation visuelle lors de l'ajout d'un article.

### Gestion des favoris
- Ajout et suppression d'activités favorites.
- Affichage des favoris dans un panneau dédié.
- Ajout d'une activité favorite au panier.
- Ajout successif de tous les favoris au panier.
- Synchronisation des compteurs et de l'interface.

### Fiches détaillées
- Ouverture d'un panneau de détail pour chaque activité.
- Affichage des informations, du tarif, des tags et des options.
- Accès aux actions du panier et des favoris depuis la fiche.

### Sauvegarde locale
Le script utilise `localStorage` pour conserver les données suivantes entre les visites :

- Le contenu du panier.
- La liste des favoris.
- L'adresse e-mail enregistrée dans l'interface de newsletter.

Les données sont enregistrées dans le navigateur, sans nécessiter de stockage supplémentaire côté serveur pour ces fonctionnalités.

### Expérience utilisateur
- Panneaux latéraux pour le panier, les favoris et les détails.
- Gestion centralisée des superpositions et des panneaux ouverts.
- Notifications temporaires intégrées à l'interface.
- Animations d'ajout au panier et aux favoris.
- Menu de navigation adapté aux appareils mobiles.
- Raccourcis clavier pour ouvrir la recherche et fermer les panneaux.

## Technologies utilisées

- **JavaScript ES6+** : logique applicative, classes, événements et manipulation du DOM.
- **PHP** : transmission des données du catalogue au JavaScript.
- **HTML5** : structure de la page et modèles HTML.
- **CSS3** : présentation, animations et états visuels.
- **Web Storage API (`localStorage`)** : persistance locale des données.
- **Web Animations API** : animations des éléments de l'interface.

## Architecture du script

Le code est organisé autour de plusieurs classes et services spécialisés.

| Classe ou module | Rôle |
|---|---|
| `StorageAdapter` | Lecture et écriture des données dans le stockage local. |
| `EventBus` | Gestion des événements personnalisés et notification des composants. |
| `Store` | Gestion centralisée du catalogue, du panier, des favoris, des filtres et du tri. |
| `ToastService` | Affichage des notifications temporaires. |
| `FlyAnimationService` | Animation des images vers le panier ou les favoris. |
| `SearchService` | Recherche et classement des résultats. |
| `OptionReader` | Lecture des options sélectionnées par l'utilisateur. |
| `OverlayManager` | Gestion des panneaux et des superpositions. |
| `ProductCard` | Création des cartes du catalogue. |
| `GridComponent` | Affichage et actualisation de la grille des activités. |
| `FilterChipsComponent` | Gestion des filtres par tags. |
| `SortComponent` | Gestion du tri des activités. |
| `HeaderBadgesComponent` | Actualisation des compteurs du panier et des favoris. |
| `CartDrawerComponent` | Affichage du contenu du panier. |
| `FavsDrawerComponent` | Affichage des favoris. |
| `ProductDetailComponent` | Affichage des fiches détaillées. |
| `SearchComponent` | Gestion de l'interface de recherche. |
| `NewsletterComponent` | Gestion de l'interface d'inscription à la newsletter. |
| `MobileMenuComponent` | Gestion du menu mobile. |
| `HotkeysComponent` | Gestion des raccourcis clavier. |
| `CartInteractions` | Centralisation des interactions liées au panier et aux favoris. |

Le démarrage de l'application est assuré par une fonction d'initialisation appelée `boot()`.

## 🔌 Intégration avec PHP

Les activités sont injectées dans le script à partir d'une variable PHP nommée `$formattedProducts`.

Le code utilise `json_encode()` pour transmettre les données au format JSON :

```php
const API_PRODUCTS = <?php echo json_encode(
    $formattedProducts,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
); ?>;
```

Les données sont ensuite utilisées pour initialiser le catalogue JavaScript.

```javascript
const PRODUCTS = API_PRODUCTS;

const storage = new StorageAdapter();
const store = new Store({
    storage,
    seed: PRODUCTS
});
```

Le script doit donc être intégré dans un environnement PHP capable de générer les données attendues. Il ne s'agit pas d'un fichier JavaScript autonome utilisable tel quel dans une page HTML statique.

## Prérequis

Pour fonctionner correctement, le script nécessite :

- Un environnement serveur exécutant PHP.
- Une variable `$formattedProducts` contenant les activités au format attendu.
- Une page HTML comportant les éléments nécessaires à l'interface.
- Les modèles HTML utilisés par les fonctions `tpl()` et `tplFragment()`.
- Une feuille de style CSS définissant les classes visuelles utilisées.
- Les navigateurs prenant en charge les fonctionnalités JavaScript modernes et `localStorage`.

Les propriétés des activités peuvent notamment inclure `id`, `name`, `price`, `img`, `desc`, `tags`, `stock`, `options` et `featured`, selon les fonctionnalités utilisées.

## Installation et utilisation

1. Intégrer le script dans la page PHP qui affiche le catalogue.
2. Préparer les données des activités dans `$formattedProducts`.
3. Ajouter les éléments HTML et les modèles attendus par le script.
4. Charger la feuille de style correspondante.
5. Vérifier que les sélecteurs `data-*` utilisés par le JavaScript correspondent bien à ceux du HTML.
6. Tester l'affichage des activités, la recherche, les filtres, le panier et les favoris.

**Important :** le script dépend de sa structure HTML et de ses modèles. Copier uniquement `youscript.js` dans un autre projet ne suffit pas à reproduire toute l'interface.

## Sécurité et limites

- Les données enregistrées dans `localStorage` restent propres au navigateur utilisé et ne constituent pas un panier partagé entre plusieurs appareils.
- Le panier et le contrôle du stock côté JavaScript ne remplacent pas une validation côté serveur.
- Le script ne met pas en œuvre, à lui seul, un système de paiement ni une commande définitive.
- L'inscription à la newsletter présente dans ce fichier est une simulation d'interface : elle ne réalise pas d'inscription auprès d'un service externe.
- Les données affichées et les montants doivent être validés côté serveur avant toute transaction réelle.

## Objectif du projet

Ce script vise à proposer une expérience de navigation moderne, fluide et interactive pour un catalogue d'activités touristiques, tout en séparant les responsabilités entre la gestion des données, l'affichage et les interactions utilisateur.

Il constitue une base JavaScript modulaire pouvant être intégrée à un site PHP existant et adaptée aux besoins d'un Office de Tourisme.

## Licence

La licence du projet reste à définir. Aucune licence particulière n'est déclarée dans ce fichier.

