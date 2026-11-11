<?php
require_once __DIR__.'/ReporteVenta.php';require_once __DIR__.'/Credito.php';require_once __DIR__.'/CuentaPagar.php';require_once __DIR__.'/AlertaInventario.php';
class Inicio {
 private $db;
 public function __construct(PDO $db){$this->db=$db;}
 private function query($sql,array $args=array()){$q=$this->db->prepare($sql);$q->execute($args);return $q->fetchAll();}
 public function obtener(array $actor){
  if(empty($actor['permisos']['todo']))throw new HttpError('Necesitas una cuenta de administrador.',403);
  $now=new DateTimeImmutable('now',new DateTimeZone('America/Mexico_City'));$today=$now->format('Y-m-d');$report=new ReporteVenta($this->db);$filters=$report->filtros(array('desde'=>$today,'hasta'=>$today),$actor);$oldZone=$this->db->query('SELECT @@SESSION.time_zone')->fetchColumn();
  $this->db->exec("SET time_zone='-06:00'");$this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();try{
   $sales=$report->resumen($filters);
   $expense=$this->query('SELECT COUNT(*) registros,CAST(COALESCE(SUM(monto),0) AS DECIMAL(24,2)) total FROM gastos WHERE fecha=?',array($today))[0];
   $receivable=Credito::saldoSql('c');$credits=$this->query('SELECT COUNT(*) cuentas,CAST(COALESCE(SUM(s.saldo),0) AS DECIMAL(24,2)) saldo,COALESCE(SUM(s.vence<?),0) vencidas,CAST(COALESCE(SUM(CASE WHEN s.vence<? THEN s.saldo ELSE 0 END),0) AS DECIMAL(24,2)) saldo_vencido FROM (SELECT c.vence,'.$receivable.' saldo FROM creditos_venta c) s WHERE s.saldo>0',array($today,$today))[0];
   $payable=CuentaPagar::saldoSql('c');$vendors=$this->query("SELECT COUNT(*) cuentas,CAST(COALESCE(SUM(s.saldo),0) AS DECIMAL(24,2)) saldo,COALESCE(SUM(s.vence<?),0) vencidas,CAST(COALESCE(SUM(CASE WHEN s.vence<? THEN s.saldo ELSE 0 END),0) AS DECIMAL(24,2)) saldo_vencido,COALESCE(SUM(s.vence IS NULL),0) sin_vencimiento FROM (SELECT c.vence,".$payable." saldo FROM compras c WHERE c.estatus='recibida' AND c.saldo_base IS NOT NULL) s WHERE s.saldo>0",array($today,$today))[0];
   $vendors['por_conciliar']=(int)$this->db->query("SELECT COUNT(*) FROM compras WHERE estatus='recibida' AND saldo_base IS NULL")->fetchColumn();
   $cashCount=(int)$this->db->query("SELECT COUNT(*) FROM turnos_caja WHERE estatus='abierto'")->fetchColumn();
   $cash=$this->query("SELECT t.id,c.nombre caja,u.nombre responsable,l.nombre ubicacion,UNIX_TIMESTAMP(t.abierto_en) abierto_unix FROM turnos_caja t JOIN cajas c ON c.id=t.caja_id JOIN usuarios u ON u.id=t.abierto_por LEFT JOIN ubicaciones l ON l.id=COALESCE(t.ubicacion_id,c.ubicacion_id) WHERE t.estatus='abierto' ORDER BY t.abierto_en,t.id LIMIT 8");
   $inventory=(new AlertaInventario($this->db))->resumen();
   $this->db->commit();return array('hoy'=>$today,'actualizado_unix'=>$now->getTimestamp(),'ventas'=>$sales,'gastos'=>$expense,'creditos'=>$credits,'proveedores'=>$vendors,'cajas'=>array('total'=>$cashCount,'turnos'=>$cash),'inventario'=>$inventory);
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}finally{$this->db->exec('SET SESSION time_zone='.$this->db->quote($oldZone));}
 }
}
