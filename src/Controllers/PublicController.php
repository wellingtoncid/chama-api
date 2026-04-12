<?php
namespace App\Controllers;

use App\Core\Response;
use App\Repositories\FreightRepository;
use App\Repositories\UserRepository;
use App\Repositories\AdRepository;
use App\Repositories\ListingRepository;

class PublicController {
    private $freightRepo;
    private $userRepo;
    private $adRepo;
    private $listingRepo;

    public function __construct($db) {
        $this->freightRepo = new FreightRepository($db);
        $this->userRepo = new UserRepository($db);
        $this->adRepo = new AdRepository($db);
        $this->listingRepo = new ListingRepository($db);
    }

    public function getFreightDetails($data, $loggedUser = null) {
        // 1. Captura e Sanitização (Segurança contra caracteres especiais na URL)
        $identifier = isset($data['slug']) ? trim(strip_tags($data['slug'])) : '';
        
        if (empty($identifier)) {
            return Response::json([
                "success" => false, 
                "message" => "Identificador da carga não fornecido."
            ], 400);
        }

        try {
            // 2. Busca Híbrida: Tenta pelo Slug primeiro
            $freight = $this->freightRepo->findBySlug($identifier);

            // 3. Fallback: Se não achou pelo slug literal, tenta extrair o ID do final da string
            if (!$freight) {
                $parts = explode('-', $identifier);
                $idOnly = (int)end($parts);
                if ($idOnly > 0) {
                    $freight = $this->freightRepo->findById($idOnly);
                }
            }

            // 4. Validação de Existência Real (404)
            // Agora só damos 404 se o registro REALMENTE não existir no banco.
            if (!$freight) {
                return Response::json([
                    "success" => false, 
                    "message" => "Carga não encontrada ou removida."
                ], 404);
            }

            /**
             * MELHORIA: Removida a trava de Status (OPEN/ACTIVE).
             * Justificativa: Se a carga existe (mesmo PENDING ou CLOSED), ela deve abrir.
             * A regra de "pode contatar" agora vem do campo 'can_contact' gerado no Repository.
             */

            // 5. Identificação do Usuário e Favoritos
            $currentUserId = null;
            if ($loggedUser) {
                $currentUserId = is_object($loggedUser) ? ($loggedUser->id ?? null) : ($loggedUser['id'] ?? null);
            }
            
            $freight['is_favorite'] = $currentUserId ? $this->freightRepo->checkFavorite($freight['id'], $currentUserId) : false;

            // 6. Registro de Métrica (Silencioso para não travar a resposta)
            try {
                $this->freightRepo->logMetric($freight['id'], 'FREIGHT', $currentUserId, 'VIEW_DETAILS');
            } catch (\Exception $e) {
                error_log("Erro métrica: " . $e->getMessage());
            }

            // 7. Preparação de SEO e Formatação de Exibição
            $price = (float)($freight['price'] ?? 0);
            $displayPrice = $price > 0 ? "R$ " . number_format($price, 2, ',', '.') : "A Combinar";
            
            $origin = ($freight['origin_city'] ?? 'Origem') . "/" . ($freight['origin_state'] ?? '??');
            $dest = ($freight['dest_city'] ?? 'Destino') . "/" . ($freight['dest_state'] ?? '??');
            $productName = $freight['product'] ?? 'Carga';

            $seo = [
                "title" => "🚛 $productName | $origin -> $dest",
                "description" => "Oportunidade de frete: $productName saindo de $origin para $dest. Valor: $displayPrice. Confira no Chama Frete.",
                "og_image" => $freight['avatar_url'] ?? "https://chamafrete.com.br/assets/img/share-default.jpg",
                "canonical" => "https://chamafrete.com.br/frete/" . ($freight['slug'] ?? $freight['id']),
                "type" => "article"
            ];

            // 8. Retorno de Sucesso
            return Response::json([
                "success" => true,
                "data" => $freight,
                "seo" => $seo
            ]);

        } catch (\Exception $e) {
            error_log("❌ ERRO CRÍTICO getFreightDetails [ID/SLUG: $identifier]: " . $e->getMessage());
            return Response::json([
                "success" => false, 
                "message" => "Ocorreu um erro ao processar sua solicitação interna."
            ], 500);
        }
    }

