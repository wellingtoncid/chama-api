<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=chama_frete_dev', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== ATUALIZANDO MONETIZAÇÃO ===\n\n";

// 1. REMOVER OBSOLETOS
$pdo->exec('DELETE FROM pricing_rules WHERE id IN (30, 70, 71, 14, 61, 74, 45, 43)');
echo "1. Removidos pricing_rules obsoletos\n";

// 2. ATUALIZAR FRETES
$pdo->exec("UPDATE pricing_rules SET price_per_use = 9.90, price_monthly = 49.90, price_daily = 19.90 WHERE feature_key = 'publish'");
$pdo->exec("UPDATE pricing_rules SET price_per_use = 9.90, price_monthly = 79.90, price_daily = 29.90, feature_name = 'Destaque Frete' WHERE feature_key = 'boost'");
$pdo->exec("UPDATE pricing_rules SET price_per_use = 14.90, price_monthly = 49.90, price_daily = 0, feature_name = 'Frete Urgente' WHERE feature_key = 'urgent'");
$pdo->exec("UPDATE pricing_rules SET price_per_use = 6.90, feature_name = 'Boost Frete (Renovação +7 dias)' WHERE feature_key = 'freight_renew'");
echo "2. Atualizados fretes\n";

// 3. ATUALIZAR MARKETPLACE
$pdo->exec("UPDATE pricing_rules SET price_per_use = 9.90, price_monthly = 29.90, price_daily = 9.90 WHERE feature_key = 'publish_listing'");
$pdo->exec("UPDATE pricing_rules SET price_per_use = 9.90, price_monthly = 59.90, price_daily = 19.90 WHERE feature_key = 'featured_listing'");
$pdo->exec("UPDATE pricing_rules SET price_per_use = 6.90, price_monthly = 0, price_daily = 0, feature_name = 'Boost Produto (Renovação +7 dias)' WHERE feature_key = 'bump'");
echo "3. Atualizados marketplace\n";

// 4. ATUALIZAR DRIVER (verificação one-time 1 ano)
$pdo->exec("UPDATE pricing_rules SET price_per_use = 19.90, price_monthly = 0, price_daily = 0, pricing_type = 'one_time', feature_name = 'Verificação de Identidade (1 ano)' WHERE feature_key = 'document_verification'");
$pdo->exec("UPDATE pricing_rules SET price_per_use = 0, price_monthly = 9.90, price_daily = 0, feature_name = 'Destaque nas Buscas (Semanal)' WHERE feature_key = 'featured_profile'");
$pdo->exec("UPDATE pricing_rules SET price_per_use = 0, price_monthly = 9.90, price_daily = 0, feature_name = 'Destaque no Radar (Semanal)' WHERE feature_key = 'radar_highlight'");
echo "4. Atualizados driver\n";

// 5. ATUALIZAR COMPANY PRO
$pdo->exec("UPDATE pricing_rules SET price_per_use = 39.90, price_monthly = 0, price_daily = 0, pricing_type = 'one_time', feature_name = 'Verificação de Identidade CNPJ (1 ano)' WHERE feature_key = 'identity_verification' AND module_key = 'company_pro'");
echo "5. Atualizados company_pro\n";

// 6. COTAÇÕES - marcar como inativo (Em Breve)
$pdo->exec("UPDATE pricing_rules SET is_active = 0 WHERE module_key = 'quotes'");
echo "6. Cotações marcado como inativo (Em Breve)\n";

// 7. CRIAR novos recursos se não existirem
$pdo->exec("INSERT IGNORE INTO pricing_rules (module_key, feature_key, feature_name, pricing_type, free_limit, price_per_use, price_monthly, price_daily, duration_days, is_active, created_at, updated_at) VALUES ('freights', 'freight_avulso', 'Anúncio Avulso de Frete', 'per_use', 0, 9.90, 0, 0, 7, 1, NOW(), NOW())");
$pdo->exec("INSERT IGNORE INTO pricing_rules (module_key, feature_key, feature_name, pricing_type, free_limit, price_per_use, price_monthly, price_daily, duration_days, is_active, created_at, updated_at) VALUES ('marketplace', 'product_avulso', 'Anúncio Avulso Marketplace', 'per_use', 0, 9.90, 0, 0, 7, 1, NOW(), NOW())");
echo "7. Criados novos recursos avulsos\n";

// 8. LIMPAR PLANOS antigos (manter só publicidade)
$pdo->exec('DELETE FROM plans WHERE id NOT IN (17, 18, 19)');
echo "8. Limpos planos antigos\n";

// 9. CRIAR PLANOS DE FRETES
$pdo->exec("INSERT INTO plans (name, slug, type, billing_type, price, duration_days, limit_ads_active, has_verification_badge, priority_support, description, features, active, is_highlighted, sort_order, category, created_at, updated_at) VALUES ('Gratuito', 'frete-gratuito', 'freight_list', 'one_time', 0, 30, 1, 0, 0, '1 anúncio de freight por mês', '[\"Ver fretes publicados\",\"Aceitar convites\",\"Marketplace básico\"]', 1, 0, 1, 'freight_subscription', NOW(), NOW())");

$pdo->exec("INSERT INTO plans (name, slug, type, billing_type, price, price_quarterly, price_semiannual, price_yearly, discount_quarterly, discount_semiannual, discount_yearly, duration_days, limit_ads_active, has_verification_badge, priority_support, description, features, active, is_highlighted, sort_order, category, created_at, updated_at) VALUES ('Starter', 'frete-starter', 'freight_list', 'subscription', 29.90, 80, 149, 269, 10, 17, 25, 30, 3, 0, 0, '3 anúncios de freight por mês', '[\"Tudo do Gratuito\",\"3 fretes/mês\",\"Chat com motoristas\"]', 1, 0, 2, 'freight_subscription', NOW(), NOW())");

