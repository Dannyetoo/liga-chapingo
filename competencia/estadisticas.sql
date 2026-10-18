-- Módulo «Torneo y estadísticas» (estilo Copa Fácil) · MySQL 5.7+/MariaDB
-- Ejecutar DESPUÉS de schema.sql, en la misma base. No borra datos; repetirlo es seguro.
SET NAMES utf8mb4;

-- Cuentas personales de jugadores (sólo consultan el torneo y votan).
ALTER TABLE cp_usuarios MODIFY rol ENUM('administrador','coordinador','equipo','jugador') NOT NULL;

-- Ficha de jugador. origen_ref = id del jugador en Tesorería cuando se importa la plantilla.
CREATE TABLE IF NOT EXISTS cp_jugadores (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, espacio_id BIGINT UNSIGNED NOT NULL,
 equipo_id BIGINT UNSIGNED NOT NULL, nombre VARCHAR(120) NOT NULL,
 numero SMALLINT UNSIGNED NULL,
 posicion ENUM('portero','defensa','medio','delantero') NOT NULL DEFAULT 'medio',
 origen_ref VARCHAR(64) COLLATE utf8mb4_bin NULL,
 usuario_id BIGINT UNSIGNED NULL UNIQUE, activo TINYINT NOT NULL DEFAULT 1,
 UNIQUE KEY uq_jugador_espacio (espacio_id,id), UNIQUE KEY uq_jugador_origen (espacio_id,origen_ref),
 KEY plantilla (espacio_id,equipo_id),
 FOREIGN KEY (espacio_id,equipo_id) REFERENCES cp_equipos(espacio_id,id),
 FOREIGN KEY (usuario_id) REFERENCES cp_usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Jornada, fase y llave de cada partido. Sin fila = fase regular sin jornada.
CREATE TABLE IF NOT EXISTS cp_partido_info (
 partido_id BIGINT UNSIGNED PRIMARY KEY, espacio_id BIGINT UNSIGNED NOT NULL,
 fase ENUM('regular','dieciseisavos','octavos','cuartos','semifinal','tercer_lugar','final') NOT NULL DEFAULT 'regular',
 jornada SMALLINT UNSIGNED NULL, llave TINYINT UNSIGNED NULL,
 penales_local TINYINT UNSIGNED NULL, penales_visita TINYINT UNSIGNED NULL,
 FOREIGN KEY (partido_id) REFERENCES cp_partidos(id), FOREIGN KEY (espacio_id) REFERENCES cp_espacios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cédula arbitral: goles, autogoles y tarjetas.
CREATE TABLE IF NOT EXISTS cp_eventos (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, espacio_id BIGINT UNSIGNED NOT NULL,
 partido_id BIGINT UNSIGNED NOT NULL, jugador_id BIGINT UNSIGNED NOT NULL,
 tipo ENUM('gol','autogol','amarilla','roja') NOT NULL, minuto TINYINT UNSIGNED NULL,
 creado_por BIGINT UNSIGNED NOT NULL, KEY por_partido (partido_id),
 FOREIGN KEY (partido_id) REFERENCES cp_partidos(id),
 FOREIGN KEY (espacio_id,jugador_id) REFERENCES cp_jugadores(espacio_id,id),
 FOREIGN KEY (creado_por) REFERENCES cp_usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Portero que jugó cada partido por equipo (para «mejor portero»).
CREATE TABLE IF NOT EXISTS cp_porteros (
 partido_id BIGINT UNSIGNED NOT NULL, equipo_id BIGINT UNSIGNED NOT NULL,
 espacio_id BIGINT UNSIGNED NOT NULL, jugador_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY (partido_id,equipo_id),
 FOREIGN KEY (partido_id) REFERENCES cp_partidos(id),
 FOREIGN KEY (espacio_id,jugador_id) REFERENCES cp_jugadores(espacio_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sanciones manuales (además de las automáticas por tarjetas).
CREATE TABLE IF NOT EXISTS cp_sanciones (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, espacio_id BIGINT UNSIGNED NOT NULL,
 jugador_id BIGINT UNSIGNED NOT NULL, partidos TINYINT UNSIGNED NOT NULL,
 motivo VARCHAR(200) NOT NULL, desde DATE NOT NULL, creado_por BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY (espacio_id,jugador_id) REFERENCES cp_jugadores(espacio_id,id),
 FOREIGN KEY (creado_por) REFERENCES cp_usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Calificaciones 1–5 entre jugadores (y de jugadores a equipos). Un voto por objetivo; se puede cambiar.
CREATE TABLE IF NOT EXISTS cp_votos (
 espacio_id BIGINT UNSIGNED NOT NULL, votante_id BIGINT UNSIGNED NOT NULL,
 tipo ENUM('jugador','equipo') NOT NULL, objetivo_id BIGINT UNSIGNED NOT NULL,
 puntaje TINYINT UNSIGNED NOT NULL, actualizado TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (votante_id,tipo,objetivo_id), KEY objetivo (espacio_id,tipo,objetivo_id),
 FOREIGN KEY (espacio_id,votante_id) REFERENCES cp_jugadores(espacio_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Documentos adjuntos al perfil (usuario_id) o a todo un espacio (usuario_id NULL).
-- El PDF vive en competencia/documentos/ (cerrada a internet) y sale sólo por api.php?a=documento.
CREATE TABLE IF NOT EXISTS cp_documentos (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, espacio_id BIGINT UNSIGNED NULL,
 usuario_id BIGINT UNSIGNED NULL, titulo VARCHAR(150) NOT NULL, archivo VARCHAR(150) NOT NULL,
 activo TINYINT NOT NULL DEFAULT 1, creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_doc (usuario_id,archivo),
 FOREIGN KEY (espacio_id) REFERENCES cp_espacios(id), FOREIGN KEY (usuario_id) REFERENCES cp_usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Pases de un solo uso del portal de capitanes → competencia.
CREATE TABLE IF NOT EXISTS cp_pases_usados (
 nonce CHAR(32) PRIMARY KEY, vence INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
