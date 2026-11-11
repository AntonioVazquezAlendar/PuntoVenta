<?php

require_once __DIR__ . '/../Models/Empresa.php';

class EmpresaController
{
    private $db;
    private $empresa;

    public function __construct(PDO $db) { $this->db = $db; $this->empresa = new Empresa($db); }
    public function obtener() { Response::json(true, 'Identidad del negocio', $this->empresa->obtener()); }
    public function estilos() { $this->empresa->estilos(); }
    public function logo() { $this->empresa->logo(); }
    public function guardar()
    {
        (new Auth($this->db))->administrador();
        Auth::csrf();
        if (isset($_FILES['logo']) && !is_array($_FILES['logo']['error'])) {
            $logo = $_FILES['logo'];
        } elseif (isset($_FILES['logo'])) { throw new HttpError('Selecciona un solo logo.'); }
        else { $logo = null; }
        Response::json(true, 'Identidad del negocio guardada', $this->empresa->guardar($_POST, $logo));
    }
}
