-- =====================================================================
-- Liga Chapingo · Fase 4 · Canchas  — SQL NO DESTRUCTIVO
-- Base: u577450088_liga_test (ENTORNO DE PRUEBAS). NO ejecutar en producción.
-- Sólo CREATE TABLE IF NOT EXISTS + un INSERT protegido. No borra ni altera nada existente.
-- =====================================================================

-- PASO 0 (verificación, sólo lectura) ---------------------------------
-- Las FOREIGN KEY exigen el MISMO tipo que semestres.id.
-- Si aquí aparece "int(10) unsigned" / "int unsigned", cambia abajo
--   semestre_id INT NOT NULL   →   semestre_id INT UNSIGNED NOT NULL
-- (en las dos tablas). Si aparece "int(11)" / "int", no cambies nada.
SHOW COLUMNS FROM semestres LIKE 'id';
-- Deben ser InnoDB (transacciones + FK):
SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('semestres', 'equipos');

-- PASO 1 · Tablas ------------------------------------------------------
CREATE TABLE IF NOT EXISTS canchas (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre     VARCHAR(100) NOT NULL,
    ubicacion  VARCHAR(150) NULL,
    activo     TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_canchas_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS disponibilidad_cancha (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cancha_id   INT UNSIGNED NOT NULL,
    semestre_id INT NOT NULL,
    dia_semana  TINYINT UNSIGNED NOT NULL,          -- 1 = lunes … 7 = domingo
    hora_inicio TIME NOT NULL,
    hora_fin    TIME NOT NULL,
    activo      TINYINT(1) NOT NULL DEFAULT 1,
    creado_en   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_dc_cancha_sem (cancha_id, semestre_id, activo, dia_semana),
    KEY idx_dc_semestre (semestre_id),
    CONSTRAINT fk_dc_cancha   FOREIGN KEY (cancha_id)   REFERENCES canchas (id),
    CONSTRAINT fk_dc_semestre FOREIGN KEY (semestre_id) REFERENCES semestres (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS excepciones_cancha (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cancha_id   INT UNSIGNED NOT NULL,
    semestre_id INT NOT NULL,
    fecha       DATE NOT NULL,
    tipo        ENUM('no_disponible', 'disponibilidad_extra') NOT NULL,
    hora_inicio TIME NULL,
    hora_fin    TIME NULL,
    motivo      VARCHAR(255) NULL,
    creado_en   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ec_cancha_sem_fecha (cancha_id, semestre_id, fecha),
    KEY idx_ec_semestre (semestre_id),
    CONSTRAINT fk_ec_cancha   FOREIGN KEY (cancha_id)   REFERENCES canchas (id),
    CONSTRAINT fk_ec_semestre FOREIGN KEY (semestre_id) REFERENCES semestres (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PASO 2 · Primera cancha de prueba (no se duplica si ya existe) ---------
INSERT INTO canchas (nombre, ubicacion, activo)
SELECT 'DICIFO', NULL, 1 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM canchas WHERE nombre = 'DICIFO');

-- PASO 3 · Verificación ------------------------------------------------
SELECT id, nombre, activo FROM canchas;

-- La disponibilidad semanal y las excepciones de DICIFO se registran desde
-- /disponibilidad/admin/canchas.php (no hace falta SQL).
