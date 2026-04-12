<?php
/**
 * Script para instalar posições de anúncios e recursos
 * Execute: php install_pricing_rules.php
 */

require_once __DIR__ . '/../src/Core/Database.php';

use App\Core\Database;

$db = Database::getConnection();

$rules = [
    // Publicidade (advertiser)
    ['advertiser', 'footer_banner', 'Banner Rodapé', 'monthly', 0, 0.00, 79.90, 19.90],
    ['advertiser', 'header_banner', 'Banner Cabeçalho', 'monthly', 0, 0.00, 149.90, 29.90],
    ['advertiser', 'spotlight_ad', 'Banner Destaque (Spotlight)', 'monthly', 0, 0.00, 249.90, 49.90],
    ['advertiser', 'infeed_ad', 'Anúncio no Feed', 'monthly', 0, 0.00, 129.90, 24.90],
    ['advertiser', 'details_ad', 'Banner Página de Detalhes', 'monthly', 0, 0.00, 89.90, 19.90],
    ['advertiser', 'home_banner', 'Banner Home', 'monthly', 0, 0.00, 199.90, 39.90],
    ['advertiser', 'sidebar_banner', 'Banner Sidebar', 'monthly', 0, 0.00, 99.90, 19.90],
    ['advertiser', 'video_ad', 'Vídeo Promocional', 'monthly', 0, 0.00, 299.90, 59.90],
    ['advertiser', 'publish_ad', 'Publicar Anúncio', 'monthly', 0, 0.00, 99.90, 0.00],
    ['advertiser', 'sponsored', 'Post Patrocinado', 'monthly', 0, 49.90, 199.90, 0.00],
    
    // Freights (para empresas)
    ['freights', 'publish', 'Publicar Frete', 'monthly', 3, 9.90, 49.90, 0.00],
    ['freights', 'boost', 'Destaque Frete', 'per_use', 0, 19.90, 79.90, 0.00],
    ['freights', 'priority_boost', 'Destaque Prioritário', 'per_use', 0, 24.90, 89.90, 0.00],
    ['freights', 'urgent', 'Frete Urgente', 'per_use', 0, 14.90, 59.90, 0.00],
    ['freights', 'urgent_badge', 'Selo Frete Urgente', 'per_use', 0, 9.90, 49.90, 0.00],
    ['freights', 'auto_renew', 'Renovação Automática', 'monthly', 0, 0.00, 9.90, 0.00],
    
    // Marketplace
    ['marketplace', 'publish_listing', 'Publicar Anúncio', 'monthly', 3, 4.90, 29.90, 0.00],
    ['marketplace', 'multi_images', 'Múltiplas Imagens (Galeria)', 'monthly', 0, 0.00, 19.90, 0.00],
    ['marketplace', 'gallery', 'Galeria de Fotos', 'monthly', 0, 0.00, 19.90, 0.00],
    ['marketplace', 'video_listing', 'Vídeo no Anúncio', 'monthly', 0, 14.90, 69.90, 0.00],
    ['marketplace', 'featured_listing', 'Anúncio em Destaque', 'per_use', 0, 9.90, 59.90, 0.00],
    ['marketplace', 'bump', 'Renovar Anúncio (Bump)', 'per_use', 0, 2.90, 0.00, 0.00],
    ['marketplace', 'verified_seller', 'Selo Vendedor Verificado', 'monthly', 0, 0.00, 39.90, 0.00],
    
    // Driver Pro (para motoristas)
    ['driver', 'document_verification', 'Verificação de Identidade', 'monthly', 0, 0.00, 9.90, 0.00],
    ['driver', 'featured_profile', 'Perfil em Destaque', 'monthly', 0, 0.00, 29.90, 0.00],
    ['driver', 'priority_support', 'Suporte Prioritário', 'monthly', 0, 0.00, 19.90, 0.00],
    ['driver', 'radar_highlight', 'Destaque no Radar', 'monthly', 0, 0.00, 19.90, 0.00],
    ['driver', 'match_priority', 'Prioridade no Match', 'monthly', 0, 0.00, 24.90, 0.00],
    
    // Cotações (para empresas)
    ['quotes', 'request_quote', 'Solicitar Cotação', 'monthly', 5, 14.90, 89.90, 0.00],
    
    // Company Pro (para empresas)
    ['company_pro', 'identity_verification', 'Verificação de Identidade', 'monthly', 0, 0.00, 19.90, 0.00],
    ['quotes', 'receive_quotes', 'Receber Cotações', 'monthly', 0, 9.90, 59.90, 0.00],
    ['quotes', 'unlimited_quotes', 'Cotações Ilimitadas', 'monthly', 0, 0.00, 149.90, 0.00],
];

$inserted = 0;
$skipped = 0;

foreach ($rules as $rule) {
    list($moduleKey, $featureKey, $featureName, $pricingType, $freeLimit, $pricePerUse, $priceMonthly, $priceDaily) = $rule;
    
    // Verifica se já existe
    $stmt = $db->prepare("SELECT id FROM pricing_rules WHERE feature_key = ?");
    $stmt->execute([$featureKey]);
    
    if ($stmt->fetch()) {
        echo "SKIP: $featureKey já existe\n";
        $skipped++;
        continue;
    }
    
    $stmt = $db->prepare("
        INSERT INTO pricing_rules 
        (module_key, feature_key, feature_name, pricing_type, free_limit, price_per_use, price_monthly, price_daily, is_active, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
    ");
    
    $stmt->execute([$moduleKey, $featureKey, $featureName, $pricingType, $freeLimit, $pricePerUse, $priceMonthly, $priceDaily]);
    echo "INSERT: $featureKey\n";
    $inserted++;
}

echo "\n=== RESULTADO ===\n";
echo "Inseridos: $inserted\n";
echo "Ignorados: $skipped\n";

// Lista todos
echo "\n=== TODAS AS REGRAS ===\n";
$all = $db->query("SELECT feature_key, feature_name, pricing_type, free_limit, price_monthly FROM pricing_rules ORDER BY module_key, feature_key")->fetchAll(PDO::FETCH_ASSOC);
foreach ($all as $r) {
    echo sprintf("%-20s %-30s %-10s R$ %.2f/mês\n", 
        $r['feature_key'], 
        $r['feature_name'], 
        $r['pricing_type'],
        $r['price_monthly']
    );
}
