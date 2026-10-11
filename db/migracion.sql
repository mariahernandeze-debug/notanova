-- =============================================================================
-- NotaNova - Migración de base de datos
-- =============================================================================
-- Script idempotente y SIN DROP: puede ejecutarse cuantas veces se quiera.
--  * Crea la base de datos y las tablas solo si no existen.
--  * Agrega columnas y claves foráneas solo si faltan.
--  * Los datos de demo se insertan solo cuando corresponde, por lo que NUNCA
--    toca ni duplica datos que ya existen.
--
-- Ejecutar con:  mysql -u root < db/migracion.sql
-- =============================================================================

CREATE DATABASE IF NOT EXISTS notanova
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_general_ci;

USE notanova;

-- =============================================================================
-- 1. TABLAS (en orden de dependencia)
-- =============================================================================

CREATE TABLE IF NOT EXISTS docentes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo_documento VARCHAR(10) DEFAULT 'CC',
    documento_id VARCHAR(50) NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    telefono VARCHAR(50),
    especialidad VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_docentes_documento (documento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS estudiantes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo_documento VARCHAR(10) DEFAULT 'TI',
    documento_id VARCHAR(50) NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    fecha_nacimiento DATE,
    telefono VARCHAR(50),
    direccion VARCHAR(255),
    acudiente_nombre VARCHAR(255),
    acudiente_telefono VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_estudiantes_documento (documento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS cursos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo_curso VARCHAR(50) NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    docente_id INT,
    modalidad VARCHAR(20) NOT NULL DEFAULT 'cortes',
    escala VARCHAR(10) NOT NULL DEFAULT '5.0',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cursos_codigo (codigo_curso),
    CONSTRAINT fk_cursos_docente
        FOREIGN KEY (docente_id) REFERENCES docentes (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS actividades (
    actividad_id INT AUTO_INCREMENT PRIMARY KEY,
    curso_id INT NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    descripcion TEXT,
    corte VARCHAR(50) NOT NULL,
    porcentaje DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    CONSTRAINT fk_actividades_curso
        FOREIGN KEY (curso_id) REFERENCES cursos (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS notas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    estudiante_id INT NOT NULL,
    curso_id INT NOT NULL,
    actividad_id INT NOT NULL,
    actividad VARCHAR(255),
    corte VARCHAR(50),
    porcentaje DECIMAL(5,2),
    nota DECIMAL(4,2) NOT NULL,
    UNIQUE KEY uq_est_act (estudiante_id, actividad_id),
    CONSTRAINT fk_notas_estudiante
        FOREIGN KEY (estudiante_id) REFERENCES estudiantes (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_notas_curso
        FOREIGN KEY (curso_id) REFERENCES cursos (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_notas_actividad
        FOREIGN KEY (actividad_id) REFERENCES actividades (actividad_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS config_evaluacion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    curso_id INT NOT NULL,
    configuracion TEXT NOT NULL,
    UNIQUE KEY uq_config_curso (curso_id),
    CONSTRAINT fk_config_curso
        FOREIGN KEY (curso_id) REFERENCES cursos (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS curso_estudiante (
    id INT AUTO_INCREMENT PRIMARY KEY,
    curso_id INT NOT NULL,
    estudiante_id INT NOT NULL,
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_curso_est (curso_id, estudiante_id),
    CONSTRAINT fk_ce_curso
        FOREIGN KEY (curso_id) REFERENCES cursos (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ce_estudiante
        FOREIGN KEY (estudiante_id) REFERENCES estudiantes (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Cuentas de acceso. 'usuario' guarda la cédula (docentes/estudiantes) o un
-- nombre fijo para el administrador. Las contraseñas se guardan con bcrypt.
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    rol ENUM('admin','docente','estudiante') NOT NULL DEFAULT 'estudiante',
    estudiante_id INT,
    docente_id INT,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_usuarios_usuario (usuario),
    CONSTRAINT fk_usuarios_estudiante
        FOREIGN KEY (estudiante_id) REFERENCES estudiantes (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_usuarios_docente
        FOREIGN KEY (docente_id) REFERENCES docentes (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================================================
-- 2. COLUMNAS DE REFERENCIA (solo se agregan si faltan en una BD previa)
-- =============================================================================

-- Las vistas de este proyecto asumen 'modalidad' y 'escala' en cursos.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = 'notanova' AND TABLE_NAME = 'cursos' AND COLUMN_NAME = 'modalidad');
SET @sql := IF(@col = 0,
    'ALTER TABLE cursos ADD COLUMN modalidad VARCHAR(20) NOT NULL DEFAULT ''cortes'' AFTER docente_id',
    'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = 'notanova' AND TABLE_NAME = 'cursos' AND COLUMN_NAME = 'escala');
SET @sql := IF(@col = 0,
    'ALTER TABLE cursos ADD COLUMN escala VARCHAR(10) NOT NULL DEFAULT ''5.0'' AFTER modalidad',
    'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- =============================================================================
-- 3. DATOS BASE DE DEMOSTRACIÓN
--    Cada bloque se ejecuta solo si la tabla de destino está vacía, por lo que
--    en una BD con datos reales no se inserta nada de esta sección.
-- =============================================================================

-- 3.1 Docentes
INSERT INTO docentes (tipo_documento, documento_id, nombre, email, telefono, especialidad)
SELECT 'CC', '1098765432', 'CARLOS MENDOZA', 'carlos.mendoza@notanova.edu', '3001234567', 'Matemáticas y Cálculo'
WHERE NOT EXISTS (SELECT 1 FROM docentes);

INSERT INTO docentes (tipo_documento, documento_id, nombre, email, telefono, especialidad)
SELECT 'CC', '1098765433', 'DIANA MARTINEZ', 'diana.martinez@notanova.edu', '3007654321', 'Física Cuántica'
WHERE NOT EXISTS (SELECT 1 FROM docentes);

-- 3.2 Estudiantes
INSERT INTO estudiantes (tipo_documento, documento_id, nombre, email, fecha_nacimiento, telefono, direccion, acudiente_nombre, acudiente_telefono)
SELECT 'CC', '1001234567', 'ANA GOMEZ PEREZ', 'ana.gomez@notanova.edu', '2005-03-15', '3111111111', 'Calle 10 # 20-30', 'PEDRO GOMEZ', '3111111112'
WHERE NOT EXISTS (SELECT 1 FROM estudiantes);

INSERT INTO estudiantes (tipo_documento, documento_id, nombre, email, fecha_nacimiento, telefono, direccion, acudiente_nombre, acudiente_telefono)
SELECT 'CC', '1007654321', 'JUAN PABLO RODRIGUEZ', 'juan.rodriguez@notanova.edu', '2004-11-02', '3222222222', 'Carrera 5 # 15-40', 'MARIA RODRIGUEZ', '3222222223'
WHERE NOT EXISTS (SELECT 1 FROM estudiantes);

INSERT INTO estudiantes (tipo_documento, documento_id, nombre, email, fecha_nacimiento, telefono, direccion, acudiente_nombre, acudiente_telefono)
SELECT 'TI', '165653254', 'SOFIA CASTRO', 'sofia.castro@notanova.edu', '2007-06-20', '3333333333', 'Avenida 20 # 30-50', 'LUIS CASTRO', '3333333334'
WHERE NOT EXISTS (SELECT 1 FROM estudiantes);

-- 3.3 Cursos (solo se crean si la tabla está vacía)
INSERT INTO cursos (codigo_curso, nombre, docente_id, modalidad, escala)
SELECT 'MAT-101', 'CÁLCULO MULTIVARIABLE', d.id, 'cortes', '5.0'
FROM docentes d WHERE d.documento_id = '1098765432'
  AND NOT EXISTS (SELECT 1 FROM cursos);

INSERT INTO cursos (codigo_curso, nombre, docente_id, modalidad, escala)
SELECT 'FIS-201', 'FÍSICA MECÁNICA', d.id, 'cortes', '5.0'
FROM docentes d WHERE d.documento_id = '1098765432'
  AND NOT EXISTS (SELECT 1 FROM cursos);

INSERT INTO cursos (codigo_curso, nombre, docente_id, modalidad, escala)
SELECT 'BD-301', 'BASE DE DATOS', d.id, 'cortes', '5.0'
FROM docentes d WHERE d.documento_id = '1098765433'
  AND NOT EXISTS (SELECT 1 FROM cursos);

-- 3.4 Matrículas (pivote curso <-> estudiante)
INSERT INTO curso_estudiante (curso_id, estudiante_id)
SELECT c.id, e.id FROM cursos c
JOIN estudiantes e ON e.documento_id = '1001234567'
WHERE c.codigo_curso = 'MAT-101'
  AND NOT EXISTS (SELECT 1 FROM curso_estudiante ce WHERE ce.curso_id = c.id AND ce.estudiante_id = e.id);

INSERT INTO curso_estudiante (curso_id, estudiante_id)
SELECT c.id, e.id FROM cursos c
JOIN estudiantes e ON e.documento_id = '1007654321'
WHERE c.codigo_curso = 'MAT-101'
  AND NOT EXISTS (SELECT 1 FROM curso_estudiante ce WHERE ce.curso_id = c.id AND ce.estudiante_id = e.id);

INSERT INTO curso_estudiante (curso_id, estudiante_id)
SELECT c.id, e.id FROM cursos c
JOIN estudiantes e ON e.documento_id = '1001234567'
WHERE c.codigo_curso = 'FIS-201'
  AND NOT EXISTS (SELECT 1 FROM curso_estudiante ce WHERE ce.curso_id = c.id AND ce.estudiante_id = e.id);

INSERT INTO curso_estudiante (curso_id, estudiante_id)
SELECT c.id, e.id FROM cursos c
JOIN estudiantes e ON e.documento_id = '1007654321'
WHERE c.codigo_curso = 'BD-301'
  AND NOT EXISTS (SELECT 1 FROM curso_estudiante ce WHERE ce.curso_id = c.id AND ce.estudiante_id = e.id);

-- 3.5 Cuentas de acceso
-- Contraseña de TODAS las cuentas demo: 123456 (hash bcrypt, nunca texto plano).
-- El "usuario" es la cédula para docentes/estudiantes, o 'admin' para el administrador.
SET @hash_demo = '$2y$10$PAL1/l0s3VkSlryxIeSt..RV4Semi7tb68UO8ghC5.YSZ9CTiHPdq';

INSERT INTO usuarios (usuario, password_hash, rol, estudiante_id, docente_id, activo)
SELECT 'admin', @hash_demo, 'admin', NULL, NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM usuarios WHERE usuario = 'admin');

INSERT INTO usuarios (usuario, password_hash, rol, estudiante_id, docente_id, activo)
SELECT d.documento_id, @hash_demo, 'docente', NULL, d.id, 1
FROM docentes d
WHERE d.documento_id = '1098765432'
  AND NOT EXISTS (SELECT 1 FROM usuarios u WHERE u.usuario = d.documento_id);

INSERT INTO usuarios (usuario, password_hash, rol, estudiante_id, docente_id, activo)
SELECT d.documento_id, @hash_demo, 'docente', NULL, d.id, 1
FROM docentes d
WHERE d.documento_id = '1098765433'
  AND NOT EXISTS (SELECT 1 FROM usuarios u WHERE u.usuario = d.documento_id);

INSERT INTO usuarios (usuario, password_hash, rol, estudiante_id, docente_id, activo)
SELECT e.documento_id, @hash_demo, 'estudiante', e.id, NULL, 1
FROM estudiantes e
WHERE e.documento_id = '1001234567'
  AND NOT EXISTS (SELECT 1 FROM usuarios u WHERE u.usuario = e.documento_id);

INSERT INTO usuarios (usuario, password_hash, rol, estudiante_id, docente_id, activo)
SELECT e.documento_id, @hash_demo, 'estudiante', e.id, NULL, 1
FROM estudiantes e
WHERE e.documento_id = '1007654321'
  AND NOT EXISTS (SELECT 1 FROM usuarios u WHERE u.usuario = e.documento_id);

INSERT INTO usuarios (usuario, password_hash, rol, estudiante_id, docente_id, activo)
SELECT e.documento_id, @hash_demo, 'estudiante', e.id, NULL, 1
FROM estudiantes e
WHERE e.documento_id = '165653254'
  AND NOT EXISTS (SELECT 1 FROM usuarios u WHERE u.usuario = e.documento_id);

-- =============================================================================
-- 4. COMPLETADO GENÉRICO DE CURSOS
--    Para cada curso que NO tenga actividades, se le crea el set estándar de
--    evaluación (3 cortes) y se registran notas de demostración para los
--    estudiantes matriculados. Idempotente por diseño.
-- =============================================================================

DROP PROCEDURE IF EXISTS sp_completar_cursos_demo;

DELIMITER $$
CREATE PROCEDURE sp_completar_cursos_demo()
BEGIN
    DECLARE hecho INT DEFAULT 0;
    DECLARE v_curso_id INT;
    DECLARE v_peso DECIMAL(5,2);

    -- Cursor: cursos que aún no tienen ninguna actividad
    DECLARE cur_cursos CURSOR FOR
        SELECT c.id FROM cursos c
        WHERE NOT EXISTS (SELECT 1 FROM actividades a WHERE a.curso_id = c.id);
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET hecho = 1;

    OPEN cur_cursos;

    ciclo_cursos: LOOP
        FETCH cur_cursos INTO v_curso_id;
        IF hecho THEN LEAVE ciclo_cursos; END IF;

        -- --- Actividades estándar: 2 en Corte 1, 2 en Corte 2, 1 en Corte 3 ---
        INSERT INTO actividades (curso_id, nombre, descripcion, corte, porcentaje)
        SELECT v_curso_id, 'Parcial 1', 'Parcial 1', 'Corte 1', 50.00
        WHERE NOT EXISTS (SELECT 1 FROM actividades WHERE curso_id = v_curso_id AND nombre = 'Parcial 1' AND corte = 'Corte 1');

        INSERT INTO actividades (curso_id, nombre, descripcion, corte, porcentaje)
        SELECT v_curso_id, 'Talleres', 'Talleres', 'Corte 1', 50.00
        WHERE NOT EXISTS (SELECT 1 FROM actividades WHERE curso_id = v_curso_id AND nombre = 'Talleres' AND corte = 'Corte 1');

        INSERT INTO actividades (curso_id, nombre, descripcion, corte, porcentaje)
        SELECT v_curso_id, 'Parcial 2', 'Parcial 2', 'Corte 2', 60.00
        WHERE NOT EXISTS (SELECT 1 FROM actividades WHERE curso_id = v_curso_id AND nombre = 'Parcial 2' AND corte = 'Corte 2');

        INSERT INTO actividades (curso_id, nombre, descripcion, corte, porcentaje)
        SELECT v_curso_id, 'Talleres', 'Talleres', 'Corte 2', 40.00
        WHERE NOT EXISTS (SELECT 1 FROM actividades WHERE curso_id = v_curso_id AND nombre = 'Talleres' AND corte = 'Corte 2');

        INSERT INTO actividades (curso_id, nombre, descripcion, corte, porcentaje)
        SELECT v_curso_id, 'Examen Final', 'Examen Final', 'Corte 3', 100.00
        WHERE NOT EXISTS (SELECT 1 FROM actividades WHERE curso_id = v_curso_id AND nombre = 'Examen Final' AND corte = 'Corte 3');

        -- --- Notas de demostración para los estudiantes matriculados ---
        -- Se usa un valor distinto por actividad para que se vea real.
        INSERT INTO notas (estudiante_id, curso_id, actividad_id, actividad, corte, porcentaje, nota)
        SELECT ce.estudiante_id, v_curso_id, a.actividad_id, a.nombre, a.corte, a.porcentaje, 4.20
        FROM curso_estudiante ce
        JOIN actividades a ON a.curso_id = v_curso_id AND a.nombre = 'Parcial 1' AND a.corte = 'Corte 1'
        WHERE ce.curso_id = v_curso_id
          AND NOT EXISTS (SELECT 1 FROM notas n WHERE n.estudiante_id = ce.estudiante_id AND n.actividad_id = a.actividad_id);

        INSERT INTO notas (estudiante_id, curso_id, actividad_id, actividad, corte, porcentaje, nota)
        SELECT ce.estudiante_id, v_curso_id, a.actividad_id, a.nombre, a.corte, a.porcentaje, 4.80
        FROM curso_estudiante ce
        JOIN actividades a ON a.curso_id = v_curso_id AND a.nombre = 'Talleres' AND a.corte = 'Corte 1'
        WHERE ce.curso_id = v_curso_id
          AND NOT EXISTS (SELECT 1 FROM notas n WHERE n.estudiante_id = ce.estudiante_id AND n.actividad_id = a.actividad_id);

        INSERT INTO notas (estudiante_id, curso_id, actividad_id, actividad, corte, porcentaje, nota)
        SELECT ce.estudiante_id, v_curso_id, a.actividad_id, a.nombre, a.corte, a.porcentaje, 3.90
        FROM curso_estudiante ce
        JOIN actividades a ON a.curso_id = v_curso_id AND a.nombre = 'Parcial 2' AND a.corte = 'Corte 2'
        WHERE ce.curso_id = v_curso_id
          AND NOT EXISTS (SELECT 1 FROM notas n WHERE n.estudiante_id = ce.estudiante_id AND n.actividad_id = a.actividad_id);

        INSERT INTO notas (estudiante_id, curso_id, actividad_id, actividad, corte, porcentaje, nota)
        SELECT ce.estudiante_id, v_curso_id, a.actividad_id, a.nombre, a.corte, a.porcentaje, 4.60
        FROM curso_estudiante ce
        JOIN actividades a ON a.curso_id = v_curso_id AND a.nombre = 'Talleres' AND a.corte = 'Corte 2'
        WHERE ce.curso_id = v_curso_id
          AND NOT EXISTS (SELECT 1 FROM notas n WHERE n.estudiante_id = ce.estudiante_id AND n.actividad_id = a.actividad_id);

        INSERT INTO notas (estudiante_id, curso_id, actividad_id, actividad, corte, porcentaje, nota)
        SELECT ce.estudiante_id, v_curso_id, a.actividad_id, a.nombre, a.corte, a.porcentaje, 4.10
        FROM curso_estudiante ce
        JOIN actividades a ON a.curso_id = v_curso_id AND a.nombre = 'Examen Final' AND a.corte = 'Corte 3'
        WHERE ce.curso_id = v_curso_id
          AND NOT EXISTS (SELECT 1 FROM notas n WHERE n.estudiante_id = ce.estudiante_id AND n.actividad_id = a.actividad_id);

    END LOOP ciclo_cursos;

    CLOSE cur_cursos;
END$$
DELIMITER ;

CALL sp_completar_cursos_demo();
DROP PROCEDURE IF EXISTS sp_completar_cursos_demo;

-- =============================================================================
-- 5. CONFIGURACIÓN DE EVALUACIÓN POR DEFECTO
--    Se agrega solo a los cursos que aún no tengan configuración.
-- =============================================================================

INSERT INTO config_evaluacion (curso_id, configuracion)
SELECT c.id, '{"Corte 1":30,"Corte 2":30,"Corte 3":40}'
FROM cursos c
WHERE NOT EXISTS (SELECT 1 FROM config_evaluacion cfg WHERE cfg.curso_id = c.id);

-- =============================================================================
-- FIN DE LA MIGRACIÓN
-- =============================================================================
