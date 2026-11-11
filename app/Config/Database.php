<?php

class Database
{
    private $host = '127.0.0.1';
    private $database = 'pos_mx';
    private $username = 'root';
    private $password = 'root1234';

    private $connection;

    public function connect()
    {
        try {
            $this->connection = new PDO(
                "mysql:host={$this->host};dbname={$this->database};charset=utf8mb4",
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
