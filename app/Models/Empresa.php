<?php

class Empresa
{
    private $db;

    public function __construct(PDO $db) { $this->db = $db; }

    public function obtener()
    {
        $row = $this->db->query('SELECT nombre_comercial, logo_ruta, color_primario,
            color_secundario, color_acento FROM empresa WHERE id = 1')->fetch();
        if (!$row) { throw new HttpError('No se encontró la configuración del negocio.', 503); }
        $row['logo_url'] = $row['logo_ruta'] ? '/PuntoVenta/public/api/empresa/logo?v=' . hash('sha256', $row['logo_ruta']) : null;
        unset($row['logo_ruta']);
        return $row;
    }

    public function guardar(array $datos, $archivo = null)
    {
        $nombre = isset($datos['nombre_comercial']) && is_string($datos['nombre_comercial']) ? trim($datos['nombre_comercial']) : '';
        if (!preg_match('/^.{1,150}$/us', $nombre)) { throw new HttpError('Escribe un nombre de negocio de hasta 150 caracteres.'); }
        $colores = array();
        foreach (array('color_primario', 'color_secundario', 'color_acento') as $campo) {
            if (!isset($datos[$campo]) || !is_string($datos[$campo]) || !preg_match('/^#[0-9A-Fa-f]{6}$/D', $datos[$campo])) {
                throw new HttpError('Selecciona un color válido en cada selector.');
            }
            $colores[] = strtoupper($datos[$campo]);
        }
        $quitarLogo = isset($datos['quitar_logo']) && $datos['quitar_logo'] === '1';
        $nuevo = null;
        $storage = __DIR__ . '/../../storage/';
        if ($archivo && (int) $archivo['error'] !== UPLOAD_ERR_NO_FILE) {
            if ((int) $archivo['error'] !== UPLOAD_ERR_OK) { throw new HttpError('No se pudo cargar el logo. Elige una imagen de hasta 2 MB.'); }
            if (!is_uploaded_file($archivo['tmp_name']) || $archivo['size'] > 2 * 1024 * 1024 || $archivo['size'] < 1) {
                throw new HttpError('El logo debe ser una imagen de hasta 2 MB.');
            }
            $formatos = array('image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp');
            $size = @getimagesize($archivo['tmp_name']);
            $mime = $size && isset($size['mime']) ? $size['mime'] : '';
            if (class_exists('finfo')) { $mime = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']); }
            if (!isset($formatos[$mime]) || !$size || $size['mime'] !== $mime || $size[0] > 4096 || $size[1] > 4096) {
                throw new HttpError('Usa un logo PNG, JPG o WebP de hasta 4096 × 4096 píxeles.');
            }
            $dir = $storage . 'uploads/logos';
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) { throw new RuntimeException('No se pudo crear el directorio de logos.'); }
            $nuevo = 'uploads/logos/' . bin2hex(random_bytes(16)) . '.' . $formatos[$mime];
            if (!move_uploaded_file($archivo['tmp_name'], $storage . $nuevo)) { throw new RuntimeException('No se pudo guardar el logo.'); }
        }
        try {
            $this->db->beginTransaction();
            $sql = 'UPDATE empresa SET nombre_comercial = ?, color_primario = ?, color_secundario = ?, color_acento = ?';
            $values = array_merge(array($nombre), $colores);
            if ($nuevo !== null || $quitarLogo) { $sql .= ', logo_ruta = ?'; $values[] = $nuevo; }
            $sql .= ' WHERE id = 1';
            $this->db->prepare($sql)->execute($values);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            if ($nuevo !== null) { unlink($storage . $nuevo); }
            throw $e;
        }
        return $this->obtener();
    }

    public function logo()
    {
        $ruta = $this->db->query('SELECT logo_ruta FROM empresa WHERE id = 1')->fetchColumn();
        if (!$ruta || !preg_match('#^uploads/logos/[a-f0-9]{32}\.(png|jpg|webp)$#D', $ruta, $match)) {
            throw new HttpError('Logo no encontrado.', 404);
        }
        $path = __DIR__ . '/../../storage/' . $ruta;
        if (!is_file($path)) { throw new HttpError('Logo no encontrado.', 404); }
        $types = array('png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp');
        header('Content-Type: ' . $types[$match[1]]);
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    public static function textoContraste($color)
    {
        $rgb = array_map('hexdec', str_split(substr($color, 1), 2));
        $linear = array_map(function ($n) { $v = $n / 255; return $v <= 0.04045 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4); }, $rgb);
        $luz = .2126 * $linear[0] + .7152 * $linear[1] + .0722 * $linear[2];
        return (1.05 / ($luz + .05)) >= (($luz + .05) / .05) ? '#FFFFFF' : '#000000';
    }

    public function estilos()
    {
        $e = $this->obtener();
        foreach (array('color_primario', 'color_secundario', 'color_acento') as $key) {
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/D', $e[$key])) { throw new RuntimeException('Color inválido en configuración.'); }
        }
        $p = $e['color_primario']; $s = $e['color_secundario']; $a = $e['color_acento'];
        $onP = self::textoContraste($p); $onS = self::textoContraste($s);
        header('Content-Type: text/css; charset=utf-8');
        echo ':root{--brand-primary:' . $p . ';--brand-secondary:' . $s . ';--brand-accent:' . $a . ';--on-primary:' . $onP . ';--on-secondary:' . $onS . ';}';
        exit;
    }
}