    public function getProfilePage($data) {
        $slug = isset($data['slug']) ? trim($data['slug']) : '';
        if (empty($slug)) {
            return Response::json(["success" => false, "message" => "Identificador obrigatório."], 400);
        }

        try {
            // O findBySlug agora já traz o display_name e dados da empresa (conforme ajustamos no Repo)
            $profile = $this->userRepo->findBySlug($slug);
            if (!$profile) {
                return Response::json(["success" => false, "message" => "Perfil não encontrado."], 404);
            }

            // Incrementa visualização toda vez que o perfil público é acessado
            $this->userRepo->incrementStats($profile['id'], 'VIEW');

            // --- TRATAMENTO DE DADOS PARA O FRONT ---    
            // WhatsApp limpo para o link do botão (usado no ProfileView.tsx)
            $profile['whatsapp_clean'] = preg_replace('/\D/', '', $profile['whatsapp'] ?? '');
            
            // Status de verificação: só mostra se is_verified = 1 E (verified_until é NULL ou não expirou)
            $isVerified = (int)($profile['is_verified'] ?? 0) === 1;
            $verifiedUntil = $profile['verified_until'] ?? null;
            $isNotExpired = !$verifiedUntil || strtotime($verifiedUntil) > time();
            $profile['is_verified'] = $isVerified && $isNotExpired;

            // --- SEO DINÂMICO ---
            $userTypeLabel = match($profile['user_type']) {
                'DRIVER'     => 'Motorista',
                'ADVERTISER' => 'Parceiro Anunciante',
                'COMPANY'    => 'Transportadora',
                'SHIPPER'    => 'Embarcador',
                default      => 'Logística'
            };

            $title = "{$profile['display_name']} | {$userTypeLabel} verificado no Chama Frete";
            
            // Descrição curta para Google/Social
            $seoDescription = $profile['bio'] ?? "Confira os serviços de {$profile['display_name']}. Localizado em {$profile['city']}-{$profile['state']}.";

            return Response::json([
                "success" => true,
                "data" => $profile,
                "seo" => [
                    "title" => $title,
                    "description" => $seoDescription,
                    "og_image" => $profile['avatar_url'] ?? "https://chamafrete.com.br/assets/img/default-avatar.jpg",
                    "type" => "profile"
                ]
            ]);

        } catch (\Exception $e) {
            error_log("Erro getPublicProfile: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao carregar perfil."], 500);
        }
    }

    public function getPublicPosts($data) {
        $userId = isset($data['user_id']) ? (int)$data['user_id'] : null;
        if (!$userId) return Response::json(["success" => false, "message" => "ID ausente"], 400);

        try {
            $user = $this->userRepo->getUserTypeAndName($userId);
            if (!$user) return Response::json(["success" => false, "message" => "Usuário não encontrado"], 404);

            $results = [];
            
            if ($user['user_type'] === 'ADVERTISER') {
                $results = $this->adRepo->getAdsByUserId($userId);
            } else {
                // Busca fretes do usuário
                $freights = $this->freightRepo->getPublicPostsByUser($userId);
                
                // Busca marketplace listings do usuário
                $listings = $this->listingRepo->findByUser($userId);
                
                // Adiciona type para identificar o tipo
                foreach ($freights as &$f) { $f['type'] = 'freight'; }
                foreach ($listings as &$l) { $l['type'] = 'marketplace'; }
                
                $results = array_merge($freights, $listings);
            }

            return Response::json([
                "success" => true,
                "user_type" => $user['user_type'],
                "data" => $results
            ]);
        } catch (\Exception $e) {
            error_log("Erro getPublicPosts: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao carregar itens"], 500);
        }
    }

    // Método para o click do WhatsApp (vincular a uma rota POST no index)
    public function trackWhatsAppClick($data) {
        $userId = (int)($data['id'] ?? 0);
        if ($userId > 0) {
            $this->userRepo->incrementStats($userId, 'CLICK');
            return Response::json(["success" => true]);
        }
        return Response::json(["success" => false], 400);
    }
}