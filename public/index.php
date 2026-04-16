<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\Router;
use App\Core\Response;
use App\Core\Auth;

// 1. Setup Ambiental
$envFile = file_exists(__DIR__ . '/../.env.production') 
    ? __DIR__ . '/../.env.production' 
    : __DIR__ . '/../.env';
    
if (file_exists($envFile)) {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname($envFile));
    $dotenv->load();
}

$appEnv = $_ENV['APP_ENV'] ?? 'local';
$isProduction = $appEnv === 'production';

// 2. CORS e Headers
$allowedOrigins = $isProduction 
    ? ['https://www.chamafrete.com.br', 'https://chamafrete.com.br']
    : ['http://127.0.0.1:5173', 'http://localhost:5173', 'http://127.0.0.1:3000', 'http://localhost:3000'];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigin = in_array($origin, $allowedOrigins) ? $origin : ($allowedOrigins[0] ?? '*');

header("Access-Control-Allow-Origin: $allowedOrigin");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Origin, Accept");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $db = Database::getConnection();

    // 4. Captura de Dados
    $input = file_get_contents("php://input");
    $jsonData = json_decode($input, true) ?? [];
    // Prioridade: JSON > POST > GET
    $data = array_merge($_GET, $_POST, $jsonData);

    // 5. Autenticação JWT 
    // Certifique-se que a classe App\Core\Auth usa o seu AuthMiddleware internamente
    $loggedUser = Auth::getAuthenticatedUser() ?: null;
    $role = $loggedUser ? strtoupper($loggedUser['role'] ?? '') : '';

    // 6. Inicialização do Router
    $router = new Router($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);

    /**
     * --- MAPEAMENTO DE ROTAS (TODAS AS ROTAS DO ANTIGO + NOVAS) ---
     */

    // --- AUTH, LOGIN & RECUPERAÇÃO ---
    $router->post('/api/login', 'AuthController@login');
    $router->post('/api/register', 'AuthController@register');
    $router->post('/api/reset-password', 'AuthController@resetPassword');

    // --- PERFIL DO USUÁRIO & SLUGS ---
    $router->get('/api/get-my-profile', 'UserController@getProfile');
    $router->get('/api/company/summary', 'UserController@getCompanySummary');
    $router->get('/api/user/modules', 'UserController@getUserModules');
    $router->post('/api/user/modules', 'UserController@toggleModule');
    $router->post('/api/user/modules/request', 'UserController@requestModuleAccess');
    $router->get('/api/pricing/rules', 'UserController@getPricingRules');
    $router->get('/api/ad-positions', 'UserController@getAdPositions');
    $router->get('/api/site-settings', 'UserController@getSiteSettings');
    $router->get('/api/public/site-settings', 'UserController@getPublicLists');
    $router->get('/api/user/usage', 'UserController@getUserUsage');
    $router->get('/api/plans', 'UserController@getPlans');
    $router->post('/api/update-profile', 'UserController@updateProfile');
    $router->post('/api/save-profile', 'UserController@updateProfile');
    $router->post('/api/update-quick-profile', 'UserController@updateProfile');
    $router->post('/api/update-user-basic', 'UserController@updateProfile');
    $router->post('/api/toggle-availability', 'UserController@toggleAvailability');

    $router->get('/api/get-public-profile', 'UserController@getUserSummary'); //User @deprecated
    $router->get('/api/user/details/:id', 'UserController@getUserSummary'); //trocar acima
    $router->get('/api/get-by-slug', 'UserController@getBySlug');
    $router->get('/api/check-slug', 'UserController@checkSlug');
    $router->post('/api/upload-image', 'UserController@uploadImage');
    $router->post('/api/activate-free-verification', 'UserController@runVerificationProcess');
    $router->post('/api/delete-account', 'UserController@deleteAccount');
    $router->post('/api/verify-cnpj', 'UserController@verifyCnpj');
    $router->get('/api/get-cnpj-data', 'UserController@getCnpjData');
    
    // --- DRIVER MATCHING & GEOLOCATION ---
    $router->get('/api/geocode/cep', 'UserController@geocodeCep');
    $router->post('/api/driver/location', 'UserController@updateDriverLocation');
    $router->get('/api/profile/completeness', 'UserController@getProfileCompleteness');
    $router->post('/api/driver/equipment', 'UserController@updateDriverEquipment');
    
    $router->get('/api/public-freight/:slug', 'PublicController@getFreightDetails');
    //$router->get('/api/public-profile', 'PublicController@getProfilePage'); //Public profile @deprecated
    $router->get('/api/profile/page/:slug', 'PublicController@getProfilePage'); //trocar acima
    $router->get('/api/get-user-posts', 'PublicController@getPublicPosts');
    $router->post('/api/profile/track-click/:id', 'PublicController@trackWhatsAppClick');
    $router->get('/api/get-user-ads', 'PublicController@getPublicAds'); //Criar

    $router->post('/api/upload-avatar', 'UserController@uploadAvatar');
    $router->post('/api/upload-banner', 'UserController@uploadImage');

    // --- FRETES (SISTEMA CORE) ---
    $router->get('/api/freights', 'FreightController@listAll');
    $router->post('/api/create-freight', 'FreightController@createFreight');
    $router->post('/api/update-freight', 'FreightController@updateFreight');
    $router->get('/api/list-freights', 'FreightController@listAll');
    $router->get('/api/list-my-freights', 'FreightController@listMyFreights');
    $router->post('/api/delete-freight', 'FreightController@deleteFreight');
    $router->post('/api/toggle-favorite', 'FreightController@toggleFavorite');
    $router->get('/api/my-favorites', 'FreightController@myFavorites');
    $router->post('/api/finish-freight', 'FreightController@finishFreight');
    $router->get('/api/suggested-drivers', 'FreightController@getSuggestedDrivers');
    $router->get('/api/confirm-match', 'FreightController@confirmMatch');
    $router->get('/api/get-interested-drivers', 'FreightController@getInterested');
    $router->post('/api/accept-driver', 'FreightController@acceptDriver');
    $router->post('/api/contact-advertiser', 'FreightController@contact');
    $router->get('/api/list-interests', 'FreightController@listInterests');
    $router->get('/api/invite-driver', 'FreightController@inviteDriver');
    $router->post('/api/respond-invitation', 'FreightController@respondInvitation'); 
    $router->get('/api/user-alerts', 'FreightController@userAlerts'); 
    $router->get('/api/my-active-freight', 'FreightController@myActiveFreight');
    $router->get('/api/driver-stats', 'FreightController@getdriverstats');
    $router->get('/api/top-ads-freight', 'FreightController@getTopAdvertisersFreight');
    $router->get('/api/freight-tracking', 'FreightController@getFreightTracking');
    $router->get('/api/freight/:id/matching-drivers', 'FreightController@findMatchingDrivers');
    
    // --- ADS & BANNERS (MÉTRICAS ATIVAS) ---
    $router->get('/api/ads', 'AdController@list');
    $router->get('/api/my-ads', 'AdController@listMyAds');
    $router->post('/api/upload-ad', 'AdController@create');
    $router->get('/api/companies', 'UserController@listCompanies');
    
    // --- PARTNERS (Strategic & Media Network - Separate from Ads) ---
    $router->get('/api/partners', 'AdController@listPartners');
    $router->post('/api/partners', 'AdController@savePartner');
    $router->delete('/api/partners/:id', 'AdController@deletePartner');
    
    $router->post('/api/log-ad-click', 'MetricsController@registerEvent');
    $router->post('/api/log-ad-view', 'MetricsController@registerEvent');
    $router->post('/api/register-ad-event', 'AdController@trackClick');

    $router->post('/api/ads/click/:id', 'AdController@recordClick');
    $router->get('/api/ads/click/:id', 'AdController@recordClick');
    $router->get('/api/ads/report/:id', 'AdController@getReport');
    $router->get('/api/ads/my-report', 'AdController@getUserAdsReport');
    $router->post('/api/ads/save', 'AdController@store');
    $router->delete('/api/ads/:id', 'AdController@store');
    

    // --- MARKETPLACE & LISTINGS ---
    $router->get('/api/listings', 'ListingController@getAll');
    $router->get('/api/marketplace', 'ListingController@getAll');
    $router->get('/api/my-listings', 'ListingController@getMyListings');
    $router->get('/api/listing/:id', 'ListingController@getMyListing');
    $router->get('/api/anuncio/:slug', 'ListingController@getPublicBySlug');
    $router->post('/api/create-listing', 'ListingController@create');
    $router->post('/api/update-listing', 'ListingController@update');
    $router->post('/api/delete-listing', 'ListingController@delete');
    $router->post('/api/listing/boost', 'ListingController@boost');
    $router->post('/api/listing/extend', 'ListingController@extend');

    // --- LISTING CATEGORIES (Marketplace) ---
    $router->get('/api/listing-categories', 'ListingCategoryController@getAll');
    $router->get('/api/listing-category/:id', 'ListingCategoryController@get');
    $router->post('/api/listing-category', 'ListingCategoryController@create');
    $router->put('/api/listing-category/:id', 'ListingCategoryController@update');
    $router->delete('/api/listing-category/:id', 'ListingCategoryController@delete');
    $router->post('/api/listing-category/:id/toggle', 'ListingCategoryController@toggleActive');
    ///$router->post('/api/log-listing-activity', 'ListingController@logActivity');

    // --- MÉTRICAS UNIFICADAS (NOVO) ---
    $router->post('/api/metrics/register', 'MetricsController@registerEvent');
    $router->get('/api/metrics/dashboard-summary', 'MetricsController@getDashboardSummary');
    $router->get('/api/metrics/global', 'MetricsController@getGlobalStats');

    // --- MÉTRICAS GERAIS ---
    $router->post('/api/log-event', 'MetricsController@registerEvent');
    $router->post('/api/register-click', 'FreightController@logEvent');
    $router->post('/api/track-metric', 'FreightController@logEvent');

    // --- NOTIFICAÇÕES & REVIEWS ---
    $router->get('/api/list-notifications', 'NotificationController@index');
    $router->post('/api/mark-as-read', 'NotificationController@markAsRead');
    $router->post('/api/mark-all-read', 'NotificationController@markAllRead');
    $router->get('/api/unread-count', 'NotificationController@unreadCount');
    $router->get('/api/profile/check-completeness', 'NotificationController@checkProfileCompleteness');
    $router->post('/api/notifications/freight-invite', 'NotificationController@sendFreightInvite');
    $router->post('/api/submit-review', 'ReviewController@submit');
    $router->get('/api/get-user-reviews', 'ReviewController@list');
    $router->get('/api/review-stats', 'ReviewController@getStats');

    // --- GRUPOS & COMUNIDADES ---
    $router->get('/api/list-groups', 'GroupController@listGroups');
    $router->get('/api/platform-groups', 'GroupController@listPlatformGroups');
    $router->get('/api/group/:id', 'GroupController@getGroup');
    $router->post('/api/manage-groups', 'GroupController@manageGroups');
    $router->post('/api/log-group-click', 'GroupController@logGroupClick');
    $router->post('/api/upload-group-image', 'GroupController@uploadImage');
    $router->post('/api/portal-request', 'AdminController@storePortalRequest'); //verificar

    // --- CATEGORIAS DE GRUPOS ---
    $router->get('/api/group-categories', 'GroupCategoryController@getAll');
    $router->get('/api/group-categories/active', 'GroupCategoryController@getActive');

    // --- BUSCA DE USUÁRIOS (disponível para usuários logados) ---
    $router->get('/api/users/search', 'AdminController@searchUsers');


    // --- PAGAMENTOS & MEMBRESIA ---
    $router->post('/api/checkout', 'PaymentController@checkout');
    // Novo: criação de pagamento via MVP MercadoPago (redundante ao checkout, mas separado para endpoints previsíveis)
    $router->post('/api/payments/create', 'PaymentController@createPayment');
    $router->post('/api/payments/webhook', 'PaymentController@webhook');
    $router->get('/api/payments/status', 'PaymentController@getPaymentStatus');
    $router->post('/api/process-checkout', 'PaymentController@checkout');
    $router->post('/api/webhook-mp', 'PaymentController@webhook');
    $router->get('/api/my-services', 'MembershipController@myServices');
    $router->get('/api/payment-history', 'MembershipController@getPaymentHistory'); 
    $router->get('/api/my-transactions', 'PaymentController@getMyTransactions');
    
    // --- CARTEIRA (WALLET) ---
    $router->get('/api/wallet/balance', 'WalletController@getBalance');
    $router->post('/api/wallet/recharge', 'WalletController@recharge');
    $router->get('/api/wallet/transactions', 'WalletController@getTransactions');
    $router->get('/api/wallet/pricing', 'WalletController@getPricing');
    $router->post('/api/wallet/webhook', 'WalletController@webhook');
    
    // --- PAGAMENTOS DE MÓDULOS ---
    $router->post('/api/module/purchase-per-use', 'PaymentController@purchasePerUse');
    $router->post('/api/module/purchase-partial', 'PaymentController@purchasePartial');
    $router->post('/api/module/subscribe-monthly', 'PaymentController@subscribeMonthly');
    $router->post('/api/plans/subscribe', 'PaymentController@subscribePlan');
    $router->get('/api/ad/check-eligibility', 'PaymentController@checkAdEligibility');
    
    // --- DESTAQUE/URGENTE DE FRETE ---
    $router->post('/api/freight/promote', 'PaymentController@promoteFreight');
    
    // --- DRIVER VERIFICATION ---
    $router->post('/api/driver/verification/purchase', 'PaymentController@purchaseDriverVerification');
    $router->get('/api/driver/verification/status', 'PaymentController@getDriverVerificationStatus');
    
    // --- COMPANY VERIFICATION ---
    $router->get('/api/company/verification/status', 'CompanyController@getVerificationStatus');
    $router->post('/api/company/verification/verify-cnpj', 'CompanyController@verifyCnpj');
    $router->post('/api/company/verification/submit', 'CompanyController@submitVerification');
    $router->post('/api/company/verification/purchase', 'CompanyController@purchaseVerification');
    
    // --- TEAM (GESTÃO DE EQUIPE) ---
    $router->get('/api/team', 'TeamController@getTeam');
    $router->post('/api/team/invite', 'TeamController@invite');
    $router->get('/api/team/invitations', 'TeamController@getInvitations');
    $router->post('/api/team/invitation/cancel', 'TeamController@cancelInvitation');
    $router->post('/api/team/accept', 'TeamController@acceptInvitation');
    $router->post('/api/team/member/remove', 'TeamController@removeMember');
    $router->post('/api/team/member/update', 'TeamController@updateMember');
    
    // --- DOCUMENT UPLOAD ---
    $router->post('/api/document/upload', 'PaymentController@uploadDocument');
    $router->get('/api/document/list', 'PaymentController@listMyDocuments');
    $router->delete('/api/document/delete', 'PaymentController@deleteDocument');
    $router->get('/api/document/check-required', 'PaymentController@checkRequiredDocuments');

    // --- CHAT ---
    $router->post('/api/chat/send', 'ChatController@sendMessage');
    $router->get('/api/chat/messages', 'ChatController@getMessages');
    $router->get('/api/chat/rooms', 'ChatController@listRooms');
    $router->post('/api/chat/init', 'ChatController@initChat');

    // --- SUPORTE (USUÁRIOS) ---
    $router->get('/api/my-tickets', 'SupportController@myTickets');
    $router->post('/api/my-tickets', 'SupportController@createTicket');
    $router->post('/api/my-tickets/reply', 'SupportController@addReply');
    $router->post('/api/my-tickets/close', 'SupportController@closeMyTicket');
    $router->get('/api/my-tickets/:id/messages', 'SupportController@getTicketMessages');

    // --- COTAÇÕES ---
    $router->post('/api/quotes', 'QuoteController@create');
    $router->get('/api/quotes', 'QuoteController@getMyQuotes');
    $router->get('/api/quotes/open', 'QuoteController@getOpenQuotes');
    $router->get('/api/quotes/:id', 'QuoteController@getQuote');
    $router->put('/api/quotes/:id', 'QuoteController@update');
    $router->delete('/api/quotes/:id', 'QuoteController@delete');
    $router->post('/api/quotes/:id/respond', 'QuoteController@respond');
    $router->post('/api/quotes/:id/accept', 'QuoteController@acceptResponse');
    $router->get('/api/quotes/responses/my', 'QuoteController@getMyResponses');

    // --- LOGS DE AUDITORIA (NOVO) ---
    $router->post('/admin/logs', 'AuditController@index');
    $router->post('/admin/logs/detail', 'AuditController@show');

    // --- BLOCO ADMINISTRATIVO / GESTÃO (RESTRITO) ---
    $role = strtoupper($loggedUser['role'] ?? '');
    
    // Rotas acessíveis por ADMIN, MANAGER e SUPPORT
    if ($loggedUser && in_array($role, ['ADMIN', 'MANAGER', 'SUPPORT'])) {
        $router->get('/api/support/tickets', 'SupportController@listAllTickets');
        $router->post('/api/support/reply', 'SupportController@reply');
        $router->post('/api/support/close-ticket', 'SupportController@closeTicket');
        $router->post('/api/support/update-ticket', 'SupportController@updateTicket');
        $router->get('/api/support/tickets/:id/messages', 'SupportController@getTicketMessagesAdmin');
        
        // CRM / Notas Internas
        $router->post('/api/admin/user-notes', 'AdminController@addUserNote');
        $router->get('/api/admin/user-notes', 'AdminController@getUserNotes');
    }
    
    // Rotas acessíveis por ADMIN e MANAGER
    if ($loggedUser && in_array($role, ['ADMIN', 'MANAGER'])) {
        // Dashboard e Logs
        $router->get('/api/admin-dashboard-data', 'AdminController@getDashboardData');
        $router->get('/api/admin/home-stats', 'AdminController@getHomeStats');
        $router->get('/api/admin/bi-stats', 'AdminController@getBIStats');

        // Usuários
        $router->get('/api/admin-user-details', 'AdminController@getUserDetails');
        $router->get('/api/admin-company-members', 'AdminController@listCompanyMembers');
        $router->post('/api/admin-create-user', 'AdminController@createUser');
        $router->post('/api/admin-create-internal-user', 'AdminController@createInternalUser');
        $router->post('/api/admin-add-note', 'AdminController@addUserNote');
        $router->post('/api/admin-update-user', 'AdminController@updateUser');
        $router->post('/api/admin-manage-user', 'AdminController@manageUsers');
        $router->post('/api/admin-verify-user', 'AdminController@verifyUser');
        $router->post('/api/admin-delete-user', 'AdminController@deleteUser');

        // Gestão de Fretes
        $router->get('/api/admin-list-freights', 'AdminController@listAllFreights');
        $router->post('/api/admin-update-freight', 'AdminController@updateFreightStatus');
        $router->post('/api/manage-freights', 'AdminController@manageFreights');

        // Gestão de Leads (Novo Sistema CRM)
        $router->post('/api/admin-portal-requests', 'LeadController@createRequest');
        $router->get('/api/admin-portal-requests', 'LeadController@listLeads');
        $router->post('/api/admin-update-lead', 'LeadController@handleAction');
        $router->get('/api/admin-lead-history', 'LeadController@getHistory');

        // Gestão de Cotações (Admin)
        $router->get('/api/admin/quotes', 'QuoteController@adminList');
        $router->post('/api/admin/quotes', 'QuoteController@adminCreate');
        $router->get('/api/admin/quotes/:id', 'QuoteController@adminGetQuote');
        $router->put('/api/admin/quotes/:id', 'QuoteController@adminUpdateQuote');
        $router->delete('/api/admin/quotes/:id', 'QuoteController@adminDeleteQuote');
        $router->post('/api/admin/quotes/:id/respond', 'QuoteController@adminRespondQuote');

        // Gestão de Marketplaces (Admin)
        $router->get('/api/admin/marketplace', 'ListingController@adminList');
        $router->post('/api/admin/marketplace', 'ListingController@adminCreate');
        $router->get('/api/admin/marketplace/:id', 'ListingController@adminGet');
        $router->put('/api/admin/marketplace/:id', 'ListingController@adminUpdate');
        $router->delete('/api/admin/marketplace/:id', 'ListingController@adminDelete');

        $router->post('/api/admin-manage-ads', 'AdminController@manageAds');

        // Créditos e Planos
        $router->post('/api/admin/add-credits', 'AdminController@manualAddCredits');
        $router->get('/api/manage-plans', 'AdminController@managePlans');
        $router->post('/api/admin-manage-plans', 'AdminController@managePlans');
        
        // Gestão de Usuários
        $router->get('/api/list-all-users', 'AdminController@listUsers');
        $router->get('/api/admin/users/search', 'AdminController@searchUsers');
        
        // Gestão de Anúncios
        $router->get('/api/admin-manage-ads', 'AdminController@manageAds');
        $router->get('/api/admin-ads', 'AdminController@manageAds');
        
        // Gestão de Grupos
        $router->get('/api/admin-groups', 'AdminController@listAllGroups');
        $router->post('/api/admin-groups', 'AdminController@manageGroups');
        
        // Gestão de Categorias de Grupos
        $router->get('/api/admin/group-categories', 'GroupCategoryController@getAll');
        $router->post('/api/admin/group-categories', 'GroupCategoryController@create');
        $router->put('/api/admin/group-categories/:id', 'GroupCategoryController@update');
        $router->delete('/api/admin/group-categories/:id', 'GroupCategoryController@delete');
        $router->post('/api/admin/group-categories/:id/toggle', 'GroupCategoryController@toggle');
        $router->post('/api/admin/group-categories/reorder', 'GroupCategoryController@reorder');
        
        // Gestão de Configurações
        $router->get('/api/admin-settings', 'AdminController@getSettings');
        $router->post('/api/admin-settings', 'AdminController@updateSettings');
        
        // Gestão de Atividades
        $router->get('/api/admin-activity', 'AdminController@getActivityLogs');
        
        // Precificação
        $router->get('/api/admin-pricing', 'AdminController@managePricing');
        $router->post('/api/admin-pricing', 'AdminController@managePricing');

        // Documentos e Verificações
        $router->get('/api/admin-pending-docs', 'AdminController@listPendingDocuments');
        $router->post('/api/admin-review-doc', 'AdminController@reviewDocument');
        
        // Driver Verifications
        $router->get('/api/admin/driver-verifications', 'AdminController@listDriverVerifications');
        $router->post('/api/admin/driver-verification/approve', 'AdminController@approveDriverVerification');
        $router->post('/api/admin/driver-verification/reject', 'AdminController@rejectDriverVerification');
        
        // Unified Verifications (Drivers + Companies)
        $router->get('/api/admin/verifications', 'AdminController@getAllVerifications');
        $router->post('/api/admin/verification/approve', 'AdminController@approveVerification');
        $router->post('/api/admin/verification/reject', 'AdminController@rejectVerification');
        
        // Reviews / Avaliações
        $router->get('/api/admin-reviews', 'AdminController@getReviews');
        $router->post('/api/admin-review/approve', 'AdminController@approveReview');
        $router->post('/api/admin-review/reject', 'AdminController@rejectReview');
        $router->post('/api/admin-review/delete', 'AdminController@deleteReview');
        $router->post('/api/review/reply', 'ReviewController@replyReview');
        $router->post('/api/review/delete-reply', 'ReviewController@deleteReply');
        
        // Reports / Denúncias (usuários)
        $router->post('/api/reports', 'ReportController@create');
        $router->get('/api/my-reports', 'ReportController@listMine');
        
        // Affiliate / Afiliados (Marketplace)
        $router->get('/api/affiliate/scrape', 'AffiliateController@scrapeProduct');
        $router->post('/api/affiliate/scrape', 'AffiliateController@scrapeProduct');
        $router->post('/api/affiliate/generate-url', 'AffiliateController@generateAffiliateUrl');
        $router->post('/api/affiliate/interest', 'AffiliateController@submitInterest');
        $router->get('/api/affiliate/my-interest', 'AffiliateController@getMyInterest');
        $router->get('/api/affiliate/access', 'AffiliateController@checkAccess');
        $router->get('/api/affiliate/redirect/:id', 'AffiliateController@redirect');
        
        // Affiliate Admin
        $router->get('/api/admin/affiliate/interests', 'AdminAffiliateController@listInterests');
        $router->get('/api/admin/affiliate/stats', 'AdminAffiliateController@getStats');
        $router->post('/api/admin/affiliate/interests/:id/approve', 'AdminAffiliateController@approveInterest');
        $router->post('/api/admin/affiliate/interests/:id/reject', 'AdminAffiliateController@rejectInterest');
        $router->post('/api/admin/affiliate/interests/:id/revoke', 'AdminAffiliateController@revokeAccess');
        
        // Reports / Denúncias (admin)
        $router->get('/api/admin/reports', 'ReportController@getAll');
        $router->get('/api/admin/reports/:id', 'ReportController@get');
        $router->post('/api/admin/reports/:id/assign', 'ReportController@assign');
        $router->post('/api/admin/reports/:id/resolve', 'ReportController@resolve');
        $router->post('/api/admin/reports/:id/dismiss', 'ReportController@dismiss');
        $router->post('/api/admin/reports/:id/delete', 'ReportController@delete');
        
        // Configurações e Estatísticas (Outros)
        $router->get('/api/admin-stats', 'AdminController@getStats');
        $router->get('/api/get-advertising-plans', 'AdminController@getAdvertisingPlans');
        
        // Rotas exclusivas de ADMIN
        if ($role === 'ADMIN') {
            $router->get('/api/admin-revenue-report', 'AdminController@getRevenueReport');
            $router->get('/api/admin-financial-stats', 'AdminController@getFinancialStats');
            $router->get('/api/admin-audit-logs', 'AdminController@listLogs');
            $router->get('/api/admin-settings', 'AdminController@getSettings');
            $router->post('/api/admin-update-settings', 'AdminController@updateSettings');
            $router->get('/api/admin-activity', 'AdminController@getActivityLogs');
            $router->get('/api/admin/freight-matching', 'AdminController@findMatchingDrivers');
            $router->post('/api/admin/driver-location', 'AdminController@updateDriverLocation');
            
            // Permissions
            $router->get('/api/admin-permissions', 'PermissionController@getAll');
            $router->post('/api/admin-permissions', 'PermissionController@create');
            $router->put('/api/admin-permissions', 'PermissionController@update');
            $router->delete('/api/admin-permissions', 'PermissionController@delete');
            $router->get('/api/admin-role-permissions', 'PermissionController@getRolePermissions');
            $router->post('/api/admin-role-permissions', 'PermissionController@setRolePermissions');
            $router->get('/api/admin-user-permissions', 'PermissionController@getUserPermissions');
            $router->post('/api/admin-user-permissions', 'PermissionController@setUserPermissions');
            
            // Roles
            $router->get('/api/admin-roles', 'RoleController@getAll');
            $router->post('/api/admin-roles', 'RoleController@create');
            $router->put('/api/admin-roles', 'RoleController@update');
            $router->delete('/api/admin-roles', 'RoleController@delete');
            $router->get('/api/admin-roles/:id', 'RoleController@getById');
            
            // Modules
            $router->get('/api/admin-modules', 'ModuleController@getAll');
            $router->post('/api/admin-modules', 'ModuleController@create');
            $router->put('/api/admin-modules', 'ModuleController@update');
            $router->delete('/api/admin-modules', 'ModuleController@delete');
            
            // Team
            $router->get('/api/admin-team', 'TeamController@getTeam');
            $router->post('/api/admin-team/invite', 'TeamController@invite');
            $router->get('/api/admin-team/invitations', 'TeamController@getInvitations');
            $router->delete('/api/admin-team/invitation', 'TeamController@cancelInvitation');
            $router->post('/api/admin-team/accept', 'TeamController@acceptInvitation');
            $router->delete('/api/admin-team/member', 'TeamController@removeMember');
            $router->put('/api/admin-team/member', 'TeamController@updateMember');
            
            // Profile (user endpoints)
            $router->get('/api/profile', 'ProfileController@get');
            $router->put('/api/profile', 'ProfileController@update');
            $router->post('/api/profile/avatar', 'ProfileController@updateAvatar');
            $router->get('/api/profile/activity', 'ProfileController@getActivity');
            
            $router->post('/api/delete-group', 'GroupController@deleteGroup');
        }
    }

    // 7. Execução
    $response = $router->run($db, $loggedUser, $data);

   if ($response !== null) {
        // Só limpamos o buffer se houver lixo (espaços, warnings) antes do JSON
        if (ob_get_level() > 0) {
            $unexpectedOutput = ob_get_clean(); 
            if (!empty($unexpectedOutput)) {
                error_log("Lixo detectado no buffer: " . $unexpectedOutput);
            }
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

} catch (Throwable $e) {
   // Log interno do erro (não mostrar tudo ao usuário em produção)

   Response::json([
        "success" => false, 
        "message" => $e->getMessage(), 
        "file" => $e->getFile(), 
        "line" => $e->getLine()
    ]);

    error_log("ERRO NO INDEX: " . $e->getMessage() . " em " . $e->getFile() . ":" . $e->getLine());

    if (!headers_sent()) {
        http_response_code(500);
        header("Content-Type: application/json; charset=utf-8");
    }

    echo json_encode([
        "success" => false, 
        "message" => "Erro interno no servidor",
        "error_id" => time(), // Útil para suporte
    ]);
}
