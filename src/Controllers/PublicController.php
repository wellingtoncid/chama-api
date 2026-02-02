<?php
namespace App\Controllers;

use App\Core\Response;
use App\Repositories\FreightRepository;
use App\Repositories\UserRepository;

class PublicController {
    private $freightRepo;
    private $userRepo;

    public function __construct($db) {
        $this->freightRepo = new FreightRepository($db);
        $this->userRepo = new UserRepository($db);
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

    public function getPublicProfile($data) {
        $slug = $data['slug'] ?? ''; 
        
        try {
            $profile = $this->userRepo->findBySlug($slug); 

            if (!$profile) {
                return Response::json(["success" => false, "message" => "Perfil não encontrado."], 404);
            }

            // 1. Tratamento de Labels por tipo de perfil
            $userTypeLabel = 'Usuário';
            switch ($profile['user_type']) {
                case 'DRIVER': $userTypeLabel = 'Motorista'; break;
                case 'COMPANY': $userTypeLabel = 'Transportadora'; break;
                case 'SHIPPER': $userTypeLabel = 'Embarcador'; break;
            }

            // 2. Padronização de dados para o Front (Booleano de Verificação)
            $profile['is_verified'] = ($profile['verification_status'] === 'verified');
            $profile['rating_avg'] = number_format((float)($profile['rating_avg'] ?? 5.0), 1);
            
            // 3. WhatsApp: Sanitização para o botão "Chamar no Zap"
            $profile['whatsapp_clean'] = preg_replace('/[^0-9]/', '', $profile['whatsapp'] ?? '');

            // 4. Customização de SEO Dinâmico
            $title = "{$userTypeLabel} Verificado: {$profile['name']}";
            
            // Descrição muda conforme o tipo: se for motorista, mostra o veículo.
            $description = "Avaliação: {$profile['rating_avg']}⭐";
            if ($profile['user_type'] === 'DRIVER' && !empty($profile['vehicle_type'])) {
                $description .= " | Veículo: {$profile['vehicle_type']} - {$profile['body_type']}";
            }
            $description .= " | Ver perfil completo e contatos no Chama Frete.";

            $seo = [
                "title" => $title,
                "description" => $description,
                "og_image" => $profile['avatar_url'] ?? "https://chamafrete.com.br/assets/img/default-avatar.jpg",
                "type" => "profile"
            ];

            return Response::json([
                "success" => true,
                "data" => $profile,
                "seo" => $seo
            ]);

        } catch (\Exception $e) {
            error_log("Erro getPublicProfile: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro interno."], 500);
        }
    }
}