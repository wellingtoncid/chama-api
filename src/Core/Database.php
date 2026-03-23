<?php
namespace App\Core;

use PDO;
use PDOException;

class Database {
    private static $instance = null;

    public static function getConnection() {
        if (self::$instance === null) {
            try {
                $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
                $db   = $_ENV['DB_NAME'] ?? 'chama_frete_dev';
                $user = $_ENV['DB_USER'] ?? 'root';
                $pass = $_ENV['DB_PASS'] ?? 'Root@Chama123#';

                // Suporte a cópia de BD para debug/teste sem tocar no código central
                $useCopyDb = isset($_ENV['DB_USE_COPY']) ? $_ENV['DB_USE_COPY'] : '0';
                if (in_array(strtolower((string)$useCopyDb), ['1', 'true', 'yes'], true)) {
                    $copyName   = $_ENV['DB_COPY_NAME'] ?? $db;
                    $copyHost   = $_ENV['DB_COPY_HOST'] ?? $host;
                    $copyUser   = $_ENV['DB_COPY_USER'] ?? $user;
                    $copyPass   = $_ENV['DB_COPY_PASS'] ?? $pass;
                    // Log para diagnóstico (não expõe senhas em produção)
                    error_log("DB COPY ENABLED -> host=$copyHost, db=$copyName, user=$copyUser");
                    $db   = $copyName;
                    $host = $copyHost;
                    $user = $copyUser;
                    $pass = $copyPass;
                }
                $port = $_ENV['DB_PORT'] ?? '3306'; 

                $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";

                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false, 
                    PDO::ATTR_PERSISTENT         => false,
                    // Garante fuso horário e charset na conexão
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '-03:00', sql_mode='NO_ENGINE_SUBSTITUTION'"
                ];

                self::$instance = new PDO($dsn, $user, $pass, $options);
                
            } catch (PDOException $e) {
                error_log("Erro de Conexão: " . $e->getMessage());
                
                if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
                    // Se o erro for "Access denied", verifique usuário e senha no .env
                    http_response_code(500);
                    die(json_encode(["success" => false, "message" => "Erro de banco: " . $e->getMessage()]));
                }
                
                http_response_code(500);
                die(json_encode(["success" => false, "message" => "Erro interno de conexão."]));
            }
        }
        return self::$instance;
    }

    // --- MÉTODOS AUXILIARES PARA TRANSAÇÕES ---

    public static function beginTransaction() {
        return self::getConnection()->beginTransaction();
    }

    public static function commit() {
        return self::getConnection()->commit();
    }

    public static function rollBack() {
        // Verifica se há uma transação ativa antes de dar rollback para evitar warnings
        if (self::getConnection()->inTransaction()) {
            return self::getConnection()->rollBack();
        }
    }

    /**
     * IMPORTANTE: Adicionado para o seu UserRepository.php funcionar
     */
    public static function inTransaction() {
        return self::getConnection()->inTransaction();
    }
}
