<?php
class Database {
    private static $instance = null;
    public static function getConnection() {
        if (!self::$instance) {
            try {
                self::$instance = new PDO(
                    "mysql:host=".$_ENV['DB_HOST'].";port=".$_ENV['DB_PORT'].";dbname=".$_ENV['DB_NAME'].";charset=utf8",
                    $_ENV['DB_USER'], 
                    $_ENV['DB_PASS'],
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, 
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        // Força o MySQL a usar o horário de Brasília para o NOW() bater com o PHP
                        PDO::MYSQL_ATTR_INIT_COMMAND => "SET names utf8; SET time_zone = '-03:00'"
                    ]
                );
            } catch (PDOException $e) {
                die(json_encode(["error" => "Falha na conexão: " . $e->getMessage()]));
            }
        }
        return self::$instance;
    }
}