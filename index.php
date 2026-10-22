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
	
	<?php 
		include 'js/youscript.js';
	?>	
 
</body>
</html>
