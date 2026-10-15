-- =====================================================================
-- Liga Chapingo · «Busca un rival» · Actualización 2026-09-29 — SQL NO DESTRUCTIVO
-- RECOMENDADO: en lugar de este archivo, entra como administrador a
--   /disponibilidad/admin/actualizar.php  y pulsa «Instalar actualización»
--   (hace lo mismo y además ajusta automáticamente las columnas ENUM).
-- Usa este archivo sólo si prefieres phpMyAdmin. No borra ni cambia datos.
-- =====================================================================

CREATE TABLE IF NOT EXISTS rival_reprog_opciones (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reprog_id INT UNSIGNED NOT NULL,
    fecha     DATE NOT NULL,
    hora      TIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rro (reprog_id, fecha, hora),
    KEY idx_rro_slot (fecha, hora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rival_propuestas (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    grupo_ref       VARCHAR(190) NOT NULL,
    cancha_id       INT UNSIGNED NOT NULL,
    fecha           DATE NOT NULL,
    hora            TIME NOT NULL,
    equipo_a_id     INT NOT NULL,
    equipo_a_nombre VARCHAR(150) NOT NULL,
    equipo_b_id     INT NOT NULL,
    equipo_b_nombre VARCHAR(150) NOT NULL,
    resp_a          VARCHAR(12) NOT NULL DEFAULT 'pendiente',
    resp_b          VARCHAR(12) NOT NULL DEFAULT 'pendiente',
    estado          VARCHAR(12) NOT NULL DEFAULT 'pendiente',
    motivo          VARCHAR(255) NULL,
    creado_por      VARCHAR(80) NULL,
    reserva_id      INT UNSIGNED NULL,
    creado_en       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resuelta_en     DATETIME NULL,
    actualizado_en  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rp_estado (estado, fecha),
    KEY idx_rp_a (equipo_a_id, estado),
    KEY idx_rp_b (equipo_b_id, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rival_peticiones (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tipo          VARCHAR(20) NOT NULL,
    equipo_id     INT NOT NULL,
    equipo_nombre VARCHAR(150) NOT NULL,
    reserva_id    INT UNSIGNED NULL,
    partido_txt   VARCHAR(255) NULL,
    mensaje       VARCHAR(500) NOT NULL,
    estado        VARCHAR(12) NOT NULL DEFAULT 'pendiente',
    respuesta     VARCHAR(255) NULL,
    atendida_por  VARCHAR(80) NULL,
    creado_en     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resuelta_en   DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_pet_estado (estado, creado_en),
    KEY idx_pet_equipo (equipo_id, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rival_notif_visto (
    equipo_id  INT NOT NULL,
    ultimo_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    visto_en   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (equipo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rival_jugados (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    grupo_ref       VARCHAR(190) NULL,
    equipo_a_id     INT NOT NULL,
    equipo_a_nombre VARCHAR(150) NOT NULL,
    equipo_b_id     INT NOT NULL,
    equipo_b_nombre VARCHAR(150) NOT NULL,
    par_llave       VARCHAR(40) NOT NULL,
    fecha           DATE NULL,
    origen          VARCHAR(20) NOT NULL DEFAULT 'manual',
    nota            VARCHAR(255) NULL,
    creado_por      VARCHAR(80) NULL,
    creado_en       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rj_par (par_llave),
    KEY idx_rj_a (equipo_a_id),
    KEY idx_rj_b (equipo_b_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MariaDB (Hostinger) acepta IF NOT EXISTS en columnas:
ALTER TABLE reservas_cancha ADD COLUMN IF NOT EXISTS motivo_cancelacion VARCHAR(255) NULL;
ALTER TABLE reservas_cancha ADD COLUMN IF NOT EXISTS cancelada_en DATETIME NULL;

-- IMPORTANTE (sólo si esas columnas son ENUM): revisa con
--   SHOW COLUMNS FROM reservas_cancha LIKE 'estado';
--   SHOW COLUMNS FROM push_cola LIKE 'tipo';
--   SHOW COLUMNS FROM rival_eventos LIKE 'tipo';
-- Si son ENUM, deben aceptar además:
--   reservas_cancha.estado → 'cancelada'
--   push_cola.tipo        → 'propuesta','partido_cambio','peticion','cancha'
--   rival_eventos.tipo    → 'horario_agregado','horario_eliminado','propuesta_admin','propuesta_resp',
--                           'partido_editado','partido_cancelado','cancha_hora_cancelada','peticion','jugado'
-- admin/actualizar.php lo hace solo, conservando los valores que ya existen.
