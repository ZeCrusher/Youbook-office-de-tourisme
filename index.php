<?php
	/* On démarre les Sessions !! */
	session_start();
	/* connexion à la base de données MySQL de l'Office */
	require_once 'db.php';
	$clef=$_SESSION['clef'];

	
	$currentUser = null; /* Vide le champ User du site */
	$authError = '';

// =================================================================================
// 1. GESTION DE L'AUTHENTIFICATION CLIENT (Connexion / Inscription / Déconnexion)
// =================================================================================

	if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_action'])) {
	   
		$action = $_POST['auth_action'];

		if ($action === 'login') {
			$email = trim($_POST['email'] ?? '');
			$password = $_POST['password'] ?? '';

			if ($email && $password) {
				$stmt = $pdo->prepare("SELECT * FROM youbook_users WHERE email = :email");
				$stmt->execute([':email' => $email]);
				$user = $stmt->fetch();

				if ($user && password_verify($password, $user['password'])) {
					$_SESSION['user_logged'] = true;
					$_SESSION['user_id'] = $user['id'];
					$_SESSION['user_firstname'] = $user['firstname'];
					$_SESSION['user_lastname'] = $user['lastname'];
					$_SESSION['user_email'] = $user['email'];
					header('Location: index.php');
					exit;
				} else {
					$authError = "Adresse e-mail ou mot de passe incorrect.";
				}
			} else {
				$authError = "Veuillez remplir tous les champs.";
			}
		} elseif ($action === 'register') {
			$firstname = trim($_POST['firstname'] ?? '');
			$lastname = trim($_POST['lastname'] ?? '');
			$email = trim($_POST['email'] ?? '');
			$password = $_POST['password'] ?? '';

			if ($firstname && $lastname && $email && $password) {
				try {
					$stmt = $pdo->prepare("INSERT INTO youbook_users (firstname, lastname, email, password) VALUES (:f, :l, :e, :p)");
					$stmt->execute([
						':f' => $firstname,
						':l' => $lastname,
						':e' => $email,
						':p' => password_hash($password, PASSWORD_DEFAULT)
					]);

					$_SESSION['user_logged'] = true;
					$_SESSION['user_id'] = $pdo->lastInsertId();
					$_SESSION['user_firstname'] = $firstname;
					$_SESSION['user_lastname'] = $lastname;
					$_SESSION['user_email'] = $email;
					header('Location: index.php');
					exit;
				} catch (Exception $e) {
					$authError = "Un compte existe déjà avec cet e-mail.";
				}
			} else {
				$authError = "Veuillez remplir tous les champs du formulaire.";
			}
		} elseif ($action === 'logout') {
			unset($_SESSION['user_logged'], $_SESSION['user_id'], $_SESSION['user_firstname'], $_SESSION['user_lastname'], $_SESSION['user_email']);
			header('Location: index.php');
			exit;
		}
	}

	// Vérifier si un utilisateur est déjà connecté
	if (!empty($_SESSION['user_logged'])) {
		$currentUser = [
			'id'        => $_SESSION['user_id'],
			'firstname' => $_SESSION['user_firstname'],
			'lastname'  => $_SESSION['user_lastname'],
			'email'     => $_SESSION['user_email']
		];
	}

	// Date du jour pour l'affichage
	$ladate = date('d/m/Y');

	// =============================================================
	// 2. CHARGEMENT DES CRÉNEAUX DEPUIS MYSQL (AVEC CORRECTION ID)
	// =============================================================
	// 1. Récupération des créneaux depuis MySQL
	$today = date('Y-m-d');
	$stmtSchedules = $pdo->prepare("SELECT id, product_id, event_date, start_time, end_time, stock 	FROM youbook_product_schedules WHERE event_date >= :today ORDER BY event_date ASC, start_time ASC");
	
	
	$stmtSchedules->execute([':today' => $today]);
	$allSchedules = $stmtSchedules->fetchAll();

	// 2. REMPLACE LA BOUCLE EXISTANTE PAR CELLE-CI :
	$schedulesByProduct = [];
	
	foreach ($allSchedules as $sched) {
	
	// Le trim() évite les erreurs dues aux espaces invisibles
		$pId = trim((string)$sched['product_id']); 
		
		if (!isset($schedulesByProduct[$pId])) {
			$schedulesByProduct[$pId] = [];
		}
		$schedulesByProduct[$pId][] = [
			'schedule_id' => (int)$sched['id'],
			'date'        => $sched['event_date'],
			'date_fr'     => date('d/m/Y', strtotime($sched['event_date'])),
			'start_time'  => substr($sched['start_time'], 0, 5),
			'end_time'    => substr($sched['end_time'], 0, 5),
			'stock'       => (int)$sched['stock']
		];
	}
	
	$schedulesByProduct = [];
	foreach ($allSchedules as $sched) {
		// Forcer en string et appliquer trim()
		$pId = trim((string)$sched['product_id']); 
		
		if (!isset($schedulesByProduct[$pId])) {
			$schedulesByProduct[$pId] = [];
		}
		$schedulesByProduct[$pId][] = [
			'schedule_id' => (int)$sched['id'],
			'date'        => $sched['event_date'],
			'date_fr'     => date('d/m/Y', strtotime($sched['event_date'])),
			'start_time'  => substr($sched['start_time'], 0, 5),
			'end_time'    => substr($sched['end_time'], 0, 5),
			'stock'       => (int)$sched['stock']
		];
	}

	// =================================================================
	// 3. APPEL cURL API YOUBOOK (Récupération de toutes les activités)
	// =================================================================
	$url = "http://public.api.youbook.travel/v3/products/list";
	$headers = ["X-Backend: true", "Content-Type: application/json", "Authorization: Bearer ".$clef];
	$data = [
		"size" => 100,
		"filters" => [
			["filterProperties" => ["type" => "publication_states", "target" => "product"], "selection" => ["PUBLISHED"]],
			["filterProperties" => ["type" => "distributor"]]
		],
		"sorts" => [["type" => "random"]]
	];

	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
	$response = curl_exec($ch);
	curl_close($ch);

	$result = json_decode($response, true);
	$productsList = $result['data'] ?? $result['products'] ?? [];

	// =============================================================
	// 4. FORMATAGE DES PRODUITS DEPUIS L'API
	// =============================================================


	$formattedProducts = array_map(function($product) use ($schedulesByProduct) {
		$id = (string)($product['id'] ?? '');
		
		// Extrait l'ID sans "ingenie:" s'il existe (ex: V_MSM_5592056)
		$cleanId = str_replace('ingenie:', '', $id);
		// Extrait uniquement les chiffres (ex: 5592056)
		$numericId = preg_replace('/[^0-9]/', '', $id);

		// Recherche le créneau par ID exact, ID nettoyé, ou ID numérique
		$productSchedules = $schedulesByProduct[$id] 
						 ?? $schedulesByProduct[$cleanId] 
						 ?? $schedulesByProduct[$numericId] 
						 ?? [];

		// Calcul du stock réel basé sur les créneaux trouvés
		$totalStock = count($productSchedules) > 0 ? array_sum(array_column($productSchedules, 'stock')) : 0;

		$imageUrl = 'https://via.placeholder.com/400x300?text=Pas+d+image';
		if (!empty($product['images'][0]['url'])) {
			$imageUrl = $product['images'][0]['url'];
		}

		$name = $product['name'] ?? $product['title'] ?? 'Produit sans nom';
		if (!empty($product['names']) && is_array($product['names'])) {
			foreach ($product['names'] as $n) {
				if (isset($n['lang'], $n['value']) && $n['lang'] === 'fr-FR') { $name = $n['value']; break; }
			}
		}

		$description = 'Aucune description disponible.';
		if (!empty($product['descriptions']) && is_array($product['descriptions'])) {
			foreach ($product['descriptions'] as $desc) {
				if (isset($desc['lang'], $desc['type']) && $desc['lang'] === 'fr-FR' && $desc['type'] === 'PRESENTATION') {
					$description = trim(strip_tags($desc['content']));
					break;
				}
			}
		}

		$price = round((float)($product['priceFrom'] ?? $product['price'] ?? 0), 2);

		return [
			'id'          => $id,
			'name'        => $name,
			'price'       => $price,
			'currency'    => $product['currency'] ?? 'EUR',
			'img'         => $imageUrl,
			'desc'        => $description,
			'tags'        => $product['tags'] ?? ["Youbook"],
			'stock'       => $totalStock,
			'isGuided'    => true,
			'schedules'   => $productSchedules
		];		
	}, $productsList);


?>



