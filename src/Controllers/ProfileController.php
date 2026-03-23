<?php

namespace App\Controllers;

use App\Core\Response;
use Exception;
use PDO;

class ProfileController {
    private $db;
    private $loggedUser;

    public function __construct($db, $loggedUser = null) {
        $this->db = $db;
        $this->loggedUser = $loggedUser;
    }

    private function authorize() {
        if (!$this->loggedUser) {
            throw new Exception("Sessão expirada", 401);
        }
    }

    public function get($data, $loggedUser) {
        $this->authorize();
        
        try {
            $userId = $data['user_id'] ?? $loggedUser['id'];
            $isOwn = $userId == $loggedUser['id'];
            
            $stmt = $this->db->prepare("
                SELECT u.id, u.name, u.email, u.phone, u.whatsapp, u.role, u.user_type,
                    u.city, u.state, u.avatar, u.plan_type, u.status, u.created_at,
                    u.is_verified, u.rating_avg, u.rating_count,
                    p.bio, p.avatar_url, p.cover_url, p.instagram, p.website,
                    p.social_links, p.full_address, p.documents,
                    p.rntrc_number, p.verification_status, p.availability_status,
                    p.vehicle_type, p.body_type, p.preferred_region,
                    p.views_count, p.clicks_count
                FROM users u
                LEFT JOIN user_profiles p ON u.id = p.user_id
                WHERE u.id = ?
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                return Response::json(["success" => false, "message" => "Usuário não encontrado"], 404);
            }
            
            if ($user['social_links']) {
                $user['social_links'] = json_decode($user['social_links'], true);
            }
            if ($user['full_address']) {
                $user['full_address'] = json_decode($user['full_address'], true);
            }
            if ($user['documents']) {
                $user['documents'] = json_decode($user['documents'], true);
            }
            
            return Response::json([
                "success" => true,
                "data" => $user,
                "is_own" => $isOwn
            ]);
        } catch (\Throwable $e) {
            return Response::json(["success" => false, "message" => "Erro ao carregar perfil"]);
        }
    }

    public function update($data, $loggedUser) {
        $this->authorize();
        
        try {
            $userId = $loggedUser['id'];
            $isAdmin = in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER']);
            
            if ($isAdmin && !empty($data['user_id'])) {
                $userId = $data['user_id'];
            }
            
            $userFields = ['name', 'phone', 'whatsapp', 'city', 'state'];
            $profileFields = ['bio', 'instagram', 'website', 'vehicle_type', 'body_type', 'preferred_region'];
            
            $this->db->beginTransaction();
            
            $userData = [];
            foreach ($userFields as $field) {
                if (isset($data[$field])) {
                    $userData[$field] = $data[$field];
                }
            }
            
            if (!empty($userData)) {
                $sets = [];
                foreach (array_keys($userData) as $key) {
                    $sets[] = "$key = :$key";
                }
                $sql = "UPDATE users SET " . implode(', ', $sets) . " WHERE id = :id";
                $userData['id'] = $userId;
                
                $userStmt = $this->db->prepare($sql);
                $userStmt->execute($userData);
            }
            
            $profileData = [];
            foreach ($profileFields as $field) {
                if (isset($data[$field])) {
                    $profileData[$field] = $data[$field];
                }
            }
            
            if (isset($data['facebook']) || isset($data['linkedin'])) {
                $socialLinks = [];
                $getStmt = $this->db->prepare("SELECT social_links FROM user_profiles WHERE user_id = ?");
                $getStmt->execute([$userId]);
                $existing = $getStmt->fetch(PDO::FETCH_ASSOC);
                if ($existing && $existing['social_links']) {
                    $socialLinks = json_decode($existing['social_links'], true) ?: [];
                }
                if (isset($data['facebook'])) $socialLinks['facebook'] = $data['facebook'];
                if (isset($data['linkedin'])) $socialLinks['linkedin'] = $data['linkedin'];
                $profileData['social_links'] = json_encode($socialLinks);
            }
            
            if (!empty($profileData)) {
                $checkStmt = $this->db->prepare("SELECT id FROM user_profiles WHERE user_id = ?");
                $checkStmt->execute([$userId]);
                $exists = $checkStmt->fetch();
                
                if ($exists) {
                    $sets = [];
                    foreach (array_keys($profileData) as $key) {
                        $sets[] = "$key = :$key";
                    }
                    $profileData['user_id'] = $userId;
                    $sql = "UPDATE user_profiles SET " . implode(', ', $sets) . " WHERE user_id = :user_id";
                } else {
                    $profileData['user_id'] = $userId;
                    $profileData['slug'] = 'user_' . $userId . '_' . time();
                    $sql = "INSERT INTO user_profiles SET " . implode(', ', array_map(function($k) { return "$k = :$k"; }, array_keys($profileData)));
                }
                
                $profileStmt = $this->db->prepare($sql);
                $profileStmt->execute($profileData);
            }
            
            $this->db->commit();
            
            return Response::json([
                "success" => true,
                "message" => "Perfil atualizado com sucesso"
            ]);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return Response::json(["success" => false, "message" => "Erro ao atualizar perfil"]);
        }
    }

