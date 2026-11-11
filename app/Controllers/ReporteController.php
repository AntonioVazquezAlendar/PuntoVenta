<?php
require_once __DIR__.'/../Models/ReporteVenta.php';
class ReporteController
{
    private $db;
    public function __construct(PDO $db){$this->db=$db;}
    public function consultar($accion){
        $actor=(new Auth($this->db))->permiso('ver_reportes');$model=new ReporteVenta($this->db);
        if($accion==='catalogos')Response::json(true,'Filtros del reporte',$model->catalogos($actor));
        $result=$model->obtener($_GET,$actor,$accion==='excel');
        if($accion==='excel'){require_once __DIR__.'/../Exports/ReporteExcel.php';ReporteExcel::descargar($result);}
        Response::json(true,'Reporte de ventas',$result);
    }
}
