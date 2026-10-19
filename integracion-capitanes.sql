-- =====================================================================
-- Liga Chapingo · Integración Estado de cuenta → Disponibilidad
-- SQL NO DESTRUCTIVO. Base: u577450088_liga_test (ENTORNO DE PRUEBAS).
-- Sólo CREATE TABLE IF NOT EXISTS. No altera ni borra nada existente.
-- =====================================================================

-- PASO 0 (sólo lectura): la FK exige el MISMO tipo que equipos.id.
-- Si aquí aparece "unsigned", cambia abajo  equipo_id INT NOT NULL  →  equipo_id INT UNSIGNED NOT NULL
SHOW COLUMNS FROM equipos LIKE 'id';

-- PASO 1: vínculo explícito  id del equipo en estado.json  ↔  equipos.id
--   · un equipo del Estado de cuenta sólo puede apuntar a UN equipo (UNIQUE);
--   · estado_nombre es sólo referencia visual: NUNCA se usa para autorizar.
CREATE TABLE IF NOT EXISTS equipos_estado_cuenta (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    equipo_id        INT NOT NULL,
    estado_equipo_id VARCHAR(64) NOT NULL,
    estado_nombre    VARCHAR(150) NULL,
    creado_en        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_estado_equipo (estado_equipo_id),
    KEY idx_eec_equipo (equipo_id),
    CONSTRAINT fk_eec_equipo FOREIGN KEY (equipo_id) REFERENCES equipos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PASO 2 (verificación)
SELECT COUNT(*) AS vinculos FROM equipos_estado_cuenta;

-- Los vínculos se crean desde /disponibilidad/admin/vinculos.php (no hace falta SQL).
