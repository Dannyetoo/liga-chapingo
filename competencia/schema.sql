-- MySQL 5.7+/MariaDB. Ejecutar en la base configurada en disponibilidad/db.php.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS cp_usuarios (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 usuario VARCHAR(60) NOT NULL UNIQUE, nombre VARCHAR(120) NOT NULL,
 clave_hash VARCHAR(255) NOT NULL, rol ENUM('administrador','coordinador','equipo') NOT NULL,
 activo TINYINT NOT NULL DEFAULT 1, version_clave INT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cp_espacios (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(150) NOT NULL,
 edicion_ref VARCHAR(64) COLLATE utf8mb4_bin NOT NULL UNIQUE,
 propietario_id BIGINT UNSIGNED NOT NULL, coordinador_id BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY (propietario_id) REFERENCES cp_usuarios(id),
 FOREIGN KEY (coordinador_id) REFERENCES cp_usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cp_espacios_archivados (
 espacio_id BIGINT UNSIGNED PRIMARY KEY, archivado_por BIGINT UNSIGNED NOT NULL,
 archivado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (espacio_id) REFERENCES cp_espacios(id), FOREIGN KEY (archivado_por) REFERENCES cp_usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cp_canchas (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, espacio_id BIGINT UNSIGNED NOT NULL,
 nombre VARCHAR(120) NOT NULL, horarios TEXT NOT NULL DEFAULT '', activo TINYINT NOT NULL DEFAULT 1,
 FOREIGN KEY (espacio_id) REFERENCES cp_espacios(id), KEY cancha_espacio (espacio_id,activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cp_equipos (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, espacio_id BIGINT UNSIGNED NOT NULL,
 origen_ref VARCHAR(64) COLLATE utf8mb4_bin NOT NULL UNIQUE,
 nombre VARCHAR(150) NOT NULL, categoria VARCHAR(150) NOT NULL DEFAULT '',
 usuario_id BIGINT UNSIGNED NULL UNIQUE,
 UNIQUE KEY uq_equipo_espacio (espacio_id,id),
 FOREIGN KEY (espacio_id) REFERENCES cp_espacios(id), FOREIGN KEY (usuario_id) REFERENCES cp_usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cp_partidos (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, espacio_id BIGINT UNSIGNED NOT NULL,
 local_id BIGINT UNSIGNED NOT NULL, visita_id BIGINT UNSIGNED NOT NULL,
 fecha DATE NOT NULL, hora TIME NOT NULL, cancha VARCHAR(100) NOT NULL,
 duracion SMALLINT UNSIGNED NOT NULL DEFAULT 60,
 tipo ENUM('torneo','amistoso') NOT NULL DEFAULT 'torneo',
 estado ENUM('programado','jugado','cancelado') NOT NULL DEFAULT 'programado',
 goles_local SMALLINT UNSIGNED NULL, goles_visita SMALLINT UNSIGNED NULL,
 version INT NOT NULL DEFAULT 1,
 FOREIGN KEY (espacio_id,local_id) REFERENCES cp_equipos(espacio_id,id),
 FOREIGN KEY (espacio_id,visita_id) REFERENCES cp_equipos(espacio_id,id),
 KEY agenda (fecha,hora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cp_reportes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, espacio_id BIGINT UNSIGNED NOT NULL,
 equipo_id BIGINT UNSIGNED NOT NULL, mensaje VARCHAR(1000) NOT NULL,
 estado ENUM('pendiente','resuelto') NOT NULL DEFAULT 'pendiente', respuesta VARCHAR(1000) NULL,
 creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (espacio_id,equipo_id) REFERENCES cp_equipos(espacio_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Libro inmutable: cargos/pagos/gastos nuevos. El histórico se consulta en su fuente JSON.
CREATE TABLE IF NOT EXISTS cp_movimientos (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, espacio_id BIGINT UNSIGNED NOT NULL,
 equipo_id BIGINT UNSIGNED NULL, tipo ENUM('cargo','pago','gasto') NOT NULL,
 monto DECIMAL(12,2) NOT NULL, concepto VARCHAR(150) NOT NULL, fecha DATE NOT NULL,
 creado_por BIGINT UNSIGNED NOT NULL, solicitud CHAR(32) NOT NULL UNIQUE,
 FOREIGN KEY (espacio_id) REFERENCES cp_espacios(id),
 FOREIGN KEY (espacio_id,equipo_id) REFERENCES cp_equipos(espacio_id,id),
 FOREIGN KEY (creado_por) REFERENCES cp_usuarios(id), KEY finanzas (espacio_id,fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cp_bitacora (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, usuario_id BIGINT UNSIGNED NOT NULL,
 espacio_id BIGINT UNSIGNED NULL, accion VARCHAR(60) NOT NULL, detalle TEXT NOT NULL,
 creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cp_intentos (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, usuario VARCHAR(60) NOT NULL,
 ip VARCHAR(45) NOT NULL, creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 KEY freno_ip (ip,creado_en), KEY freno_usuario (usuario,creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Marca accesos migrados; repetir schema.sql es seguro en instalaciones existentes.
CREATE TABLE IF NOT EXISTS cp_codigos_migrados (
 usuario_id BIGINT UNSIGNED PRIMARY KEY,
 FOREIGN KEY (usuario_id) REFERENCES cp_usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Partidos importados del Coordinador anterior; evita duplicarlos al repetir importar-partidos.php.
CREATE TABLE IF NOT EXISTS cp_partidos_importados (
 espacio_id BIGINT UNSIGNED NOT NULL, origen_ref VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 partido_id BIGINT UNSIGNED NOT NULL UNIQUE,
 PRIMARY KEY (espacio_id,origen_ref),
 FOREIGN KEY (espacio_id) REFERENCES cp_espacios(id),
 FOREIGN KEY (partido_id) REFERENCES cp_partidos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Interfaz 18: receptor explícito del pago; conserva intacto el libro histórico.
CREATE TABLE IF NOT EXISTS cp_movimiento_detalle (
 movimiento_id BIGINT UNSIGNED PRIMARY KEY,
 recibio VARCHAR(120) NOT NULL,
 FOREIGN KEY (movimiento_id) REFERENCES cp_movimientos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
