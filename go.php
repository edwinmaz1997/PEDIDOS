<?php
/**
 * Router de slugs de negocios
 * Uso: nuevaexpress.com/{slug} → /cliente/pedido.html?business={id}
 */
require_once __DIR__ . '/api/config/database.php';

$slug = trim($_GET['slug'] ?? '', '/');

if (!$slug || !preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
    header('Location: /');
    exit;
}

try {
    $db   = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT id FROM businesses WHERE slug = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$slug]);
    $business = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    header('Location: /');
    exit;
}

if (!$business) {
    header('Location: /404.html');
    exit;
}

header('Location: /cliente/pedido.html?business=' . $business['id']);
exit;
