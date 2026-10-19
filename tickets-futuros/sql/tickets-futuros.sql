-- Tickets futuros · no destructivo (sólo crea la tabla si no existe).
-- Ejecutar en la base del entorno correspondiente:
--   pruebas:    u577450088_liga_test
--   producción: u577450088_liga_chapingo
-- (admin/index.php también la crea con un botón.)

CREATE TABLE IF NOT EXISTS tickets_futuros (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  equipo_estado_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,  -- id del equipo en estado.json
  equipo_nombre VARCHAR(150) NOT NULL,                                               -- nombre al momento de crear (referencia)
  jornada SMALLINT UNSIGNED NULL,
  fecha_estimada DATE NULL,
  concepto VARCHAR(150) NOT NULL,
  importe DECIMAL(10,2) NOT NULL,
  nota VARCHAR(255) NULL,
  visible_capitan TINYINT(1) NOT NULL DEFAULT 0,                                     -- 1 = el capitán lo ve en su Estado de cuenta
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tf_equipo (equipo_estado_id, visible_capitan, jornada),
  KEY idx_tf_jornada (jornada)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