    public function updateAvatar($data, $loggedUser) {
        $this->authorize();
        
        try {
            $userId = $loggedUser['id'];
            $avatarUrl = $data['avatar_url'] ?? $data['avatar'] ?? '';
            
            if (empty($avatarUrl)) {
                return Response::json(["success" => false, "message" => "Avatar URL é obrigatório"], 400);
            }
            
            $checkStmt = $this->db->prepare("SELECT id FROM user_profiles WHERE user_id = ?");
            $checkStmt->execute([$userId]);
            
            if ($checkStmt->fetch()) {
                $stmt = $this->db->prepare("UPDATE user_profiles SET avatar_url = ? WHERE user_id = ?");
                $stmt->execute([$avatarUrl, $userId]);
            } else {
                $stmt = $this->db->prepare("INSERT INTO user_profiles (user_id, avatar_url, slug) VALUES (?, ?, ?)");
                $stmt->execute([$userId, $avatarUrl, 'user_' . $userId . '_' . time()]);
            }
            
            return Response::json([
                "success" => true,
                "message" => "Avatar atualizado com sucesso"
            ]);
        } catch (\Throwable $e) {
            return Response::json(["success" => false, "message" => "Erro ao atualizar avatar"]);
        }
    }

    public function getActivity($data, $loggedUser) {
        $this->authorize();
        
        try {
            $userId = $data['user_id'] ?? $loggedUser['id'];
            $limit = $data['limit'] ?? 20;
            $page = $data['page'] ?? 1;
            $offset = ($page - 1) * $limit;
            
            $stmt = $this->db->prepare("
                SELECT * FROM logs_auditoria 
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->bindValue(1, $userId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->bindValue(3, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $countStmt = $this->db->prepare("SELECT COUNT(*) as total FROM logs_auditoria WHERE user_id = ?");
            $countStmt->execute([$userId]);
            $total = $countStmt->fetch()['total'];
            
            return Response::json([
                "success" => true,
                "data" => $activities,
                "total" => $total,
                "page" => $page,
                "limit" => $limit
            ]);
        } catch (\Throwable $e) {
            return Response::json(["success" => false, "message" => "Erro ao carregar atividades"]);
        }
    }

    public function logActivity($userId, $action, $description = '', $entityType = null, $entityId = null) {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            $stmt = $this->db->prepare("
                INSERT INTO logs_auditoria (user_id, action_type, description, target_type, target_id, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $action, $description, $entityType, $entityId, $ip, $agent]);
        } catch (\Throwable $e) {
            error_log("Error logging activity: " . $e->getMessage());
        }
    }
}
