<?php

class Database
{
    private $host = '127.0.0.1';
    private $database = 'pos_mx';
    private $username = 'root';
    private $password = 'root1234';

    private $connection;

    public function connect($original = false)
    {
        try {
            // Selección local de demos por CLI; la base original permanece configurada arriba.
            $database = $this->database;
            $selection = __DIR__ . '/../../storage/demo_database.json';
            if (!($original && PHP_SAPI === 'cli') && is_file($selection)) {
                $demo = json_decode(file_get_contents($selection), true);
                if (!is_array($demo) || !isset($demo['base']) || !is_string($demo['base']) ||
                    !preg_match('/^pos_demo_(abarrotes|ropa|agroquimica|restaurante|ferreteria)$/D', $demo['base'])) {
                    throw new RuntimeException('La selección de demo no es válida.');
                }
                $database = $demo['base'];
            }
            $this->connection = new PDO(
                "mysql:host={$this->host};dbname={$database};charset=utf8mb4",
                $this->username,
                $this->password,
                array(
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                )
            );

            return $this->connection;

        } catch (PDOException $e) {
            error_log($e->getMessage());
            throw new Exception('Error al conectar con la base de datos.');
        }
    }
}
