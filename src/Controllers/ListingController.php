<?php
namespace App\Controllers;

use PDO;
use App\Core\Response;
use App\Repositories\ListingRepository;
use App\Services\CreditService;
use App\Services\GeocodingService;
use App\Services\ContentFilterService;
use App\Services\MercadoPagoService;

class ListingController {
    private $repository;
    private $creditService;
    private $geocodingService;
    private $db;
    private $mpService;

    public function __construct($db) {
        $this->db = $db;
        $this->repository = new ListingRepository($db);
        $this->creditService = new CreditService($db);
        $this->geocodingService = new GeocodingService($db);
        $this->mpService = new MercadoPagoService($db);
    }

    private function uploadFile($file) {
        if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }
        
        $targetDir = __DIR__ . "/../../public/uploads/listings/";
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        
        if (!in_array(strtolower($ext), $allowedExtensions)) {
            return null;
        }

        $fileName = time() . "_" . uniqid() . "." . $ext;
        
        if (move_uploaded_file($file['tmp_name'], $targetDir . $fileName)) {
            return "uploads/listings/" . $fileName;
        }
        return null;
    }

    public function create($data, $loggedUser = null) {
        // Validação básica
        if (empty($data['title']) || empty($data['user_id'])) {
            return Response::json(["success" => false, "message" => "Dados insuficientes"], 400);
        }

        // Validar conteúdo com ContentFilter
        if (!ContentFilterService::isClean($data['title'])) {
            $reason = ContentFilterService::getReason($data['title']);
            return Response::json(["success" => false, "message" => $reason ?: "O título contém conteúdo não permitido."], 400);
        }
        if (!empty($data['description']) && !ContentFilterService::isClean($data['description'])) {
            $reason = ContentFilterService::getReason($data['description']);
            return Response::json(["success" => false, "message" => $reason ?: "A descrição contém conteúdo não permitido."], 400);
        }

        // Validar acesso a afiliados se estiver tentando criar um anúncio afiliado
        $isAffiliate = !empty($data['is_affiliate']) && $data['is_affiliate'] == true;
        if ($isAffiliate && !$loggedUser) {
            return Response::json([
                "success" => false, 
                "message" => "Você precisa estar logado para criar anúncios de afiliados."
            ], 401);
        }
        
        if ($isAffiliate && !in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER'])) {
            $stmt = $this->db->prepare("SELECT has_affiliate_access FROM users WHERE id = ?");
            $stmt->execute([(int)$data['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user || !$user['has_affiliate_access']) {
                return Response::json([
                    "success" => false, 
                    "message" => "Você não tem acesso ao recurso de afiliados. Solicite acesso em 'Marketplace > Anúncios de Afiliado'."
                ], 403);
            }
        }

        $userId = (int)$data['user_id'];
        $isFeatured = !empty($data['is_featured']) && $data['is_featured'] == true;

        // Verificar tipo de publicação
        $featureKey = 'publish_listing';
        $expiresDays = 7;

        if ($isFeatured) {
            $featureKey = 'featured_listing';
        }

        // Verificar se é admin (não paga)
        $isAdmin = $loggedUser && in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER']);
        $paymentRequired = !$isAdmin;

        // Buscar regra de pricing
        $amount = 14.90; // valor padrão
        $freeLimit = 0;
        $pricePerUse = 14.90;

        try {
            $stmt = $this->db->prepare("
                SELECT * FROM pricing_rules 
                WHERE module_key = 'marketplace' 
                AND feature_key = :feature_key 
                AND is_active = 1
            ");
            $stmt->execute([':feature_key' => $featureKey]);
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($rule) {
                $pricePerUse = floatval($rule['price_per_use'] ?? 9.90);
                $freeLimit = intval($rule['free_limit'] ?? 0);
                $expiresDays = intval($rule['duration_days'] ?? 7);
            }
        } catch (\Exception $e) {
            // Em caso de erro, usa valores padrão
            $pricePerUse = 9.90;
            $freeLimit = 0;
            $expiresDays = 7;
        }

        // Se é featured, soma o valor
        $amount = $isFeatured ? ($pricePerUse + 9.90) : $pricePerUse;

        // Verificar se usuário tem limite grátis disponível
        $usedFreeListings = 0;
        $hasFreeListing = false;

        if ($freeLimit > 0) {
            try {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) as total FROM listings 
                    WHERE user_id = :user_id 
                    AND MONTH(created_at) = MONTH(CURRENT_DATE())
                    AND YEAR(created_at) = YEAR(CURRENT_DATE())
                    AND status != 'rejected'
                ");
                $stmt->execute([':user_id' => $userId]);
                $usedFreeListings = (int)$stmt->fetch()['total'];
                $hasFreeListing = $usedFreeListings < $freeLimit;
            } catch (\Exception $e) {
                $hasFreeListing = false;
            }
        }

        // Se tem listing grátis disponível, não cobra
        if ($hasFreeListing) {
            $paymentRequired = false;
            $amount = 0;
        }

        // Verificar saldo e debitar
        if ($paymentRequired) {
            $balance = $this->creditService->getBalance($userId);
            
            if ($balance < $amount) {
                return Response::json([
                    "success" => false,
                    "message" => "Saldo insuficiente. Você tem R$ " . number_format($balance, 2, ',', '.') . " na carteira. Custo: R$ " . number_format($amount, 2, ',', '.') . ".",
                    "balance" => $balance,
                    "required" => $amount,
                    "free_limit" => $freeLimit,
                    "used_free" => $usedFreeListings,
                    "code" => "INSUFFICIENT_BALANCE"
                ], 402);
            }

            $debited = $this->creditService->debit($userId, $amount, 'marketplace', $featureKey);
            if (!$debited) {
                return Response::json([
                    "success" => false,
                    "message" => "Erro ao debitar saldo. Tente novamente."
                ], 500);
            }
        }

        $data['slug'] = $this->generateSlug($data['title']);
        $data['expires_at'] = date('Y-m-d H:i:s', strtotime("+$expiresDays days"));
        $data['is_featured'] = $isFeatured ? 1 : 0;
        
        // Processar imagens (upload de arquivos)
        $imageUrls = [];
        
        // Se houver uploads de arquivos (multipart/form-data)
        if (!empty($_FILES['images'])) {
            $files = $this->reArrayFiles($_FILES['images']);
            foreach ($files as $file) {
                $url = $this->uploadFile($file);
                if ($url) {
                    $imageUrls[] = $url;
                }
            }
        }
        
        // Se houver URLs de imagens (JSON body)
        if (!empty($data['images']) && is_array($data['images'])) {
            foreach ($data['images'] as $img) {
                if (is_string($img) && filter_var($img, FILTER_VALIDATE_URL)) {
                    $imageUrls[] = $img;
                }
            }
        }
        
        // Definir main_image como primeira imagem
        $data['main_image'] = $imageUrls[0] ?? null;
        
        $listingId = $this->repository->save($data);
        
        if (!$listingId) {
            return Response::json(["success" => false, "message" => "Erro ao salvar anúncio"], 500);
        }
        
        // Geocodificar automaticamente pela cidade
        $city = $data['location_city'] ?? null;
        $state = $data['location_state'] ?? null;
        if ($city && $state) {
            $coords = $this->geocodingService->geocodeCity($city, $state);
            if ($coords) {
                $this->repository->updateCoords($listingId, $coords['lat'], $coords['lng']);
            }
        }
        
        // Salvar todas as imagens na tabela listing_images
        foreach ($imageUrls as $index => $url) {
            $this->repository->addImage($listingId, $url, $index);
        }

        $newBalance = $paymentRequired ? $this->creditService->getBalance($userId) : null;

        return Response::json([
            "success" => true, 
            "id" => $listingId, 
            "slug" => $data['slug'],
            "cost" => $paymentRequired ? $amount : 0,
            "balance" => $newBalance,
            "message" => "Anúncio publicado com sucesso!",
            "images" => $imageUrls
        ]);
    }
    
    private function reArrayFiles($files) {
        $result = [];
        if (is_array($files['name'])) {
            $fileCount = count($files['name']);
            for ($i = 0; $i < $fileCount; $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $result[] = [
                        'name' => $files['name'][$i],
                        'type' => $files['type'][$i],
                        'tmp_name' => $files['tmp_name'][$i],
                        'error' => $files['error'][$i],
                        'size' => $files['size'][$i]
                    ];
                }
            }
        } elseif ($files['error'] === UPLOAD_ERR_OK) {
            $result[] = $files;
        }
        return $result;
    }

    public function getAll($data) {
        $page = $data['page'] ?? 1;
        $filters = [
            'category' => $data['category'] ?? null,
            'search' => $data['search'] ?? null,
            'state' => $data['state'] ?? null,
            'city' => $data['city'] ?? null,
            'condition' => $data['condition'] ?? null,
            'min_price' => $data['min_price'] ?? null,
            'max_price' => $data['max_price'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'radius' => $data['radius'] ?? 50,
        ];

        $listings = $this->repository->findActiveWithFilters($filters, $page);
        
        // OTIMIZAÇÃO: Busca imagens de todos os anúncios em uma única query
        if (!empty($listings['items'])) {
            $ids = array_column($listings['items'], 'id');
            $allImages = $this->repository->getImagesForList($ids);
            
            foreach ($listings['items'] as &$item) {
                $item['images'] = $allImages[$item['id']] ?? [];
            }
        }
        
        return Response::json(["success" => true, "data" => $listings]);
    }

    public function getPublicBySlug($data) {
        $slug = $data['slug'] ?? '';
        
        if (!$slug) {
            return Response::json(["success" => false, "message" => "Slug não informado"], 400);
        }
        
        $listing = $this->repository->findBySlug($slug);
        
        if (!$listing || $listing['status'] === 'deleted') {
            return Response::json(["success" => false, "message" => "Anúncio não encontrado"], 404);
        }
        
        // Verificar se listing expirou
        if ($listing['expires_at'] && strtotime($listing['expires_at']) < time()) {
            return Response::json(["success" => false, "message" => "Anúncio expirado"], 410);
        }
        
        $listing['gallery'] = $this->repository->getImages($listing['id']);
        
        // Adicionar dados do vendedor
        $listing['total_listings'] = $this->repository->countUserListings($listing['user_id']);
        
        // Buscar sugestões "podem interessar" (mesma categoria + mesmo estado + outros vendedores)
        $state = $listing['location_state'] ?? null;
        $related = $this->repository->findSuggestions(
            $listing['id'], 
            $listing['category'], 
            $state,
            $listing['user_id'], 
            8
        );
        if (!empty($related)) {
            $relatedIds = array_column($related, 'id');
            $allImages = $this->repository->getImagesForList($relatedIds);
            foreach ($related as &$rel) {
                $rel['images'] = $allImages[$rel['id']] ?? [];
            }
        }
        $listing['related'] = $related;
        
        return Response::json(["success" => true, "data" => $listing]);
    }
    
    public function getMyListings($data, $loggedUser) {
        if (!$loggedUser) {
            return Response::json(["success" => false, "message" => "Unauthorized"], 401);
        }
        
        $userId = $loggedUser['id'];
        $listings = $this->repository->findByUser($userId);
        
        return Response::json(["success" => true, "data" => $listings]);
    }

    public function getDetail($data) {
        $id = $data['id'] ?? 0;
        $item = $this->repository->findById($id);
        
        if (!$item) {
            return Response::json(["success" => false, "message" => "Anúncio não encontrado"], 404);
        }
        
        $item['gallery'] = $this->repository->getImages($id);
        return Response::json(["success" => true, "data" => $item]);
    }

    private function generateSlug($text) {
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        $text = preg_replace('~[^-\w]+~', '', $text);
        $text = trim($text, '-');
        return strtolower($text) . '-' . rand(100, 999);
    }

    // ==================== ADMIN METHODS ====================

    public function adminList($data, $loggedUser) {
        if (!$loggedUser || !in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER', 'SUPPORT'])) {
            return Response::json(['success' => false, 'message' => 'Acesso restrito'], 403);
        }

        $filters = [
            'status' => $data['status'] ?? null,
            'category' => $data['category'] ?? null,
            'search' => $data['search'] ?? null,
            'is_affiliate' => $data['is_affiliate'] ?? null
        ];

        $listings = $this->repository->findAll($filters);

        if (!empty($listings)) {
            $ids = array_column($listings, 'id');
            $allImages = $this->repository->getImagesForList($ids);
            
            foreach ($listings as &$item) {
                $item['images'] = $allImages[$item['id']] ?? [];
                if (empty($item['main_image']) && !empty($item['images'])) {
                    $item['main_image'] = $item['images'][0];
                }
            }
        }

        return Response::json([
            'success' => true,
            'data' => $listings
        ]);
    }

    public function adminGet($data, $loggedUser, $id) {
        if (!$loggedUser || !in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER', 'SUPPORT'])) {
            return Response::json(['success' => false, 'message' => 'Acesso restrito'], 403);
        }

        $item = $this->repository->findByIdAdmin($id);
        if (!$item) {
            return Response::json(['success' => false, 'message' => 'Anúncio não encontrado'], 404);
        }

        $item['gallery'] = $this->repository->getImages($id);

        return Response::json([
            'success' => true,
            'data' => $item
        ]);
    }

    public function adminCreate($data, $loggedUser) {
        if (!$loggedUser || !in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER'])) {
            return Response::json(['success' => false, 'message' => 'Acesso restrito'], 403);
        }

        if (empty($data['title']) || empty($data['user_id'])) {
            return Response::json(['success' => false, 'message' => 'Dados insuficientes'], 400);
        }

        $data['slug'] = $this->generateSlug($data['title']);
        
        // Processar imagens
        $imageUrls = [];
        if (!empty($_FILES['images'])) {
            $files = $this->reArrayFiles($_FILES['images']);
            foreach ($files as $file) {
                $url = $this->uploadFile($file);
                if ($url) $imageUrls[] = $url;
            }
        } elseif (!empty($data['images']) && is_array($data['images'])) {
            foreach ($data['images'] as $img) {
                if (is_string($img) && filter_var($img, FILTER_VALIDATE_URL)) {
                    $imageUrls[] = $img;
                }
            }
        }
        $data['main_image'] = $imageUrls[0] ?? null;
        
        $listingId = $this->repository->save($data);
        
        if (!$listingId) {
            return Response::json(['success' => false, 'message' => 'Erro ao salvar anúncio'], 500);
        }
        
        foreach ($imageUrls as $index => $url) {
            $this->repository->addImage($listingId, $url, $index);
        }

        return Response::json([
            'success' => true, 
            'id' => $listingId, 
            'slug' => $data['slug'],
            'message' => 'Anúncio criado com sucesso'
        ], 201);
    }

    public function update($data, $loggedUser, $id = null) {
        if (!$loggedUser) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        // Validar conteúdo com ContentFilter
        if (!empty($data['title']) && !ContentFilterService::isClean($data['title'])) {
            $reason = ContentFilterService::getReason($data['title']);
            return Response::json(['success' => false, 'message' => $reason ?: "O título contém conteúdo não permitido."], 400);
        }
        if (!empty($data['description']) && !ContentFilterService::isClean($data['description'])) {
            $reason = ContentFilterService::getReason($data['description']);
            return Response::json(['success' => false, 'message' => $reason ?: "A descrição contém conteúdo não permitido."], 400);
        }

        $listingId = $id ?? ($data['id'] ?? null);
        if (!$listingId) {
            return Response::json(['success' => false, 'message' => 'ID do anúncio não informado'], 400);
        }

        $listingId = (int)$listingId;
        $existing = $this->repository->findById($listingId);
        
        if (!$existing) {
            return Response::json(['success' => false, 'message' => 'Anúncio não encontrado'], 404);
        }

        if ($existing['user_id'] !== $loggedUser['id']) {
            return Response::json(['success' => false, 'message' => 'Você não tem permissão para editar este anúncio'], 403);
        }

        // Validar acesso a afiliados se estiver tentando ativar afiliação
        $wantsAffiliate = array_key_exists('is_affiliate', $data) && $data['is_affiliate'] == true;
        $wasAffiliate = !empty($existing['is_affiliate']);
        
        if ($wantsAffiliate && !$wasAffiliate && !in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER'])) {
            $stmt = $this->db->prepare("SELECT has_affiliate_access FROM users WHERE id = ?");
            $stmt->execute([$loggedUser['id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user || !$user['has_affiliate_access']) {
                return Response::json([
                    'success' => false, 
                    'message' => 'Você não tem acesso ao recurso de afiliados. Solicite acesso em Marketplace.'
                ], 403);
            }
        }

        try {
            // Processar imagens se houver upload
            $imageUrls = [];
            if (!empty($_FILES['images'])) {
                $files = $this->reArrayFiles($_FILES['images']);
                foreach ($files as $file) {
                    $url = $this->uploadFile($file);
                    if ($url) $imageUrls[] = $url;
                }
                
                // Atualizar main_image se houver nova imagem
                if (!empty($imageUrls)) {
                    $data['main_image'] = $imageUrls[0];
                }
            }
            
            $this->repository->update($listingId, $data);
            
            // Atualizar coordenadas se cidade mudou
            $city = $data['location_city'] ?? $existing['location_city'];
            $state = $data['location_state'] ?? $existing['location_state'];
            if ($city && $state) {
                $coords = $this->geocodingService->geocodeCity($city, $state);
                if ($coords) {
                    $this->repository->updateCoords($listingId, $coords['lat'], $coords['lng']);
                }
            }
            
            // Adicionar novas imagens se houver
            if (!empty($imageUrls)) {
                foreach ($imageUrls as $index => $url) {
                    $this->repository->addImage($listingId, $url, $existing['images_count'] ?? 0 + $index);
                }
            }
            
            return Response::json(['success' => true, 'message' => 'Anúncio atualizado']);
        } catch (Exception $e) {
            return Response::json(['success' => false, 'message' => 'Erro ao atualizar'], 500);
        }
    }

    public function delete($data, $loggedUser, $id = null) {
        if (!$loggedUser) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $listingId = $id ?? ($data['id'] ?? null);
        if (!$listingId) {
            return Response::json(['success' => false, 'message' => 'ID do anúncio não informado'], 400);
        }

        $listingId = (int)$listingId;
        $existing = $this->repository->findById($listingId);
        
        if (!$existing) {
            return Response::json(['success' => false, 'message' => 'Anúncio não encontrado'], 404);
        }

        if ($existing['user_id'] !== $loggedUser['id']) {
            return Response::json(['success' => false, 'message' => 'Você não tem permissão para excluir este anúncio'], 403);
        }

        try {
            $this->repository->delete($listingId);
            return Response::json(['success' => true, 'message' => 'Anúncio excluído']);
        } catch (Exception $e) {
            return Response::json(['success' => false, 'message' => 'Erro ao excluir'], 500);
        }
    }

    public function getMyListing($data, $loggedUser) {
        if (!$loggedUser) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $listingId = $data['id'] ?? null;
        if (!$listingId) {
            return Response::json(['success' => false, 'message' => 'ID do anúncio não informado'], 400);
        }

        $listing = $this->repository->findById((int)$listingId);
        
        if (!$listing) {
            return Response::json(['success' => false, 'message' => 'Anúncio não encontrado'], 404);
        }

        $isAdmin = in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER']);
        
        if ($listing['user_id'] !== $loggedUser['id'] && !$isAdmin) {
            return Response::json(['success' => false, 'message' => 'Você não tem permissão para ver este anúncio'], 403);
        }

        $listing['gallery'] = $this->repository->getImages((int)$listingId);

        return Response::json(['success' => true, 'data' => $listing]);
    }

    public function boost($data, $loggedUser) {
        if (!$loggedUser) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $listingId = (int)($data['listing_id'] ?? 0);
        $type = $data['type'] ?? 'featured';
        
        if (!$listingId) {
            return Response::json(['success' => false, 'message' => 'ID do anúncio não informado'], 400);
        }

        $listing = $this->repository->findById($listingId);
        if (!$listing) {
            return Response::json(['success' => false, 'message' => 'Anúncio não encontrado'], 404);
        }

        if ($listing['user_id'] !== $loggedUser['id']) {
            return Response::json(['success' => false, 'message' => 'Você não tem permissão para impulsionar este anúncio'], 403);
        }

        $validTypes = ['featured', 'bump', 'sponsored'];
        if (!in_array($type, $validTypes)) {
            $type = 'featured';
        }
        
        $pricePerUse = 9.90;
        $durationDays = 7;

        try {
            $stmt = $this->db->prepare("
                SELECT * FROM pricing_rules 
                WHERE module_key = 'marketplace' 
                AND feature_key = :key 
                AND is_active = 1
            ");
            $stmt->execute([':key' => $type]);
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($rule) {
                $pricePerUse = floatval($rule['price_per_use'] ?? 9.90);
                $durationDays = intval($rule['duration_days'] ?? 7);
            }
        } catch (\Exception $e) {}

        if ($type === 'featured') {
            if (!empty($listing['is_featured']) && !empty($listing['featured_until']) && 
                strtotime($listing['featured_until']) > time()) {
                return Response::json(['success' => false, 'message' => 'Anúncio já possui destaque ativo'], 400);
            }
        }

        $typeNames = [
            'featured' => 'Destaque Anúncio',
            'bump' => 'Prorrogar Anúncio',
            'sponsored' => 'Anúncio Patrocinado'
        ];

        $paymentData = [
            'listing_id' => $listingId,
            'type' => $type,
            'amount' => $pricePerUse,
            'title' => "Impulsionar Anúncio #{$listingId} - {$typeNames[$type]}",
            'duration_days' => $durationDays,
            'description' => "{$durationDays} dias de visibilidade para {$listing['title']}"
        ];

        try {
            $result = $this->mpService->createListingPromotionPreference($paymentData, $loggedUser['id']);
            
            if (!empty($result['init_point'])) {
                return Response::json([
                    'success' => true,
                    'checkout_url' => $result['init_point'],
                    'transaction_id' => $result['transaction_id'],
                    'amount' => $pricePerUse,
                    'type' => $type,
                    'duration_days' => $durationDays
                ]);
            }
        } catch (\Exception $e) {
            return Response::json(['success' => false, 'message' => 'Erro ao gerar pagamento: ' . $e->getMessage()], 500);
        }

        return Response::json(['success' => false, 'message' => 'Erro ao gerar link de pagamento'], 500);
    }

    public function extend($data, $loggedUser) {
        if (!$loggedUser) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $listingId = (int)($data['listing_id'] ?? 0);
        if (!$listingId) {
            return Response::json(['success' => false, 'message' => 'ID do anúncio não informado'], 400);
        }

        $listing = $this->repository->findById($listingId);
        if (!$listing) {
            return Response::json(['success' => false, 'message' => 'Anúncio não encontrado'], 404);
        }

        if ($listing['user_id'] !== $loggedUser['id']) {
            return Response::json(['success' => false, 'message' => 'Você não tem permissão para prorrogar este anúncio'], 403);
        }

        $isAdmin = in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER']);

        $pricePerUse = 6.90;
        $durationDays = 7;

        try {
            $stmt = $this->db->prepare("
                SELECT * FROM pricing_rules 
                WHERE module_key = 'marketplace' 
                AND feature_key = 'bump' 
                AND is_active = 1
            ");
            $stmt->execute();
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($rule) {
                $pricePerUse = floatval($rule['price_per_use'] ?? 6.90);
                $durationDays = intval($rule['duration_days'] ?? 7);
            }
        } catch (\Exception $e) {}

        $amount = $pricePerUse;
        $paymentRequired = !$isAdmin;

        if ($paymentRequired) {
            $balance = $this->creditService->getBalance($loggedUser['id']);
            if ($balance < $amount) {
                return Response::json([
                    'success' => false,
                    'message' => 'Saldo insuficiente',
                    'balance' => $balance,
                    'required' => $amount
                ], 402);
            }
            $debited = $this->creditService->debit($loggedUser['id'], $amount, 'marketplace', 'bump');
            if (!$debited) {
                return Response::json(['success' => false, 'message' => 'Erro ao debitar saldo'], 500);
            }
        }

        $currentExpiry = $listing['expires_at'] ? strtotime($listing['expires_at']) : time();
        $newExpiry = max($currentExpiry, time()) + ($durationDays * 24 * 60 * 60);
        $expiresAt = date('Y-m-d H:i:s', $newExpiry);

        $this->repository->extendExpiration($listingId, $expiresAt);

        return Response::json([
            'success' => true,
            'message' => "Anúncio prorrogado por mais {$durationDays} dia(s)!",
            'expires_at' => $expiresAt,
            'duration_days' => $durationDays,
            'cost' => $paymentRequired ? $amount : 0
        ]);
    }

    public function adminUpdate($data, $loggedUser, $id) {
        if (!$loggedUser || !in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER'])) {
            return Response::json(['success' => false, 'message' => 'Acesso restrito'], 403);
        }

        $existing = $this->repository->findByIdAdmin($id);
        if (!$existing) {
            return Response::json(['success' => false, 'message' => 'Anúncio não encontrado'], 404);
        }

        try {
            $this->repository->update($id, $data);
            return Response::json(['success' => true, 'message' => 'Anúncio atualizado']);
        } catch (Exception $e) {
            return Response::json(['success' => false, 'message' => 'Erro ao atualizar'], 500);
        }
    }

    public function adminDelete($data, $loggedUser, $id) {
        if (!$loggedUser || !in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER'])) {
            return Response::json(['success' => false, 'message' => 'Acesso restrito'], 403);
        }

        $existing = $this->repository->findByIdAdmin($id);
        if (!$existing) {
            return Response::json(['success' => false, 'message' => 'Anúncio não encontrado'], 404);
        }

        try {
            $this->repository->delete($id);
            return Response::json(['success' => true, 'message' => 'Anúncio excluído']);
        } catch (Exception $e) {
            return Response::json(['success' => false, 'message' => 'Erro ao excluir'], 500);
        }
    }
}