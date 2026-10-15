-- ENTORNO DE PRUEBAS · base u577450088_liga_test
-- No destructivo: sólo crea la tabla si no existe. No hay ALTER, DROP ni DELETE.
-- (admin/vinculos.php puede crearla con un botón y detecta el tipo solo;
--  este archivo es la alternativa manual desde phpMyAdmin.)

-- 1) Revisa el tipo de equipos.id:
SHOW COLUMNS FROM equipos LIKE 'id';
--    Si dice "int(10) unsigned" / "int unsigned", cambia abajo
--    `equipo_id INT NOT NULL` por `equipo_id INT UNSIGNED NOT NULL`.

-- 2) Crea la tabla de vínculos:
CREATE TABLE IF NOT EXISTS equipos_estado_cuenta (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  equipo_id INT NOT NULL,
  estado_equipo_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  estado_nombre VARCHAR(150) NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_estado_equipo (estado_equipo_id),
  KEY idx_eec_equipo (equipo_id),
  CONSTRAINT fk_eec_equipo FOREIGN KEY (equipo_id) REFERENCES equipos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Verificación:
SELECT v.estado_equipo_id, v.estado_nombre, v.equipo_id, e.nombre, e.activo
  FROM equipos_estado_cuenta v
  JOIN equipos e ON e.id = v.equipo_id;