<html lang="fr">
	<head>
	<meta charset="UTF-8">

    <link rel="icon" type="image/x-icon" href="https://cpwebassets.codepen.io/assets/favicon/favicon-aec34940fbc1a6e787974dcd360f2c6b63348d4b1f4e06c77743096d55480f33.ico">

	<title>Youbook - Office de tourisme de Martigues</title>

	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,ital,wght@9..144,0,400;9..144,0,700;9..144,0,900;9..144,1,400;9..144,1,700&amp;family=Inter:wght@400;500;600;700&amp;display=swap">
	<link rel="stylesheet" href="css/style.css">


	<script>
		window.console = window.console || function(t) {};
	</script>


	<style>
		.auth-form-group { margin-bottom: 1rem; }
		.auth-form-group label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.3rem; }
		.auth-form-group input { width: 100%; padding: 0.6rem; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
		.auth-tabs { display: flex; border-bottom: 1px solid #eee; margin-bottom: 1.5rem; }
		.auth-tab { flex: 1; padding: 0.75rem; text-align: center; font-weight: bold; cursor: pointer; border-bottom: 2px solid transparent; }
		.auth-tab.is-active { border-bottom-color: #0284c7; color: #0284c7; }
		.user-avatar-btn { background: #0284c7; color: white; border-radius: 50%; font-weight: bold; font-size: 0.9rem; }
	</style>
 

<?php

	// 1. Charger les créneaux futurs depuis MySQL
	$today = date('Y-m-d');
	$stmtSchedules = $pdo->prepare("SELECT id, product_id, event_date, start_time, end_time, stock FROM youbook_product_schedules WHERE event_date >= :today ORDER BY event_date ASC, start_time ASC");
	$stmtSchedules->execute([':today' => $today]);
	$allSchedules = $stmtSchedules->fetchAll();

	// Organiser les créneaux par ID produit
	$schedulesByProduct = [];
	foreach ($allSchedules as $sched) {
		$pId = $sched['product_id'];
		if (!isset($schedulesByProduct[$pId])) {
			$schedulesByProduct[$pId] = [];
		}
		$schedulesByProduct[$pId][] = [
			'schedule_id' => (int)$sched['id'],
			'date'        => $sched['event_date'],
			'date_fr'     => date('d/m/Y', strtotime($sched['event_date'])),
			'start_time'  => substr($sched['start_time'], 0, 5),
			'end_time'    => substr($sched['end_time'], 0, 5),
			'stock'       => (int)$sched['stock']
		];
	}

	// 3. Variable utilisée pour l'affichage de la date
	$mois = [ 1 => 'janvier','février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
	$ladate = "Nous sommes le " . date('j') . " " . $mois[date('n')] . " " . date('Y');
	 
	// 4. Appel cURL à l'API Youbook
	$url = "http://public.api.youbook.travel/v3/products/list";

	$headers = [
		"X-Backend: true",
		"Content-Type: application/json",
		"Authorization: Bearer ".$clef
	];

	$data = [
		"size" => 100,
		"filters" => [
			[
				"filterProperties" => ["type" => "publication_states", "target" => "product"],
				"selection" => ["PUBLISHED"]
			],
			[
				"filterProperties" => ["type" => "distributor"]
			]
		],
		"sorts" => [["type" => "random"]],
		"metrics" => [["type" => "taxonomyThemes"], ["type" => "taxonomyTypes"]],
		"properties" => [["type" => "rating"]]
	];

	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

	$response = curl_exec($ch);
	curl_close($ch);

	$result = json_decode($response, true);

	// Récupération du tableau brut
	$productsList = $result['data'] ?? $result['products'] ?? [];

	// 5. Formatage exact pour le frontend
	$formattedProducts = array_map(function($product) use ($schedulesByProduct) {
		
		// Image principale (Priorité aux images taguées en fr-FR, sinon la 1ère disponible)
		$imageUrl = 'https://via.placeholder.com/400x300?text=Pas+d+image';
		if (!empty($product['images']) && is_array($product['images'])) {
			foreach ($product['images'] as $img) {
				if (isset($img['lang']) && $img['lang'] === 'fr-FR' && !empty($img['url'])) {
					$imageUrl = $img['url'];
					break;
				}
			}
			if ($imageUrl === 'https://via.placeholder.com/400x300?text=Pas+d+image' && !empty($product['images'][0]['url'])) {
				$imageUrl = $product['images'][0]['url'];
			}
		}

		// Extraction du Nom / Titre en Français
		$name = $product['name'] ?? $product['title'] ?? 'Produit sans nom';
		if (!empty($product['names']) && is_array($product['names'])) {
			foreach ($product['names'] as $n) {
				if (isset($n['lang'], $n['value']) && $n['lang'] === 'fr-FR') {
					$name = $n['value'];
					break;
				}
			}
		}

		// Extraction de la description en Français (PRESENTATION)
		$description = 'Aucune description disponible.';
		if (!empty($product['descriptions']) && is_array($product['descriptions'])) {
			foreach ($product['descriptions'] as $desc) {
				if (isset($desc['lang'], $desc['type']) && $desc['lang'] === 'fr-FR' &&	$desc['type'] === 'PRESENTATION') {
					$description = trim(strip_tags($desc['content']));
					break;
				}
			}
		}

		// Extraction du prix avec arrondi propre à 2 décimales
		$price = round((float)($product['priceFrom'] ?? $product['price'] ?? 0), 2);

		

		// Adresse / Point de rdv
		$address = '';
		if (!empty($product['meetingPoint']['location'])) {
			$loc = $product['meetingPoint']['location'];
			$address = trim(($loc['streetAddress'] ?? '') . ' ' . ($loc['zipCode'] ?? '') . ' ' . ($loc['locality'] ?? ''));
		}

		// Association des créneaux BDD
		$id = (string)($product['id'] ?? 'prod-' . rand(100, 999));
		$productSchedules = $schedulesByProduct[$id] ?? [];
		$totalStock = count($productSchedules) > 0 ? array_sum(array_column($productSchedules, 'stock')) : 0;

		return [
			'id'          => $id,
			'name'        => $name,
			'price'       => $price,
			'currency'    => $product['currency'] ?? 'EUR',
			'img'         => $imageUrl,
			'desc'        => $description,
			'address'     => $address,
			'tags'        => $product['tags'] ?? ["Youbook"],
			'stock'       => $totalStock,
			'isGuided'    => true,
			'options'     => $product['options'] ?? [],
			'schedules'   => $productSchedules
		];		
	}, $productsList);
?>
  
</head>

<body translate="no">
  <div class="marquee" aria-hidden="true">
	<div class="marquee-track"> <!-- A faire en lien avec la liste des produits -->
		<span>En ce moment</span><span>✦</span>
		<span>produit 1</span><span>✦</span>
		<span>produit 2</span><span>✦</span>
		<span>produit 3</span><span>✦</span>
		<span>produit 4</span><span>✦</span>
		<span>produit 5</span><span>✦</span>
		<span>produit 6</span><span>✦</span>
		<span>produit 7</span><span>✦</span>
	</div>
</div>

<header class="site-header">
	<a class="brand-mark" href="#">
		<img scr="https://www.martigues-tourisme.com/templates/2018/images/logo.png" height="20px" width="20px;" >
		<span>
			<strong>Expérience</strong>
			<small>à Martigues · <?php echo date('Y'); ?></small>
		</span>
	</a>
	<nav class="site-nav">
		<a href="#">Famille</a>
		<a href="#">Enfants</a>
		<a href="#">Sport</a>
		<a href="#">Repos</a>
		<a href="#">Terre</a>
		<a href="#">Mer</a>
	</nav>
	<div class="header-actions">
		<button class="icon-btn menu-btn" aria-label="Open menu" data-toggle-menu="">
			<svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
				<path d="M3 6h18M3 12h18M3 18h18"></path>
			</svg>
		</button>
		<button class="icon-btn search-btn" aria-label="Search" data-open-search="">
			<svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
				<circle cx="11" cy="11" r="7"></circle>
				<path d="m21 21-4.3-4.3"></path>
			</svg>
		</button>
		<button class="icon-btn fav-btn" aria-label="Favorites" data-open-favs="">
			<svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
				<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1L12 21.2l7.7-7.8 1.1-1a5.5 5.5 0 0 0 0-7.8Z"></path>
			</svg>
			<span class="fav-pill is-empty" data-fav-count="">0</span>
		</button>
		<button class="icon-btn cart-btn" aria-label="Open cart" data-open-cart="">
			<svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
				<path d="M5 7h14l-1.5 11a2 2 0 0 1-2 1.7H8.5a2 2 0 0 1-2-1.7L5 7Z"></path>
				<path d="M9 7V5a3 3 0 0 1 6 0v2"></path>
			</svg>
			<span class="cart-pill is-empty" data-cart-count="">0</span>
		</button>
		<button class="icon-btn <?= $currentUser ? 'user-avatar-btn' : '' ?>" aria-label="Account" data-open-auth="">
			<?php if ($currentUser): ?>
				<?= strtoupper(substr($currentUser['firstname'], 0, 1)) ?>
			<?php else: ?>
				<svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
					<circle cx="12" cy="7" r="4"></circle>
				</svg>
			<?php endif; ?>
		</button>		
	</div>
</header>

<nav class="mobile-nav" data-mobile-nav="">
	<a href="#">Shop</a>
	<a href="#">Collections</a>
	<a href="#">Journal</a>
	<a href="#">Visit</a>
</nav>

	<section class="hero fw">
		<div class="hero-grid">
			<div class="hero-left">
				<span class="kicker"><span class="kicker-dot"></span><?php echo $ladate; ?></span>
				<h1 class="display">Trouvez l'expérience qui  
					<span class="ital accent">vous</span>
					<span class="ital">ressemble<span>
				</span></span></h1>
				<p class="lede">Qu'avez-vous prévu pour les vacances ?</p>
				<div class="hero-meta">
					<a href="#grid" class="pill pill--filled">Voir la liste</a>
					<button type="button" class="pill" data-open-search=""> <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<circle cx="11" cy="11" r="7"></circle>
							<path d="m21 21-4.3-4.3"></path>
						</svg>Rechercher</button>
				</div>
			</div>
			<aside class="hero-stats">
				<div><b>00</b><span>expériences</span></div>
				 <!-- <div><b>48h</b><span>ships within</span></div>  -->
				<div><b><span style="font-weight: 900;font-size: 1.5rem;letter-spacing: -0.02em;" data-cart-count="">0</span></b><span>dans votre panier</span></div>
			</aside>
		</div>
	</section>

	<div class="toolbar">
		<div class="filter-chips" role="tablist" data-filter-chips="">
			<!--- <button type="button" class="chip is-active" data-tag="">TOUT · </button>
			<button type="button" class="chip" data-tag="test01">test01</button>
			<button type="button" class="chip" data-tag="test01">test01</button>
			<button type="button" class="chip" data-tag="test01">test01</button>
			<button type="button" class="chip" data-tag="test01">test01</button> -->
		</div>
		<label class="sort">
			<span>Liste</span>
			<select data-sort="">
				<option value="featured">En ce moment</option>
				<option value="price-asc">Prix: Ordre croissant</option>
				<option value="price-desc">Prix: Ordre décroissant</option>
				<option value="name">Par nom de A → Z</option>
			</select>
		</label>
	</div>


	<main class="grid" id="grid" data-grid="">
	</main>

	<section class="callout">
		<div class="callout-inner">
			<p class="kicker"><span class="kicker-dot"></span>Pour ne rien rater !</p>
	 
			<h2>Ne manquez pas les prochaines <em>Expériences</em> avant qu'elles ne soient prise d'assaut.</h2>
			<form class="signup" data-signup="">
				<input type="email" placeholder="adresse@maboitemel.com" required="">
				<button type="submit">S'inscrire →</button>
			</form>
			<div class="subscribed-state" data-subscribed="">
				<strong>Vous êtes inscrit ✓</strong>
				<code data-sub-email=""></code>
				<button type="button" data-unsubscribe="">Se désabonner</button>
			</div>
			<small class="helper">Ici... on deteste le SPAM !</small>
		</div>
	</section>

	<footer class="site-footer">
		<div class="foot-grid">
			<div>
				<strong>Les Expériences à Vivre</strong>
				<p>Des activités sélectionnées avec soin pour chacun d'entre vous.</p>
			</div>
			<div>
				<h4>Shop</h4>
				<a href="#">Site Officiel de l'Office</a><a href="#">Ceramic</a><a href="#">Travel</a><a href="#">Limited</a>
			</div>
			<!-- <div>
				<h4>Help</h4>
				<a href="#">Shipping</a><a href="#">Returns</a><a href="#">Care</a><a href="#">Contact</a>
			</div> -->
			<div>
				<h4>Suivez-nous</h4>
				<a href="#">Instagram</a><a href="#">facebook</a><a href="#">LinkedIn</a>
			</div>
		</div>
		<p class="legal">© 2026 Les expériences à Martigues</p>
	</footer>



	<aside class="cart-bar" data-cart-bar="" hidden="">
		<div class="cart-bar-inner">
			<div>
				<b><span data-cart-count="">0</span> produit(s)</b>
				<span class="cart-total"><span data-cart-total="">0</span> €</span>
			</div>
			<button type="button" class="cta cta--accent" data-open-cart="">Voir le panier →</button>
		</div>
	</aside>


<!-- Cart drawer -->
<div class="drawer" data-cart-drawer="">
	<div class="drawer-backdrop" data-close-cart=""></div>
	<div class="drawer-panel" role="dialog" aria-label="Cart">
		<div class="drawer-head">
			<h3>Votre panier<span class="count" data-cart-count="">0</span></h3>
			<button class="drawer-close" data-close-cart="" aria-label="Close">⨉</button>
		</div>
		<div class="drawer-items" data-cart-items=""><div class="empty-state">
		<svg viewBox="0 0 24 24" width="48" height="48">
			<path d="M5 7h14l-1.5 11a2 2 0 0 1-2 1.7H8.5a2 2 0 0 1-2-1.7L5 7Z"></path>
			<path d="M9 7V5a3 3 0 0 1 6 0v2"></path>
		</svg>
		<p>Votre panier est vide</p>
		<button type="button" class="cta" data-close-cart="">Continuer votre shopping</button>
	</div></div>
		<div class="drawer-foot">
			<div class="drawer-totals">
				<div class="row"><span>Résumé de votre panier</span><span>Montant</span></div>
				<div class="row sub"><span>Total</span><b><span data-cart-total="">0</span> €</b></div>
			</div>
			<button type="button" class="cta cta--accent">Payer la commande</button>
		</div>
	</div>
</div>

<!-- Espace Membre Drawer -->
<div class="drawer" data-auth-drawer="<?= $authError ? 'is-open' : '' ?>">
	<div class="drawer-backdrop" data-close-auth=""></div>
	<div class="drawer-panel" role="dialog" aria-label="Espace Membre">
		<div class="drawer-head">
			<h3>Espace Membre</h3>
			<button class="drawer-close" data-close-auth="" aria-label="Close">⨉</button>
		</div>
		<div class="drawer-items" style="padding: 1.5rem;">
			<?php if ($currentUser): ?>
				<div style="text-align: center; padding: 1rem 0;">
					<h2>Bonjour <?= htmlspecialchars($currentUser['firstname']) ?> ! 👋</h2>
					<p><?= htmlspecialchars($currentUser['email']) ?></p>
					<form method="POST" action="index.php" style="margin-top: 2rem;">
						<input type="hidden" name="auth_action" value="logout">
						<button type="submit" class="cta" style="background:#dc2626; color:white;">Se déconnecter</button>
					</form>
				</div>
			<?php else: ?>
				<div class="auth-tabs">
					<div class="auth-tab is-active" id="tabLogin" onclick="switchAuthTab('login')">Connexion</div>
					<div class="auth-tab" id="tabRegister" onclick="switchAuthTab('register')">Inscription</div>
				</div>

				<?php if ($authError): ?>
					<div style="background:#fee2e2; color:#dc2626; padding:0.8rem; border-radius:4px; margin-bottom:1rem; font-size:0.85rem;"><?= htmlspecialchars($authError) ?></div>
				<?php endif; ?>

				<form method="POST" action="index.php" id="formLogin">
					<input type="hidden" name="auth_action" value="login">
					<div class="auth-form-group">
						<label>Adresse e-mail</label>
						<input type="email" name="email" required>
					</div>
					<div class="auth-form-group">
						<label>Mot de passe</label>
						<input type="password" name="password" required>
					</div>
					<button type="submit" class="cta cta--accent" style="width: 100%; margin-top: 1rem;">Se connecter</button>
				</form>

				<form method="POST" action="index.php" id="formRegister" style="display: none;">
					<input type="hidden" name="auth_action" value="register">
					<div class="auth-form-group">
						<label>Prénom</label>
						<input type="text" name="firstname" required>
					</div>
					<div class="auth-form-group">
						<label>Nom</label>
						<input type="text" name="lastname" required>
					</div>
					<div class="auth-form-group">
						<label>Adresse e-mail</label>
						<input type="email" name="email" required>
					</div>
					<div class="auth-form-group">
						<label>Téléphone</label>
						<input type="tel" name="phone" placeholder="06 12 34 56 78" required>
					</div>
					<div class="auth-form-group">
						<label>Mot de passe</label>
						<input type="password" name="password" required>
					</div>
					<button type="submit" class="cta cta--accent" style="width: 100%; margin-top: 1rem;">Créer mon compte</button>
				</form>
			<?php endif; ?>
		</div>
	</div>
</div>

<!-- ┌───────────────────────────────────────────────────────────────┐ -->
<!-- │ 	Product detail drawer		:								 │ -->
<!-- └───────────────────────────────────────────────────────────────┘ -->

<!-- Product detail drawer -->
<div class="drawer" data-product-drawer="">
	<div class="drawer-backdrop" data-close-product=""></div>
	<div class="drawer-panel drawer-panel--detail" role="dialog" aria-label="Details du produit" data-pd-panel="">
		<div class="drawer-head">
			<h3 data-pd-title="">Produit</h3>
			<button class="drawer-close" data-close-product="" aria-label="Close">⨉</button>
		</div>
		<div class="drawer-items" data-product-detail=""></div>
		<div class="drawer-foot" data-pd-foot="">
			<div data-buy=""></div>
		</div>
	</div>
</div>


<!-- ┌───────────────────────────────────────────────────────────────┐ -->
<!-- │ 	Tiroir des favoris		:									 │ -->
<!-- └───────────────────────────────────────────────────────────────┘ -->
<div class="drawer" data-favs-drawer="">
	<div class="drawer-backdrop" data-close-favs=""></div>
	<div class="drawer-panel" role="dialog" aria-label="Saved">
		<div class="drawer-head">
			<h3>Sauvegarde<span class="count" data-fav-count="">0</span></h3>
			<button class="drawer-close" data-close-favs="" aria-label="Close">⨉</button>
		</div>
		<div class="drawer-items" data-favs-items="">
			<div class="empty-state">
				<svg viewBox="0 0 24 24" width="48" height="48">
					<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1L12 21.2l7.7-7.8 1.1-1a5.5 5.5 0 0 0 0-7.8Z"></path>
				</svg>
			<p>Aucun favori pour l'instant</p>
			<button type="button" class="cta" data-close-favs="">Parcourir les expériences →</button>
			</div>
		</div>
		<div class="drawer-foot">
			<button type="button" class="cta cta--accent" data-move-all-favs="" style="display: none;">Tout ajouter dans le panier →</button>
		</div>
	</div>
</div>



<!-- ┌───────────────────────────────────────────────────────────────┐ -->
<!-- │ 	Le menu de Recherche	:									 │ -->
<!-- └───────────────────────────────────────────────────────────────┘ -->

<div class="search-overlay" data-search-overlay="">
	<div class="search-card" role="dialog" aria-label="Search">
		<form class="searchbar" onsubmit="event.preventDefault()">
			<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
				<circle cx="11" cy="11" r="7"></circle>
				<path d="m21 21-4.3-4.3"></path>
			</svg>
			<input type="search" placeholder="Rechercher une expérience unique" autocomplete="off" data-search-input="">
			<button type="button" class="search-clear" data-search-clear="" hidden="">Effacer</button>
			<button type="button" class="search-esc" data-close-search="">Retour / ESC</button>
		</form>
		<div class="search-results" data-search-results=""></div>
		<div class="search-foot">
			<span><kbd>↑</kbd> <kbd>↓</kbd> Navigation · <kbd>↵</kbd> Ouvrir</span>
			<span>OT Martigues 2026</span>
		</div>
	</div>
</div>


<div class="toast" data-toast="" aria-live="polite"></div>

<!-- =========================== TEMPLATES =========================== -->
<template id="t-card">
	<article class="item">
		<figure class="media">
			<img loading="lazy" alt="">
			<span class="tag" hidden=""></span>
			<button class="wishlist" data-fav="" aria-label="Save">
				<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1L12 21.2l7.7-7.8 1.1-1a5.5 5.5 0 0 0 0-7.8Z"></path>
				</svg>
			</button>
		</figure>
		<div class="body">
			<div class="title-row">
				<h3></h3>
				<span class="price"></span>
			</div>
			<p class="desc"></p>
			<div class="options"></div>
			<div class="buy" data-buy=""></div>
		</div>
	</article>
</template>

<template id="t-opt-swatch">
	<div class="opt-row">
		<span class="opt-label"><span class="label-text"></span> · <span data-opt-display="">Faites votre choix</span></span>
		<div class="swatches" data-opt=""></div>
	</div>
</template>

<template id="t-swatch-item">
	<button class="swatch" type="button"></button>
</template>

<template id="t-opt-pill">
	<div class="opt-row">
		<span class="opt-label"><span class="label-text"></span> · <span data-opt-display="">Faites votre choix</span></span>
		<div class="pill-options" data-opt=""></div>
	</div>
</template>

<template id="t-opt-pill-item">
	<button class="opt-pill" type="button"></button>
</template>

<template id="t-opt-letter">
	<div class="opt-row">
		<span class="opt-label"><span class="label-text">Odyssée</span></span>
		<input type="text" class="letter-input" maxlength="1" placeholder="C" data-opt="letter" data-opt-input="">
	</div>
</template>

<template id="t-opt-note">
	<div class="opt-row">
		<span class="opt-label muted"></span>
	</div>
</template>

<template id="t-opt-stock">
	<div class="opt-row">
		<span class="opt-label muted">
			<span class="stock-bar"><span class="stock-fill"></span></span>
			<span class="stock-text"></span>
		</span>
	</div>
</template>

<template id="t-pronouns">
	<div class="custom-pronouns" hidden="">
		<label>
			<small>Expérience 1</small>
			<input type="text" maxlength="3" placeholder="Mx" data-custom="1">
		</label>
		<label>
			<small>Expérience 1</small>
			<input type="text" maxlength="3" placeholder="Mx" data-custom="2">
		</label>
	</div>
</template>

<template id="t-line">
	<div class="line"> <!-- OK -->
		<img alt="">
		<div>
			<p class="l-name"></p>
			<p class="l-opts"></p>
			<div class="l-actions"></div>
		</div>
		<div class="l-right"><span class="l-price"></span></div>
	</div>
</template>

<template id="t-search-result">
	<button type="button" class="search-result">
		<img alt="">
		<div>
			<p class="sr-name"></p>
			<p class="sr-meta"></p>
		</div>
		<span class="sr-price"></span>
	</button>
</template>

<template id="t-buy-add">
	<button type="button" class="buy-add" data-add="">
		<span class="add-label">Ajouter au panier</span>
		<span class="add-arrow"></span>
	</button>
</template>

<template id="t-buy-stepper">
	<div class="buy-stepper" role="group" aria-label="Quantity">
		<button type="button" data-dec="" aria-label="Decrease">−</button>
		<div class="in-cart"><b class="qty"></b>dans le panier · <span class="line-total"></span></div>
		<button type="button" data-inc="" aria-label="Increase">+</button>
	</div>
</template>

<template id="t-cart-line-actions">
	<div class="l-stepper">
		<button type="button" data-dec="" aria-label="Decrease">−</button>
		<output></output>
		<button type="button" data-inc="" aria-label="Increase">+</button>
	</div>
	<button type="button" class="l-btn l-btn--ghost" data-remove="">Retirer</button>
</template>

<template id="t-fav-line-actions">
	<button type="button" class="l-btn l-btn--primary" data-add-from-fav=""></button>
	<button type="button" class="l-btn l-btn--ghost" data-rm-fav="">Retirer</button>
</template>

<template id="t-empty-cart">
	<div class="empty-state">
		<svg viewBox="0 0 24 24" width="48" height="48">
			<path d="M5 7h14l-1.5 11a2 2 0 0 1-2 1.7H8.5a2 2 0 0 1-2-1.7L5 7Z"></path>
			<path d="M9 7V5a3 3 0 0 1 6 0v2"></path>
		</svg>
		<p>Votre panier est vide</p>
		<button type="button" class="cta" data-close-cart="">Continuez vos achats →</button>
	</div>
</template>

<template id="t-empty-favs">
	<div class="empty-state">
		<svg viewBox="0 0 24 24" width="48" height="48">
			<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1L12 21.2l7.7-7.8 1.1-1a5.5 5.5 0 0 0 0-7.8Z"></path>
		</svg>
		<p>Aucun favori pour l'instant</p>
		<button type="button" class="cta" data-close-favs="">Parcourir les expériences →</button>
	</div>
</template>

<template id="t-search-empty">
	<div class="search-empty">
		<p>De quoi avez-vous envie ?</p>
		<div class="search-suggestions" data-suggestions=""></div>
	</div>
</template>

<template id="t-search-suggestion">
	<button type="button" data-suggest=""></button>
</template>

<template id="t-search-no-results">
	<div class="search-empty">
		<p>Aucune expérience ne correspond à "<b data-query=""></b>"</p>
		<small>Essayez de parcourir les autres expériences du moment</small>
	</div>
</template>


<template id="t-product-detail">
	<article class="pd-card">
		<figure class="pd-figure">
			<img class="pd-img" alt="">
			<span class="tag" hidden=""></span>
			<button class="wishlist" data-fav="" aria-label="Save">
				<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1L12 21.2l7.7-7.8 1.1-1a5.5 5.5 0 0 0 0-7.8Z"></path>
				</svg>
			</button>
		</figure>
		<div class="pd-body">
			<p class="pd-price"></p>
			<p class="pd-desc"></p>
			<div class="pd-schedules" style="margin: 1rem 0;">
				<label style="font-weight: bold; display: block; margin-bottom: 0.5rem;">Choisissez une date & horaire :</label>
				<select class="schedule-select" style="width: 100%; padding: 0.6rem; border-radius: 4px; border: 1px solid #ccc;"></select>
			</div>			
			<div class="pd-tags"></div>
			<div class="pd-options options"></div>
		</div>
	</article>
</template>
  
	
<!-- =========================== TEMPLATES =========================== -->	
	
	
	<script id="rendered-js">
 
	const STORAGE_KEYS = {
			products: "officetourisme.products.v2",
			cart: "officetourisme.cart.v2",
			favs: "officetourisme.favs.v2",
			sub: "officetourisme.subscribed.v2" 
	};

		const ANIM = {
			toast: 1800,
			bump: 280,
			justAdded: 500,
			hit: 1200,
			searchDebounce: 80,
			searchFocusDelay: 80,
			signupFake: 900,
			flyDuration: 780,
			staggerAdd: 140,
			scrollDelay: 200,
			receiving: 600 
		};


		const API_PRODUCTS = <?= json_encode(
			$formattedProducts,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		); ?>;



		// const SEED_PRODUCTS = [	]; <- C'est pour les tests ^^' 

		const PRODUCTS = API_PRODUCTS;

		const $ = (sel, root = document) => root.querySelector(sel);
		const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
		const tpl = id => $(id).content.cloneNode(true).firstElementChild;
		const tplFragment = id => $(id).content.cloneNode(true);


	/* Dans le popup Recherhe. Là, il y a les mots clefs qui sont "souvent" dans les textes et titres de nos news .. souvent XD */
	const SEARCH_SUGGESTIONS = [
		"Accessible",
		"Bateau",
		"Balade",
		"Canaux",
		"Enfant",
		"Famille",
		"Sport"	];

	/* Variable qui va afficher le prix en € comme par exemple "9 €". */
	//const formatPrice = n => `${n} €`;
	/* Petite correction, car ça affiche des nombres à virugles 25.349999999999998 € même avec un float 2 */ 
	const formatPrice = n => `${Number(n).toFixed(2)} €`;
	
	const escapeHTML = (s) =>s.replace(/[&<>"']/g,(c) =>({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);


	const highlightTerm = (text, q) => {
	  if (!q) return escapeHTML(text);
	  const safe = escapeHTML(text);
	  const esc = q.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
	  return safe.replace(new RegExp(`(${esc})`, "gi"), "<mark>$1</mark>");
	};

	const bump = el => {
	  if (!el) return;
	  el.classList.add("bump");
	  setTimeout(() => el.classList.remove("bump"), ANIM.bump);
	};

	class StorageAdapter {
		constructor(backend = localStorage) {
			this.backend = backend;
		}
		load(key, fallback) {
			try {
				return JSON.parse(this.backend.getItem(key)) ?? fallback;
			} 	
			catch {
				return fallback;
			}
		}
		save(key, value) {
			this.backend.setItem(key, JSON.stringify(value));
		}
	}


class EventBus {
  constructor() {
    this._h = new Map();
  }
  on(event, fn) {
    if (!this._h.has(event)) this._h.set(event, new Set());
    this._h.get(event).add(fn);
    return () => this.off(event, fn);
  }
  off(event, fn) {
    this._h.get(event)?.delete(fn);
  }
  emit(event, payload) {
    this._h.get(event)?.forEach(fn => fn(payload));
  }}


class Store extends EventBus {
  constructor({ storage, seed }) {
    super();
    this.storage = storage;

    // const persisted = storage.load(STORAGE_KEYS.products, null);
    // this.products =
    // Array.isArray(persisted) && persisted.length ? persisted : seed;
    // if (this.products === seed) storage.save(STORAGE_KEYS.products, seed);
    // this.productsById = Object.fromEntries(this.products.map(p => [p.id, p]));
	
	this.products = seed;

	this.productsById = Object.fromEntries(
		this.products.map(p => [p.id, p])
	);
	

    this.cart = storage.load(STORAGE_KEYS.cart, {});
    this.favs = storage.load(STORAGE_KEYS.favs, []);
    this.subscribed = storage.load(STORAGE_KEYS.sub, null);
    this.filterTag = "";
    this.sort = "featured";
	
  }

  getProduct(id) {
    return this.productsById[id];
  }
  cartQty(id) {
    return this.cart[id]?.qty ?? 0;
  }
  isFav(id) {
    return this.favs.includes(id);
  }
  
  
  /* Correction Arrondis le total calculé à 2 décimales avant de le retourner */
	/* V1 -> cartTotals() {
		let count = 0,
		total = 0;
		for (const id in this.cart) {
			const p = this.productsById[id];
			if (!p) continue;
			count += this.cart[id].qty;
			total += this.cart[id].qty * p.price;
		}
		return { count, total };
	} */
	
	/* V2 */
	cartTotals() {
		let count = 0,
		total = 0;
		for (const id in this.cart) {
			const p = this.productsById[id];
			if (!p) continue;
			count += this.cart[id].qty;
			total += this.cart[id].qty * p.price;
		}
		return { count, total: Math.round(total * 100) / 100 };
	}
	
  allTags() {
    const set = new Set();
    this.products.forEach(p => (p.tags || []).forEach(t => set.add(t)));
    return [...set].sort();
  }
  visibleProducts() {
    const list = this.filterTag ?
    this.products.filter(p => (p.tags || []).includes(this.filterTag)) :
    this.products;
    const sorted = [...list];
    switch (this.sort) {
      case "price-asc":
        return sorted.sort((a, b) => a.price - b.price);
      case "price-desc":
        return sorted.sort((a, b) => b.price - a.price);
      case "name":
        return sorted.sort((a, b) => a.name.localeCompare(b.name));
      default:
        return sorted.sort((a, b) => (b.featured ? 1 : 0) - (a.featured ? 1 : 0));}

  }

  _persist() {
    this.storage.save(STORAGE_KEYS.cart, this.cart);
    this.storage.save(STORAGE_KEYS.favs, this.favs);
    this.storage.save(STORAGE_KEYS.sub, this.subscribed);
  }

	addToCart(id, options = {}) {
		const p = this.productsById[id];
		if (!p) return;
		if (!this.cart[id]) 
			this.cart[id] = { qty: 1, options, addedAt: Date.now() }; 
		else    {
			this.cart[id].qty += 1;
			this.cart[id].options = options;
		}
    this._persist();
    this.emit("cart:changed", { id, type: "add" });
  }
  
  changeQty(id, delta) {
    if (!this.cart[id]) return;
    this.cart[id].qty += delta;
    if (this.cart[id].qty <= 0) delete this.cart[id];
    this._persist();
    this.emit("cart:changed", { id, type: "qty" });
  }
  removeFromCart(id) {
    if (!this.cart[id]) return;
    delete this.cart[id];
    this._persist();
    this.emit("cart:changed", { id, type: "remove" });
  }
  toggleFav(id) {
    const i = this.favs.indexOf(id);
    const adding = i === -1;
    if (adding) this.favs.push(id);else
    this.favs.splice(i, 1);
    this._persist();
    this.emit("favs:changed", { id, adding });
  }
  setSubscription(email) {
    this.subscribed = email;
    this._persist();
    this.emit("sub:changed");
  }
  setFilter(tag) {
    if (this.filterTag === tag) return;
    this.filterTag = tag;
    this.emit("filter:changed");
  }
  setSort(value) {
    if (this.sort === value) return;
    this.sort = value;
    this.emit("sort:changed");
  }}


class ToastService {
  constructor(root) {
    this.root = root;
    this._t = null;
  }
  show(msg, duration = ANIM.toast) {
    this.root.textContent = msg;
    this.root.classList.add("show");
    clearTimeout(this._t);
    this._t = setTimeout(() => this.root.classList.remove("show"), duration);
  }}


class FlyAnimationService {
  static fly(sourceImg, targetEl) {
    if (!sourceImg || !targetEl) return;
    const s = sourceImg.getBoundingClientRect();
    const d = targetEl.getBoundingClientRect();
    if (s.width === 0 || d.width === 0) return;

    const ghost = sourceImg.cloneNode();
    ghost.className = "fly-ghost";
    Object.assign(ghost.style, {
      left: s.left + "px",
      top: s.top + "px",
      width: s.width + "px",
      height: s.height + "px" });

    document.body.appendChild(ghost);

    const dx = d.left + d.width / 2 - s.left - s.width / 2;
    const dy = d.top + d.height / 2 - s.top - s.height / 2;
    const peakY = Math.min(dy * 0.5 - 120, -60);

    const anim = ghost.animate(
    [
    { transform: "translate(0,0) scale(1) rotate(0deg)", opacity: 1 },
    {
      transform: `translate(${
      dx * 0.55
      }px, ${peakY}px) scale(0.55) rotate(-12deg)`,
      opacity: 0.95,
      offset: 0.55 },

    {
      transform: `translate(${dx}px, ${dy}px) scale(0.08) rotate(28deg)`,
      opacity: 0.1 }],


    {
      duration: ANIM.flyDuration,
      easing: "cubic-bezier(0.55, 0.05, 0.7, 0.9)",
      fill: "forwards" });


    anim.onfinish = () => {
      ghost.remove();
      targetEl.classList.add("receiving");
      setTimeout(() => targetEl.classList.remove("receiving"), ANIM.receiving);
    };
  }}


class SearchService {
  constructor(products) {
    this.products = products;
  }
  static _score(p, q) {
    let s = 0;
    if (p.name.toLowerCase().includes(q)) s += 10;
    (p.tags || []).forEach(t => {
      if (t.toLowerCase().includes(q)) s += 6;
    });
    (p.options || []).forEach((o) =>
    (o.values || []).forEach(v => {
      if ((v.value || "").toLowerCase().includes(q)) s += 3;
    }));

    if (p.desc.toLowerCase().includes(q)) s += 1;
    return s;
  }
  search(query, limit = 6) {
    const q = query.trim().toLowerCase();
    if (!q) return [];
    return this.products.
    map(p => ({ p, score: SearchService._score(p, q) })).
    filter(r => r.score > 0).
    sort((a, b) => b.score - a.score).
    slice(0, limit);
  }}


class OptionReader {
  static read(cardEl) {
    if (!cardEl) return {};
    const opts = {};
    cardEl.querySelectorAll("[data-opt]").forEach(group => {
      const key = group.dataset.opt;
      if (group.tagName === "INPUT") {
        if (group.value) opts[key] = group.value.toUpperCase();
        return;
      }
      const active = group.querySelector(".is-active");
      if (!active) return;
      let val = active.dataset.value;
      if (val === "custom") {
        const c1 = cardEl.querySelector('[data-custom="1"]')?.value.trim();
        const c2 = cardEl.querySelector('[data-custom="2"]')?.value.trim();
        val = (c1 || "?") + " · " + (c2 || "?");
      }
      opts[key] = val;
    });
    return opts;
  }}


class OverlayManager {
  constructor(bodyEl = document.body) {
    this.body = bodyEl;
    this.open = new Set();
  }
  register(id) {
    this.open.add(id);
    this.body.classList.add("has-overlay");
  }
  unregister(id) {
    this.open.delete(id);
    if (this.open.size === 0) this.body.classList.remove("has-overlay");
  }
  isOpen(id) {
    return this.open.has(id);
  }}


const OptionRenderers = {
  swatch(opt) {
    const row = tpl("#t-opt-swatch");
    row.querySelector(".label-text").textContent = opt.label;
    const group = row.querySelector("[data-opt]");
    group.dataset.opt = opt.key;
    opt.values.forEach(v => {
      const sw = tpl("#t-swatch-item");
      sw.style.setProperty("--c", v.color);
      sw.dataset.value = v.value;
      sw.setAttribute("aria-label", v.value + (v.disabled ? ", sold out" : ""));
      if (v.disabled) {
        sw.classList.add("is-disabled");
        sw.disabled = true;
      }
      group.appendChild(sw);
    });
    return row;
  },
  pill(opt) {
    const row = tpl("#t-opt-pill");
    row.querySelector(".label-text").textContent = opt.label;
    const group = row.querySelector("[data-opt]");
    group.dataset.opt = opt.key;
    opt.values.forEach(v => {
      const p = tpl("#t-opt-pill-item");
      p.textContent = v.label || v.value;
      p.dataset.value = v.value;
      if (v.custom) p.dataset.toggleCustom = "";
      group.appendChild(p);
    });
    return row;
  },
  letter(opt) {
    const row = tpl("#t-opt-letter");
    row.querySelector(".label-text").textContent = opt.label;
    return row;
  } };

const registerOptionRenderer = (type, fn) => {
  OptionRenderers[type] = fn;
};
const renderOption = opt => OptionRenderers[opt.type]?.(opt) ?? null;

const MetaRowRenderers = {
  stock(p) {
    if (!p.stockLimit) return null;
    const row = tpl("#t-opt-stock");
    const pct = Math.round(p.stock / p.stockLimit * 100);
    row.querySelector(".stock-fill").style.setProperty("--p", pct + "%");
    row.querySelector(
    ".stock-text").
    textContent = `${p.stock} / ${p.stockLimit} left`;
    return row;
  },
  note(p) {
    if (!p.optNote) return null;
    const row = tpl("#t-opt-note");
    row.querySelector(".opt-label").textContent = p.optNote;
    return row;
  } };


class Component {
  constructor(store) {
    this.store = store;
    this._unsubs = [];
  }
  listen(event, fn) {
    this._unsubs.push(this.store.on(event, fn));
  }
  destroy() {
    this._unsubs.forEach(u => u());
    this._unsubs = [];
  }}


	const BuyButton = {
		render(card, product, qty) {
		const buy = card.querySelector("[data-buy]");
		
		// Si le stock est à 0 ou négatif, on affiche le bouton "Complet"
		if (product.stock !== undefined && product.stock <= 0) {
			buy.replaceChildren(this._outOfStock());
			return;
		}

			// Sinon, comportement habituel (Ajouter ou Stepper)
			buy.replaceChildren(
			qty === 0 ? this._add(product) : this._stepper(product, qty)
			);
		},
	  
	  _add(product) {
		const node = tpl("#t-buy-add");
		node.dataset.add = product.id;
		node.querySelector(".add-arrow").textContent = `${formatPrice(product.price)} →`;
		return node;
	  },

	  _outOfStock() {
		const btn = document.createElement("button");
		btn.className = "btn-disabled";
		btn.disabled = true;
		btn.textContent = "Complet";
		btn.style.cssText = "width: 100%; padding: 8px; background: #e0e0e0; color: #757575; border: none;  cursor: not-allowed; font-weight: bold;height: 46px;border: 0;border-radius: 999px;";
		return btn;
	  },
	/* 
	<div class="buy" data-buy=""><button type="button" class="buy-add" data-add="ingenie:V_OTMARTIGUESACCUEIL_5796808">
			<span class="add-label">Ajouter au panier</span>
			<span class="add-arrow">1.95 € →</span>
		</button></div>

	*/
	
		/* Les lignes d'articles et le sélecteur de quantité */
		_stepper(product, qty) {
			const node = tpl("#t-buy-stepper");
			node.querySelector("[data-dec]").dataset.dec = product.id;
			node.querySelector("[data-inc]").dataset.inc = product.id;
			node.querySelector(".qty").textContent = qty;
			node.querySelector(".line-total").textContent = formatPrice(qty * product.price);
			return node;
		}
	};

/*

const BuyButton = {
  render(card, product, qty) {
    const buy = card.querySelector("[data-buy]");
    buy.replaceChildren(
    qty === 0 ? this._add(product) : this._stepper(product, qty));

  },
  _add(product) {
    const node = tpl("#t-buy-add");
    node.dataset.add = product.id;
    node.querySelector(".add-arrow").textContent = `${formatPrice(
    product.price)
    } →`;
	
    return node;
  },
  _stepper(product, qty) {
    const node = tpl("#t-buy-stepper");
    node.querySelector("[data-dec]").dataset.dec = product.id;
    node.querySelector("[data-inc]").dataset.inc = product.id;
    node.querySelector(".qty").textContent = qty;
    node.querySelector(".line-total").textContent = formatPrice(
    qty * product.price);

    return node;
  } };  

*/

/* Affichage des Cards - La partie qui affiche les vignettes de chaque activité de la base de donnée */ 

	const ProductCard = {
		build(product) 	{
			const card = tpl("#t-card");
			card.dataset.id = product.id;
			if (product.featured) card.classList.add("is-feature");

			const img = card.querySelector("img");
			img.src = product.img;
			img.alt = product.name;

			card.querySelector("h3").textContent = product.name;
			
			const priceEl = card.querySelector(".price");
			priceEl.textContent = formatPrice(product.price);
		
			if (product.priceSuffix) {
				const suffix = document.createElement("small");
				suffix.textContent = product.priceSuffix;
				priceEl.append(" ", suffix);
			}
		
			card.querySelector(".desc").textContent = product.desc;

			// --- AJOUT : Badge "Visite encadrée / guidée" ---
			if (product.isGuided) {
				const tag = card.querySelector(".tag");
				if (tag) {
					tag.textContent = "Visite guidée";
					tag.classList.add("tag--guided");
					tag.hidden = false;
				}		
			} else 
				if (product.tag) {
					const tag = card.querySelector(".tag");
					if (tag) {
						tag.textContent = product.tag.text;
						if (product.tag.type) tag.classList.add("tag--" + product.tag.type);
						tag.hidden = false;
					}
				}

			// --- AJOUT : Affichage des Tags & du Stock ---
			const metaContainer = document.createElement("div");
			metaContainer.className = "card-meta-info";
			metaContainer.style.cssText = "display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 8px; font-size: 0.85em; color: #666;";

			// 1. Liste des Tags
			if (product.tags && product.tags.length > 0) {
				const tagsEl = document.createElement("div");
				tagsEl.className = "card-tags";
				tagsEl.textContent = "🏷️ " + product.tags.join(" · ");
				metaContainer.appendChild(tagsEl);
			}

			// 2. Affichage du Stock
			if (product.stock !== undefined) {
				
				const stockEl = document.createElement("span");
				stockEl.className = "card-stock";
				stockEl.style.cssText = "margin-left: auto; font-weight: bold; padding: 2px 6px; border-radius: 4px; background: #f0f0f0;";
			  
				if (product.stock > 0) {
					stockEl.textContent = `${product.stock} dispo.`;
					stockEl.style.color = "#2e7d32"; // Vert
				} else {
					stockEl.textContent = "Complet";
					stockEl.style.color = "#c62828"; // Rouge
				}
				
				metaContainer.appendChild(stockEl);
			}

			// Insertion des infos sous la description
			const descEl = card.querySelector(".desc");

			if (descEl && descEl.parentNode) {
				descEl.parentNode.insertBefore(metaContainer, descEl.nextSibling);
			}

			// Gestion des options / metas d'origine
			const optsContainer = card.querySelector(".options");
		
			if (product.options?.length) {
				product.options.forEach(opt => {
					const row = renderOption(opt);
					if (row) optsContainer.appendChild(row);
		  
				});
				
				if (product.options.some(o => o.values?.some(v => v.custom))) {
					optsContainer.appendChild(tpl("#t-pronouns"));
				}
			} else {
			
				const meta = MetaRowRenderers.stock(product) || MetaRowRenderers.note(product);
				if (meta) optsContainer.appendChild(meta);
				
			}
			return card;
		}
	};



const buildLine = (product, { priceText, fillOpts, actionsNode }) => {
  const node = tpl("#t-line");
  node.dataset.line = product.id;
  const img = node.querySelector("img");
  img.src = product.img;
  img.alt = product.name;
  node.querySelector(".l-name").textContent = product.name;
  node.querySelector(".l-price").textContent = priceText;
  fillOpts(node.querySelector(".l-opts"));
  node.querySelector(".l-actions").replaceChildren(actionsNode);
  return node;
};

// Uses DOM nodes (not innerHTML) — keeps user-provided values safe from XSS.
const renderLineOpts = (container, options) => {
  container.textContent = "";
  const entries = Object.entries(options || {}).filter(([, v]) => v);
  if (!entries.length) {
    container.textContent = "No options";
    return;
  }
  entries.forEach(([k, v], i) => {
    if (i > 0) container.append(" · ");
    container.append(`${k}: `);
    const b = document.createElement("b");
    b.textContent = v;
    container.append(b);
  });
};

const buildCartLineActions = (id, qty) => {
  const frag = tplFragment("#t-cart-line-actions");
  frag.querySelector("[data-dec]").dataset.dec = id;
  frag.querySelector("[data-inc]").dataset.inc = id;
  frag.querySelector("[data-remove]").dataset.remove = id;
  frag.querySelector("output").textContent = qty;
  return frag;
};

const buildFavLineActions = (id, qtyInCart) => {
  const frag = tplFragment("#t-fav-line-actions");
  const primary = frag.querySelector("[data-add-from-fav]");
  primary.dataset.addFromFav = id;
  if (qtyInCart > 0) {
    primary.classList.add("is-in-cart");
    primary.textContent = `Dans le panier (${qtyInCart})`;
  } else {
    primary.textContent = "Ajouter au panier";
  }
  frag.querySelector("[data-rm-fav]").dataset.rmFav = id;
  return frag;
};

class GridComponent extends Component {
  constructor(store, { root, onProductClick }) {
    super(store);
    this.root = root;
    this.onProductClick = onProductClick || (() => {});
    this.cards = new Map();

    this.listen("filter:changed", () => this.render());
    this.listen("sort:changed", () => this.render());
    this.listen("cart:changed", ({ id }) => this._refreshBuy(id));
    this.listen("favs:changed", ({ id }) => this._refreshFav(id));

    this._bindOptionInteractions();
    this._bindProductImageClick();
    this.render();
  }

  _bindProductImageClick() {
    this.root.addEventListener("click", e => {
      const img = e.target.closest(".media img");
      if (!img) return;
      const id = img.closest("[data-id]")?.dataset.id;
      if (id) this.onProductClick(id);
    });
  }

  render() {
    this.root.replaceChildren();
    this.cards.clear();
    this.store.visibleProducts().forEach(p => {
      const card = ProductCard.build(p);
      this.cards.set(p.id, card);
      this.root.appendChild(card);
      BuyButton.render(card, p, this.store.cartQty(p.id));
      this._setFavState(card, this.store.isFav(p.id));
    });
  }

  cardImg(id) {
    return this.cards.get(id)?.querySelector(".media img");
  }
  readOptions(id) {
    return OptionReader.read(this.cards.get(id));
  }
  flashAdded(id) {
    const card = this.cards.get(id);
    if (!card) return;
    card.classList.add("is-just-added");
    setTimeout(() => card.classList.remove("is-just-added"), ANIM.justAdded);
  }
  highlight(id) {
    const card = this.cards.get(id);
    if (!card) return;
    setTimeout(() => {
      card.scrollIntoView({ behavior: "smooth", block: "center" });
      card.classList.add("is-hit");
      setTimeout(() => card.classList.remove("is-hit"), ANIM.hit);
    }, ANIM.scrollDelay);
  }

  _refreshBuy(id) {
    const card = this.cards.get(id);
    if (!card) return;
    BuyButton.render(card, this.store.getProduct(id), this.store.cartQty(id));
  }
  _refreshFav(id) {
    const card = this.cards.get(id);
    if (!card) return;
    this._setFavState(card, this.store.isFav(id));
  }
  _setFavState(card, isOn) {
    card.querySelector("[data-fav]").classList.toggle("is-on", isOn);
  }

  _bindOptionInteractions() {
    bindOptionInteractions(this.root);
  }}


const bindOptionInteractions = root => {
  root.addEventListener("click", e => {
    const target = e.target.closest(".swatch, .opt-pill");
    if (!target || target.disabled) return;
    const group = target.parentElement;
    if (!group.matches("[data-opt]")) return;

    group.
    querySelectorAll(".swatch, .opt-pill").
    forEach(el => el.classList.remove("is-active"));
    target.classList.add("is-active");

    const card = target.closest("[data-id]");
    const display = group.parentElement.querySelector("[data-opt-display]");
    if (display) {
      display.textContent =
      target.dataset.value === "custom" ?
      "custom" :
      (target.dataset.value || "").toLowerCase();
    }
    const pronouns = card?.querySelector(".custom-pronouns");
    if (pronouns) {
      pronouns.hidden = !(target.dataset.toggleCustom !== undefined);
    }
  });
};

class FilterChipsComponent extends Component {
  constructor(store, { root }) {
    super(store);
    this.root = root;
    root.addEventListener("click", e => {
      const chip = e.target.closest(".chip");
      if (!chip) return;
      store.setFilter(chip.dataset.tag);
      this.render();
    });
    this.render();
  }
  
  render() {
    const mk = (label, value, active) => {
      const b = document.createElement("button");
      b.type = "button";
      b.className = "chip" + (active ? " is-active" : "");
      b.textContent = label;
      b.dataset.tag = value;
      return b;
    };
    this.root.replaceChildren();
	
	/* J'affiche là le nombre d'article de Youbook */
    this.root.appendChild(mk(`TOUT · ${this.store.products.length}`, "", this.store.filterTag === ""));

    this.store.
    allTags().
    forEach(t => this.root.appendChild(mk(t, t, this.store.filterTag === t)));
  }}


class SortComponent extends Component {
  constructor(store, { root }) {
    super(store);
    root.addEventListener("change", e => store.setSort(e.target.value));
  }}


class HeaderBadgesComponent extends Component {
  constructor(store, opts) {
    super(store);
    Object.assign(this, opts);
    this.listen("cart:changed", e => {
      this.renderCart();
      if (e?.type === "add") bump(this.cartPill);
    });
    this.listen("favs:changed", () => {
      this.renderFavs();
      bump(this.favPill);
    });
    this.renderCart();
    this.renderFavs();
  }
  renderCart() {
    const { count, total } = this.store.cartTotals();
    this.countNodes().forEach(el => el.textContent = count);
    this.totalNodes().forEach(el => el.textContent = total);
    this.cartBar.hidden = count === 0;
    this.cartPill.classList.toggle("is-empty", count === 0);
  }
  renderFavs() {
    this.favCountNodes().forEach(
    el => el.textContent = this.store.favs.length);

    this.favPill.classList.toggle("is-empty", this.store.favs.length === 0);
  }}


class Drawer {
  constructor({ root, id, overlay, openSelectors, closeSelectors }) {
    this.root = root;
    this.id = id;
    this.overlay = overlay;
    document.addEventListener("click", e => {
      if (openSelectors.some(s => e.target.closest(s))) this.open();else
      if (closeSelectors.some(s => e.target.closest(s))) this.close();
    });
  }
  open() {
    this.root.classList.add("is-open");
    this.overlay.register(this.id);
  }
  close() {
    this.root.classList.remove("is-open");
    this.overlay.unregister(this.id);
  }
  isOpen() {
    return this.overlay.isOpen(this.id);
  }}


class CartDrawerComponent extends Component {
  constructor(store, { itemsEl }) {
    super(store);
    this.itemsEl = itemsEl;
    this.listen("cart:changed", () => this.render());
    this.render();
  }
  render() {
    const ids = Object.keys(this.store.cart);
    if (ids.length === 0) {
      this.itemsEl.replaceChildren(tpl("#t-empty-cart"));
      return;
    }
    this.itemsEl.replaceChildren();
    ids.forEach(id => {
      const line = this.store.cart[id];
      const p = this.store.getProduct(id);
      if (!p) return;
      this.itemsEl.appendChild(
      buildLine(p, {
        priceText: formatPrice(line.qty * p.price),
        fillOpts: el => renderLineOpts(el, line.options),
        actionsNode: buildCartLineActions(id, line.qty) }));


    });
  }}


class FavsDrawerComponent extends Component {
  constructor(store, { itemsEl, moveAllBtn }) {
    super(store);
    this.itemsEl = itemsEl;
    this.moveAllBtn = moveAllBtn;
    this.listen("favs:changed", () => this.render());
    this.listen("cart:changed", () => this.render());
    this.render();
  }
  render() {
    if (this.store.favs.length === 0) {
      this.moveAllBtn.style.display = "none";
      this.itemsEl.replaceChildren(tpl("#t-empty-favs"));
      return;
    }
    this.moveAllBtn.style.display = "";
    this.itemsEl.replaceChildren();
    this.store.favs.forEach(id => {
      const p = this.store.getProduct(id);
      if (!p) return;
      const qtyInCart = this.store.cartQty(id);
      const tagsText = (p.tags || []).join(" · ");
      this.itemsEl.appendChild(
      buildLine(p, {
        priceText: formatPrice(p.price),
        fillOpts: el => {
          el.textContent = tagsText;
        },
        actionsNode: buildFavLineActions(id, qtyInCart) }));


    });
  }}


class ProductDetailComponent extends Component {
  constructor(store, { drawer, root, panel, titleEl, footEl }) {
    super(store);
    this.drawer = drawer;
    this.root = root;
    this.panel = panel;
    this.titleEl = titleEl;
    this.footEl = footEl;
    this.currentId = null;

    this.listen("cart:changed", ({ id }) => {
      if (id === this.currentId) this._refreshBuy();
    });
    this.listen("favs:changed", ({ id }) => {
      if (id === this.currentId) this._refreshFav();
    });

    bindOptionInteractions(this.root);
  }

  show(id) {
    const p = this.store.getProduct(id);
    if (!p) return;
    this.currentId = id;
    this.panel.dataset.id = id;
    this._render(p);
    this.drawer.open();
  }

  _render(p) {
    this.titleEl.textContent = p.name;

    const card = tpl("#t-product-detail");

    const img = card.querySelector(".pd-img");
    img.src = p.img;
    img.alt = p.name;

    const priceEl = card.querySelector(".pd-price");
    priceEl.textContent = formatPrice(p.price);
    if (p.priceSuffix) {
      const suffix = document.createElement("small");
      suffix.textContent = p.priceSuffix;
      priceEl.append(" ", suffix);
    }

    card.querySelector(".pd-desc").textContent = p.desc;

    if (p.tag) {
      const tag = card.querySelector(".tag");
      tag.textContent = p.tag.text;
      if (p.tag.type) tag.classList.add("tag--" + p.tag.type);
      tag.hidden = false;
    }

    const tagsContainer = card.querySelector(".pd-tags");
    (p.tags || []).forEach(t => {
      const chip = document.createElement("span");
      chip.className = "pd-tag";
      chip.textContent = t;
      tagsContainer.appendChild(chip);
    });

    const optsContainer = card.querySelector(".pd-options");
    if (p.options?.length) {
      p.options.forEach(opt => {
        const row = renderOption(opt);
        if (row) optsContainer.appendChild(row);
      });
      if (p.options.some(o => o.values?.some(v => v.custom))) {
        optsContainer.appendChild(tpl("#t-pronouns"));
      }
    } else {
      const meta = MetaRowRenderers.stock(p) || MetaRowRenderers.note(p);
      if (meta) optsContainer.appendChild(meta);
    }

    card.
    querySelector("[data-fav]").
    classList.toggle("is-on", this.store.isFav(p.id));

    this.root.replaceChildren(card);
    BuyButton.render(this.footEl, p, this.store.cartQty(p.id));
  }

  _refreshBuy() {
    if (!this.currentId) return;
    BuyButton.render(
    this.footEl,
    this.store.getProduct(this.currentId),
    this.store.cartQty(this.currentId));

  }
  _refreshFav() {
    const card = this.root.firstElementChild;
    if (!card) return;
    card.
    querySelector("[data-fav]").
    classList.toggle("is-on", this.store.isFav(this.currentId));
  }}


class SearchComponent extends Component {
  constructor(store, opts) {
    super(store);
    Object.assign(this, opts);
    this.id = "search";
    this.service = new SearchService(store.products);
    this.currentResults = [];
    this.activeIdx = -1;
    this._timer = null;

    document.addEventListener("click", e => {
      if (this.openSelectors.some(s => e.target.closest(s))) return this.open();
      if (this.closeSelectors.some(s => e.target.closest(s)))
      return this.close();
      if (e.target.closest("[data-search-clear]")) return this.clear();

      const sugg = e.target.closest("[data-suggest]");
      if (sugg) {
        this.inputEl.value = sugg.dataset.suggest;
        this.clearBtn.hidden = false;
        this.renderResults(this.inputEl.value);
        this.inputEl.focus();
        return;
      }
      const result = e.target.closest(".search-result");
      if (result) this._select(result.dataset.id);
    });

    this.inputEl.addEventListener("input", () => {
      clearTimeout(this._timer);
      this.clearBtn.hidden = !this.inputEl.value;
      this._timer = setTimeout(
      () => this.renderResults(this.inputEl.value),
      ANIM.searchDebounce);

    });

    this.inputEl.addEventListener("keydown", e => {
      if (e.key === "ArrowDown") {
        e.preventDefault();
        this._setActive(this.activeIdx + 1);
      } else if (e.key === "ArrowUp") {
        e.preventDefault();
        this._setActive(this.activeIdx - 1);
      } else if (e.key === "Enter" && this.currentResults[this.activeIdx]) {
        e.preventDefault();
        this._select(this.currentResults[this.activeIdx].p.id);
      }
    });
  }

  open() {
    this.overlayEl.classList.add("is-open");
    this.overlay.register(this.id);
    setTimeout(() => this.inputEl.focus(), ANIM.searchFocusDelay);
    this.renderResults("");
  }
  close() {
    this.overlayEl.classList.remove("is-open");
    this.overlay.unregister(this.id);
    this.inputEl.value = "";
    this.clearBtn.hidden = true;
  }
  isOpen() {
    return this.overlay.isOpen(this.id);
  }
  clear() {
    this.inputEl.value = "";
    this.clearBtn.hidden = true;
    this.renderResults("");
    this.inputEl.focus();
  }

  renderResults(q) {
    this.currentResults = this.service.search(q);
    this.activeIdx = this.currentResults.length ? 0 : -1;

    if (!q) return this._renderEmpty();
    if (!this.currentResults.length) return this._renderNoResults(q);
    this._renderResultList(q);
  }

  _renderEmpty() {
    const node = tpl("#t-search-empty");
    const slot = node.querySelector("[data-suggestions]");
    SEARCH_SUGGESTIONS.forEach(s => {
      const btn = tpl("#t-search-suggestion");
      btn.dataset.suggest = s;
      btn.textContent = s;
      slot.appendChild(btn);
    });
    this.resultsEl.replaceChildren(node);
  }

  _renderNoResults(q) {
    const node = tpl("#t-search-no-results");
    node.querySelector("[data-query]").textContent = q;
    this.resultsEl.replaceChildren(node);
  }

  _renderResultList(q) {
    this.resultsEl.replaceChildren();
    const label = document.createElement("div");
    label.className = "search-section-label";
    label.textContent = `Products · ${this.currentResults.length}`;
    this.resultsEl.appendChild(label);

    this.currentResults.forEach((r, i) => {
      const node = tpl("#t-search-result");
      node.dataset.id = r.p.id;
      if (i === this.activeIdx) node.classList.add("is-active");
      node.querySelector("img").src = r.p.img;
      // highlightTerm returns escaped text + <mark>, so innerHTML is safe here.
      node.querySelector(".sr-name").innerHTML = highlightTerm(r.p.name, q);
      this._fillResultMeta(node.querySelector(".sr-meta"), r.p);
      node.querySelector(".sr-price").textContent = formatPrice(r.p.price);
      this.resultsEl.appendChild(node);
    });
  }

  _fillResultMeta(container, product) {
    container.textContent = "";
    const parts = [(product.tags || []).join(" · ")];
    if (product.options?.length) {
      const total = product.options.
      flatMap(o => o.values?.length || 1).
      reduce((a, b) => a + b, 0);
      parts.push(`${total} options`);
    }
    parts.filter(Boolean).forEach((text, i) => {
      if (i > 0) {
        const dot = document.createElement("span");
        dot.className = "dot";
        container.appendChild(dot);
      }
      container.appendChild(document.createTextNode(text));
    });
  }

  _setActive(idx) {
    const items = $$(".search-result", this.resultsEl);
    if (!items.length) return;
    this.activeIdx = (idx + items.length) % items.length;
    items.forEach((el, i) =>
    el.classList.toggle("is-active", i === this.activeIdx));

    items[this.activeIdx].scrollIntoView({ block: "nearest" });
  }

  _select(id) {
    this.close();
    this.onSelect?.(id);
  }}


class MobileMenuComponent {
  constructor({ navEl, toggleSelector }) {
    document.addEventListener("click", e => {
      const btn = e.target.closest(toggleSelector);
      if (btn) {
        const open = navEl.classList.toggle("is-open");
        btn.classList.toggle("is-open", open);
        btn.setAttribute("aria-label", open ? "Close menu" : "Open menu");
        return;
      }
      if (e.target.closest("[data-mobile-nav] a")) {
        navEl.classList.remove("is-open");
        $(toggleSelector)?.classList.remove("is-open");
      }
    });
  }}


class NewsletterComponent extends Component {
  constructor(store, opts) {
    super(store);
    Object.assign(this, opts);
    this.listen("sub:changed", () => this.render());

    this.signupEl.addEventListener("submit", async e => {
      e.preventDefault();
      const email = this.signupEl.querySelector("input").value.trim();
      if (!email) return;
      const btn = this.signupEl.querySelector("button");
      const originalText = btn.textContent;
      btn.disabled = true;
      btn.textContent = "Brewing…";
      await new Promise(r => setTimeout(r, ANIM.signupFake));
      this.store.setSubscription(email);
      btn.disabled = false;
      btn.textContent = originalText;
      this.toast.show("Bienvenue dans la liste");
    });

    this.unsubBtn.addEventListener("click", () => {
      this.store.setSubscription(null);
      this.toast.show("Désabonné");
    });

    this.render();
  }
  render() {
    if (this.store.subscribed) {
      this.signupEl.classList.add("is-hidden");
      this.subscribedEl.classList.add("is-active");
      this.emailEl.textContent = this.store.subscribed;
    } else {
      this.signupEl.classList.remove("is-hidden");
      this.subscribedEl.classList.remove("is-active");
    }
  }}


	class HotkeysComponent {
	  constructor({ search, cartDrawer, favsDrawer, productDrawer }) {
		document.addEventListener("keydown", e => {
		  if (e.key === "Escape") {
			if (search.isOpen()) return search.close();
			if (productDrawer.isOpen()) return productDrawer.close();
			if (favsDrawer.isOpen()) return favsDrawer.close();
			if (cartDrawer.isOpen()) return cartDrawer.close();
		  }
		  if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "k") {
			e.preventDefault();
			search.open();
		  }
		  if (
		  e.key === "/" &&
		  !["INPUT", "TEXTAREA"].includes(document.activeElement?.tagName))
		  {
			e.preventDefault();
			search.open();
		  }
		});
	  }}
	  
	  


	class CartInteractions {
	  constructor({ store, grid, favsItemsEl, flyTargets, toast }) {
		const addOne = (id, { sourceImg, sourceCard } = {}) => {
			const p = store.getProduct(id);
			if (!p) return;
			const opts = sourceCard ?
			OptionReader.read(sourceCard) :
			grid.readOptions(id);
			store.addToCart(id, opts);
			grid.flashAdded(id);
			FlyAnimationService.fly(sourceImg || grid.cardImg(id), flyTargets.cart);
			toast.show(`Ajouté · ${p.name}`);
		};
		document.addEventListener("click", e => {
			const add = e.target.closest("[data-add]");
			if (add) {
				const card = add.closest("[data-id]");
				const id = card?.dataset.id ?? add.dataset.add;
				return addOne(id, {	sourceCard: card, sourceImg: card?.querySelector("img") });
			}

		  const inc = e.target.closest("[data-inc]");
		  if (inc) return store.changeQty(inc.dataset.inc, +1);
		  const dec = e.target.closest("[data-dec]");
		  if (dec) return store.changeQty(dec.dataset.dec, -1);

		  const rm = e.target.closest("[data-remove]");
		  if (rm) {
			const name = store.getProduct(rm.dataset.remove)?.name;
			store.removeFromCart(rm.dataset.remove);
			if (name) toast.show(`Retirer · ${name}`);
			return;
		  }

		  const fav = e.target.closest("[data-fav]");
		  if (fav) {
			e.preventDefault();
			const card = fav.closest("[data-id]");
			if (!card) return;
			const id = card.dataset.id;
			const wasFav = store.isFav(id);
			store.toggleFav(id);
			if (!wasFav) {
			  FlyAnimationService.fly(card.querySelector("img"), flyTargets.fav);
			}
			toast.show(
			wasFav ? "Retiré des favoris" : `Sauvegardé · ${store.getProduct(id).name}`);

			return;
		  }

		  const addFav = e.target.closest("[data-add-from-fav]");
		  if (addFav) {
			const id = addFav.dataset.addFromFav;
			const sourceImg = addFav.closest(".line")?.querySelector("img");
			return addOne(id, { sourceImg });
		  }

		  const rmFav = e.target.closest("[data-rm-fav]");
		  if (rmFav) {
			store.toggleFav(rmFav.dataset.rmFav);
			toast.show("Retiré des favoris");
			return;
		  }

		  if (e.target.closest("[data-move-all-favs]")) {
			const ids = [...store.favs];
			ids.forEach((id, i) =>
			setTimeout(() => {
			  const src = favsItemsEl.querySelector(`[data-line="${id}"] img`);
			  addOne(id, { sourceImg: src });
			}, i * ANIM.staggerAdd));

		  }
		});
	  }}


(function boot() {
  const storage = new StorageAdapter();
  const store = new Store({ storage, seed: PRODUCTS });
  const overlay = new OverlayManager();
  const toast = new ToastService($("[data-toast]"));

	const els = {
		cartBar: $("[data-cart-bar]"),
		cartPill: $(".cart-pill"),
		cartBtn: $(".cart-btn"),
		favPill: $(".fav-pill"),
		favBtn: $(".fav-btn"),
		authDrawer: $("[data-auth-drawer]"), /* Menu Slider Membre */
		cartDrawer: $("[data-cart-drawer]"),
		favsDrawer: $("[data-favs-drawer]"),
		productDrawer: $("[data-product-drawer]"),
		productDetail: $("[data-product-detail]"),
		productPanel: $("[data-pd-panel]"),
		productTitle: $("[data-pd-title]"),
		productFoot: $("[data-pd-foot]"),
		cartItems: $("[data-cart-items]"),
		favsItems: $("[data-favs-items]"),
		moveAllFavs: $("[data-move-all-favs]"),
		searchOverlay: $("[data-search-overlay]"),
		searchInput: $("[data-search-input]"),
		searchResults: $("[data-search-results]"),
		searchClear: $("[data-search-clear]"),
		grid: $("[data-grid]"),
		filterChips: $("[data-filter-chips]"),
		sortSel: $("[data-sort]"),
		mobileNav: $("[data-mobile-nav]"),
		signup: $("[data-signup]"),
		subscribed: $("[data-subscribed]"),
		subEmail: $("[data-sub-email]"),
		unsub: $("[data-unsubscribe]") 
	};


	const flyTargets = { cart: els.cartBtn, fav: els.favBtn };

	const authDrawer = new Drawer({
	  root: els.authDrawer,
	  id: "auth",
	  overlay,
	  openSelectors: ["[data-open-auth]"], // <-- Déclenchera l'ouverture sur ton bouton !
	  closeSelectors: ["[data-close-auth]"]
	});



	const cartDrawer = new Drawer({
		root: els.cartDrawer,
		id: "cart",
		overlay,
		openSelectors: ["[data-open-cart]"],
		closeSelectors: ["[data-close-cart]"] 
	});

  const favsDrawer = new Drawer({
    root: els.favsDrawer,
    id: "favs",
    overlay,
    openSelectors: ["[data-open-favs]"],
    closeSelectors: ["[data-close-favs]"] });

  const productDrawer = new Drawer({
    root: els.productDrawer,
    id: "product",
    overlay,
    openSelectors: [],
    closeSelectors: ["[data-close-product]"] });


  const productDetail = new ProductDetailComponent(store, {
    drawer: productDrawer,
    root: els.productDetail,
    panel: els.productPanel,
    titleEl: els.productTitle,
    footEl: els.productFoot });


  const grid = new GridComponent(store, {
    root: els.grid,
    onProductClick: id => productDetail.show(id) });


  new FilterChipsComponent(store, { root: els.filterChips });
  new SortComponent(store, { root: els.sortSel });
  new HeaderBadgesComponent(store, {
    cartBar: els.cartBar,
    cartPill: els.cartPill,
    favPill: els.favPill,
    countNodes: () => $$("[data-cart-count]"),
    totalNodes: () => $$("[data-cart-total]"),
    favCountNodes: () => $$("[data-fav-count]") });

  new CartDrawerComponent(store, { itemsEl: els.cartItems });
  new FavsDrawerComponent(store, {
    itemsEl: els.favsItems,
    moveAllBtn: els.moveAllFavs });


  const search = new SearchComponent(store, {
    overlayEl: els.searchOverlay,
    inputEl: els.searchInput,
    resultsEl: els.searchResults,
    clearBtn: els.searchClear,
    overlay,
    openSelectors: ["[data-open-search]"],
    closeSelectors: ["[data-close-search]"],
    onSelect: id => productDetail.show(id) //grid.highlight(id)
  });

  new MobileMenuComponent({
    navEl: els.mobileNav,
    toggleSelector: "[data-toggle-menu]" });

  new NewsletterComponent(store, {
    signupEl: els.signup,
    subscribedEl: els.subscribed,
    emailEl: els.subEmail,
    unsubBtn: els.unsub,
    toast });


  new CartInteractions({
    store,
    grid,
    favsItemsEl: els.favsItems,
    flyTargets,
    toast });


  new HotkeysComponent({ search, cartDrawer, favsDrawer, productDrawer });
})();

</script>
 
 
 
</body>
</html>