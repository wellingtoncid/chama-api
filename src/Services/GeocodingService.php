<?php

namespace App\Services;

use App\Core\Database;
use PDO;

class GeocodingService {
    private PDO $db;
    
    public function __construct(PDO $db = null) {
        $this->db = $db ?? Database::getConnection();
    }
    
    /**
     * Busca coordenadas a partir de CEP usando ViaCEP + Nominatim
     */
    public function geocodeFromCep(string $cep): ?array {
        $cep = preg_replace('/\D/', '', $cep);
        
        if (strlen($cep) !== 8) {
            return null;
        }
        
        // ViaCEP para obter endereço
        $viacepUrl = "https://viacep.com.br/ws/{$cep}/json/";
        $context = stream_context_create([
            'http' => ['timeout' => 5]
        ]);
        
        $response = @file_get_contents($viacepUrl, false, $context);
        
        if (!$response) {
            return null;
        }
        
        $data = json_decode($response, true);
        
        if (isset($data['erro']) || !isset($data['localidade'])) {
            return null;
        }
        
        $address = "{$data['logradouro']}, {$data['bairro']}, {$data['localidade']}, {$data['uf']}, Brasil";
        
        return $this->geocodeAddress($address);
    }
    
    /**
     * Geocodifica um endereço usando Nominatim (OpenStreetMap)
     * Rate limit: 1 req/s
     */
    public function geocodeAddress(string $address): ?array {
        $query = http_build_query([
            'q' => $address,
            'format' => 'json',
            'limit' => 1,
            'addressdetails' => 1
        ]);
        
        $url = "https://nominatim.openstreetmap.org/search?{$query}";
        
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header' => [
                    "User-Agent: ChamaFrete/1.0 (contato@chamafrete.com.br)\r\n"
                ]
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if (!$response) {
            return null;
        }
        
        $results = json_decode($response, true);
        
        if (empty($results)) {
            return null;
        }
        
        $result = $results[0];
        
        return [
            'lat' => (float)$result['lat'],
            'lng' => (float)$result['lon'],
            'display_name' => $result['display_name'],
            'type' => $result['type'] ?? 'address',
            'importance' => (float)($result['importance'] ?? 0)
        ];
    }
    
    /**
     * Geocodifica cidade/estado para coordenadas aproximadas
     */
    public function geocodeCity(string $city, string $state): ?array {
        $address = "{$city}, {$state}, Brasil";
        return $this->geocodeAddress($address);
    }
    
    /**
     * Salva coordenadas no perfil do usuário
     */
    public function saveDriverLocation(int $userId, float $lat, float $lng, ?string $city = null, ?string $state = null): bool {
        try {
            $stmt = $this->db->prepare("
                UPDATE user_profiles 
                SET home_lat = ?, home_lng = ?, home_city = ?, home_state = ?
                WHERE user_id = ?
            ");
            return $stmt->execute([$lat, $lng, $city, $state, $userId]);
        } catch (\Throwable $e) {
            error_log("Erro saveDriverLocation: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Salva coordenadas no frete
     */
    public function saveFreightLocation(int $freightId, float $originLat, float $originLng, float $destLat, float $destLng): bool {
        try {
            $stmt = $this->db->prepare("
                UPDATE freights 
                SET origin_lat = ?, origin_lng = ?, dest_lat = ?, dest_lng = ?
                WHERE id = ?
            ");
            return $stmt->execute([$originLat, $originLng, $destLat, $destLng, $freightId]);
        } catch (\Throwable $e) {
            error_log("Erro saveFreightLocation: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Calcula distância entre duas coordenadas (Haversine)
     */
    public function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float {
        $earthRadius = 6371; // km
        
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng / 2) * sin($dLng / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return round($earthRadius * $c, 2);
    }
}
