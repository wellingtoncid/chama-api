<?php

namespace Tests\Integration;

use App\Repositories\FreightRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class FreightRepositoryTest extends TestCase
{
    private ?PDO $pdo = null;
    private ?FreightRepository $repository = null;

    protected function setUp(): void
    {
        try {
            $this->pdo = $this->createPDO();
            $this->repository = new FreightRepository($this->pdo);
        } catch (\PDOException $e) {
            $this->markTestSkipped("Banco de teste indisponível: " . $e->getMessage());
        }
    }

    public function testFindByIdReturnsNullForInvalidId(): void
    {
        $result = $this->repository->findById(999999);
        $this->assertNull($result);
    }

    public function testCreateFreightInsertsRecord(): void
    {
        $data = [
            'user_id' => 1,
            'account_id' => 1,
            'product' => 'Teste Unitário',
            'origin_city' => 'São Paulo',
            'origin_state' => 'SP',
            'dest_city' => 'Rio de Janeiro',
            'dest_state' => 'RJ',
            'price' => 1500.00,
            'vehicle_type' => 'Carreta',
            'body_type' => 'Bauc',
            'weight' => 10000,
            'status' => 'OPEN',
            'description' => 'Teste de integração',
            'expires_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
            'is_featured' => 0,
            'slug' => 'teste-integracao-' . uniqid(),
        ];

        $id = $this->repository->save($data);
        $this->assertNotEmpty($id);
        $this->assertGreaterThan(0, (int)$id);

        // Cleanup
        $stmt = $this->pdo->prepare('DELETE FROM freights WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    private function createPDO(): PDO
    {
        $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
        $port = $_ENV['DB_PORT'] ?? '3306';
        $name = $_ENV['DB_NAME'] ?? 'chama_frete_test';
        $user = $_ENV['DB_USER'] ?? 'root';
        $pass = $_ENV['DB_PASS'] ?? '';

        return new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
}
