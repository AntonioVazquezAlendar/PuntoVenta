-- Mantiene el acceso previo al catálogo sin sobrescribir decisiones ya guardadas.
UPDATE roles SET permisos = JSON_SET(COALESCE(permisos, JSON_OBJECT()), '$.ver_productos', CAST('true' AS JSON))
WHERE JSON_CONTAINS_PATH(COALESCE(permisos, JSON_OBJECT()), 'one', '$.ver_productos') = 0
  AND nombre <> 'Administrador'
  AND COALESCE(JSON_EXTRACT(permisos, '$.todo'), FALSE) <> TRUE;
