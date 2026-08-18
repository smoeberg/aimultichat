<?php
declare(strict_types=1);

namespace Core;

class Database
{
    private static ?\PDO $instance = null;
    
    private function __construct() {}
    
    public static function getInstance(): \PDO
    {
        if (self::$instance === null) {
            $host=\configValue('DB_HOST');
            $port=(int)(\configValue('DB_PORT','3306')??3306);
            $name=\configValue('DB_NAME');
            $user=\configValue('DB_USER');
            $password=\configValue('DB_PASSWORD','')??'';
            foreach (['DB_HOST'=>$host, 'DB_NAME'=>$name, 'DB_USER'=>$user] as $required=>$value) {
                if ($value === null || $value === '') {
                    throw new \RuntimeException('Databasekonfigurationen mangler. Kør setup.php først.');
                }
            }
            if (!extension_loaded('pdo_mysql')) {
                throw new \RuntimeException('PHP extension pdo_mysql is not installed.');
            }
            self::$instance = new \PDO(
                sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $host,
                    max(1, min(65535, $port)),
                    $name
                ),
                $user,
                $password,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                    \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci'
                ]
            );
        }
        
        return self::$instance;
    }
    
    public static function beginTransaction(): void
    {
        self::getInstance()->beginTransaction();
    }
    
    public static function commit(): void
    {
        self::getInstance()->commit();
    }
    
    public static function rollback(): void
    {
        self::getInstance()->rollBack();
    }
}