$pdo->exec("INSERT INTO plans (name, slug, type, billing_type, price, price_quarterly, price_semiannual, price_yearly, discount_quarterly, discount_semiannual, discount_yearly, duration_days, limit_ads_active, has_verification_badge, priority_support, description, features, active, is_highlighted, sort_order, category, created_at, updated_at) VALUES ('Pró', 'frete-pro', 'freight_list', 'subscription', 49.90, 134, 249, 449, 10, 17, 25, 30, 5, 0, 0, '5 anúncios + 1 destaque/mês', '[\"Tudo do Starter\",\"5 fretes/mês\",\"1 Destaque gratuito/mês\",\"Ver dados de contato\"]', 1, 1, 3, 'freight_subscription', NOW(), NOW())");

$pdo->exec("INSERT INTO plans (name, slug, type, billing_type, price, price_quarterly, price_semiannual, price_yearly, discount_quarterly, discount_semiannual, discount_yearly, duration_days, limit_ads_active, has_verification_badge, priority_support, description, features, active, is_highlighted, sort_order, category, created_at, updated_at) VALUES ('Max', 'frete-max', 'freight_list', 'subscription', 79.90, 215, 399, 719, 10, 17, 25, 30, 10, 1, 1, '10 anúncios + 2 destaques + Suporte VIP + Verificação', '[\"Tudo do Pró\",\"10 fretes/mês\",\"2 Destaques gratuitos/mês\",\"Suporte VIP\",\"Verificação de Identidade Grátis\"]', 1, 1, 4, 'freight_subscription', NOW(), NOW())");
echo "9. Criados planos de fretes\n";

// 10. CRIAR PLANOS DE MARKETPLACE
$pdo->exec("INSERT INTO plans (name, slug, type, billing_type, price, duration_days, limit_ads_active, has_verification_badge, priority_support, description, features, active, is_highlighted, sort_order, category, created_at, updated_at) VALUES ('Gratuito', 'marketplace-gratuito', 'sidebar', 'one_time', 0, 30, 1, 0, 0, '1 anúncio no marketplace por mês', '[\"Ver marketplace\",\"Publicar 1 produto\"]', 1, 0, 5, 'marketplace_subscription', NOW(), NOW())");

$pdo->exec("INSERT INTO plans (name, slug, type, billing_type, price, price_quarterly, price_semiannual, price_yearly, discount_quarterly, discount_semiannual, discount_yearly, duration_days, limit_ads_active, has_verification_badge, priority_support, description, features, active, is_highlighted, sort_order, category, created_at, updated_at) VALUES ('Starter', 'marketplace-starter', 'sidebar', 'subscription', 29.90, 80, 149, 269, 10, 17, 25, 30, 3, 0, 0, '3 anúncios no marketplace por mês', '[\"Tudo do Gratuito\",\"3 produtos/mês\",\"Chat com compradores\"]', 1, 0, 6, 'marketplace_subscription', NOW(), NOW())");

$pdo->exec("INSERT INTO plans (name, slug, type, billing_type, price, price_quarterly, price_semiannual, price_yearly, discount_quarterly, discount_semiannual, discount_yearly, duration_days, limit_ads_active, has_verification_badge, priority_support, description, features, active, is_highlighted, sort_order, category, created_at, updated_at) VALUES ('Pró', 'marketplace-pro', 'sidebar', 'subscription', 49.90, 134, 249, 449, 10, 17, 25, 30, 5, 0, 0, '5 anúncios + 1 destaque/mês', '[\"Tudo do Starter\",\"5 produtos/mês\",\"1 Destaque gratuito/mês\"]', 1, 1, 7, 'marketplace_subscription', NOW(), NOW())");

$pdo->exec("INSERT INTO plans (name, slug, type, billing_type, price, price_quarterly, price_semiannual, price_yearly, discount_quarterly, discount_semiannual, discount_yearly, duration_days, limit_ads_active, has_verification_badge, priority_support, description, features, active, is_highlighted, sort_order, category, created_at, updated_at) VALUES ('Max', 'marketplace-max', 'sidebar', 'subscription', 79.90, 215, 399, 719, 10, 17, 25, 30, 10, 0, 1, '10 anúncios + 2 destaques + Suporte VIP', '[\"Tudo do Pró\",\"10 produtos/mês\",\"2 Destaques gratuitos/mês\",\"Suporte VIP\"]', 1, 1, 8, 'marketplace_subscription', NOW(), NOW())");
echo "10. Criados planos de marketplace\n";

echo "\n=== MONETIZAÇÃO ATUALIZADA COM SUCESSO! ===\n\n";

// Mostrar resultado
echo "--- PLANOS ---\n";
$stmt = $pdo->query("SELECT id, name, price, limit_ads_active, category FROM plans ORDER BY category, sort_order");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "ID: " . $row['id'] . " | " . $row['name'] . " | R$" . $row['price'] . " | Limite: " . $row['limit_ads_active'] . " | " . $row['category'] . "\n";
}

echo "\n--- PRICING RULES (ativos) ---\n";
$stmt = $pdo->query("SELECT module_key, feature_key, feature_name, price_per_use, price_monthly, is_active FROM pricing_rules WHERE is_active = 1 ORDER BY module_key, feature_key");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['module_key'] . "." . $row['feature_key'] . " = R$" . $row['price_per_use'] . " (use) / R$" . $row['price_monthly'] . " (mês) - " . $row['feature_name'] . "\n";
}
