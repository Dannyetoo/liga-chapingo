-- =====================================================================
-- Liga Premier Chapingo · MÓDULO FINANCIERO · Fase 3 (seguridad + estructura)
--
--   BASE: u577450088_liga_chapingo  (PRODUCCIÓN)
--   Ábrelo desde el phpMyAdmin de u577450088_liga_chapingo.
--
--   No destructivo:
--     · sólo CREATE TABLE IF NOT EXISTS e INSERT IGNORE;
--     · no hay ALTER, DROP, UPDATE ni DELETE sobre tablas existentes;
--     · no copia cobros ni gastos: la fuente sigue siendo arbitrajes/datos/estado.json.
--   Se puede ejecutar dos veces sin duplicar nada.
--   Los campos *_ref guardan ids de estado.json (texto sensible a mayúsculas).
-- =====================================================================
SET NAMES utf8mb4;

-- Cada tabla lleva el nombre de la base de PRODUCCIÓN por delante, así funciona
-- sin importar qué base esté seleccionada en phpMyAdmin.

-- ---------------------------------------------------------------------
-- A) SEGURIDAD: administradores del panel /disponibilidad/admin/
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `u577450088_liga_chapingo`.admin_usuarios (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario         VARCHAR(60)  NOT NULL,
  nombre          VARCHAR(120) NOT NULL,
  clave_hash      VARCHAR(255) NOT NULL,              -- password_hash() de PHP; nunca la contraseña
  puede_finanzas  TINYINT(1)   NOT NULL DEFAULT 0,    -- 1 = ve el módulo Finanzas
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  ultimo_acceso   TIMESTAMP    NULL DEFAULT NULL,
  creado_en       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_usuario (usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Intentos de acceso (freno contra adivinar contraseñas). Sólo crece; se puede
-- vaciar cuando se quiera sin afectar nada más.
CREATE TABLE IF NOT EXISTS `u577450088_liga_chapingo`.admin_intentos (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario    VARCHAR(60)  NOT NULL,
  ip         VARCHAR(45)  NOT NULL,
  exito      TINYINT(1)   NOT NULL DEFAULT 0,
  creado_en  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ai_usuario (usuario, creado_en),
  KEY idx_ai_ip (ip, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- B) FINANZAS
-- ---------------------------------------------------------------------

-- 1) Parámetros de proyección, versionados: un cambio = una fila nueva.
--    Vigente = la fila más reciente con vigente_desde <= NOW().
--    Si no hay parámetro, el sistema usa un estimado automático y lo rotula así.
CREATE TABLE IF NOT EXISTS `u577450088_liga_chapingo`.fin_parametros (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  edicion_ref    VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  categoria_ref  VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',  -- '' = toda la edición
  clave          VARCHAR(60)  NOT NULL,
  valor          DECIMAL(12,2) NOT NULL,
  vigente_desde  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  motivo         VARCHAR(255) NULL,
  creado_por     VARCHAR(60)  NOT NULL,
  creado_en      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fp_busqueda (edicion_ref, categoria_ref, clave, vigente_desde),
  CONSTRAINT chk_fp_valor CHECK (valor >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Catálogo ampliable de categorías de gasto.
CREATE TABLE IF NOT EXISTS `u577450088_liga_chapingo`.fin_categorias_gasto (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre    VARCHAR(80)  NOT NULL,
  orden     SMALLINT     NOT NULL DEFAULT 0,
  activo    TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fcg_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `u577450088_liga_chapingo`.fin_categorias_gasto (nombre, orden) VALUES
  ('Premios',10),('Trofeos',20),('Material deportivo',30),('Mantenimiento',40),
  ('Cancha',50),('Administración',60),('Publicidad',70),('Transporte',80),('Otros',90);

-- 3) Ingresos y gastos PROYECTADOS o COMPROMETIDOS (todavía no son dinero).
--    Nunca se borran: se cancelan. Al volverse reales se registran en Tesorería
--    y aquí pasan a 'realizado' con el id del movimiento real.
CREATE TABLE IF NOT EXISTS `u577450088_liga_chapingo`.fin_compromisos (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo               ENUM('ingreso','gasto') NOT NULL,
  categoria_gasto_id INT UNSIGNED NULL,
  edicion_ref        VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  categoria_ref      VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',  -- '' = General de la liga
  partido_clave      VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  concepto           VARCHAR(150) NOT NULL,
  descripcion        VARCHAR(255) NULL,
  monto              DECIMAL(12,2) NOT NULL,
  fecha_estimada     DATE         NULL,
  estado             ENUM('proyectado','comprometido','realizado','cancelado') NOT NULL DEFAULT 'proyectado',
  origen_real_id     VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  responsable        VARCHAR(100) NULL,
  metodo_pago        VARCHAR(40)  NULL,
  observaciones      VARCHAR(255) NULL,
  creado_por         VARCHAR(60)  NOT NULL,
  creado_en          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_por    VARCHAR(60)  NULL,
  actualizado_en     TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fc_ambito (edicion_ref, categoria_ref, estado),
  KEY idx_fc_partido (partido_clave),
  CONSTRAINT chk_fc_monto CHECK (monto >= 0),
  CONSTRAINT fk_fc_catgasto FOREIGN KEY (categoria_gasto_id) REFERENCES `u577450088_liga_chapingo`.fin_categorias_gasto (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Huellas: historial de cada cobro, gasto y multa de estado.json.
--    Una fila por VERSIÓN distinta de cada registro. Si Tesorería lo modifica,
--    aparece una versión nueva; si lo borra, su última versión queda con
--    eliminado_en. Aquí nunca se borra nada.
CREATE TABLE IF NOT EXISTS `u577450088_liga_chapingo`.fin_huellas (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  origen        ENUM('mov','gasto','multa') NOT NULL,
  origen_id     VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  hash          CHAR(64)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  datos         LONGTEXT     NOT NULL,
  rev_estado    INT UNSIGNED NOT NULL,
  visto_primero TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  visto_ultimo  TIMESTAMP    NULL DEFAULT NULL,
  eliminado_en  TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fh_version (origen, origen_id, hash),
  KEY idx_fh_registro (origen, origen_id, visto_primero),
  KEY idx_fh_rev (rev_estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) Bitácora: accesos al panel y acciones del módulo.
CREATE TABLE IF NOT EXISTS `u577450088_liga_chapingo`.fin_bitacora (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario     VARCHAR(60)  NOT NULL,
  accion      VARCHAR(40)  NOT NULL,
  entidad     VARCHAR(40)  NOT NULL,
  entidad_id  VARCHAR(64)  NULL,
  antes       LONGTEXT     NULL,
  despues     LONGTEXT     NULL,
  ip          VARCHAR(45)  NULL,
  creado_en   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fb_entidad (entidad, entidad_id),
  KEY idx_fb_fecha (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comprobación (sólo lectura): deben aparecer las 7 tablas nuevas.
SELECT TABLE_NAME FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = 'u577450088_liga_chapingo'
   AND TABLE_NAME IN ('admin_usuarios','admin_intentos','fin_parametros','fin_categorias_gasto',
                      'fin_compromisos','fin_huellas','fin_bitacora');
