-- v10.39: estructura de usuarios del piloto PH4 (obra H57): Profesionales de Terreno y sus Capataces.
-- Cuentas FICTICIAS (correo @ph4-ficticio.invalid: el sistema nunca les envia correo) con una clave
-- aleatoria que nadie conoce. Se usan con 'Entrar como' del panel Desarrollador. Al tener los correos
-- reales, se cambian desde ese mismo panel (Usuarios -> editar correo) y se les fija clave con 'Claves'.
-- Requiere haber corrido antes migracion_v10_39_frente.sql. Es repetible: si se corre otra vez solo
-- actualiza nombre, frente y jefe, sin duplicar.
-- Uso (Web Shell de Render, base de H57):  mysql -h127.0.0.1 -uroot icafal_rrhh < /app/database/seed_equipo_ph4.sql
SET NAMES utf8mb4;

-- Profesional de Terreno 1
INSERT INTO usuarios (nombre, correo, password, rol, debe_cambiar_password) VALUES ('Olguin Olivares Andres Nicolas', 'pt1@ph4-ficticio.invalid', '$2y$10$OezjjtT3ENqGdistXJjG5OdSzbl/3D51Nv2UTmAT5mtxXXYPg3Rg2', 'Jefe_Terreno', 1)
  ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), rol = 'Jefe_Terreno', activo = 1;
SET @pt1 = (SELECT id FROM usuarios WHERE correo = 'pt1@ph4-ficticio.invalid');
INSERT INTO usuarios (nombre, correo, password, rol, jefe_terreno_id, frente, debe_cambiar_password) VALUES
  ('Villaon Perez Hernan Humberto', 'pt1-c01@ph4-ficticio.invalid', '$2y$10$OezjjtT3ENqGdistXJjG5OdSzbl/3D51Nv2UTmAT5mtxXXYPg3Rg2', 'Capataz', @pt1, NULL, 1),
  ('Nuñez Aros Cristian Horacio', 'pt1-c02@ph4-ficticio.invalid', '$2y$10$OezjjtT3ENqGdistXJjG5OdSzbl/3D51Nv2UTmAT5mtxXXYPg3Rg2', 'Capataz', @pt1, 'Frente Gasfitería', 1),
  ('Amaya Chacon Pablo Francisco', 'pt1-c03@ph4-ficticio.invalid', '$2y$10$OezjjtT3ENqGdistXJjG5OdSzbl/3D51Nv2UTmAT5mtxXXYPg3Rg2', 'Capataz', @pt1, 'Frente 1-A', 1),
  ('Lara Meneses Simon Wladimir', 'pt1-c04@ph4-ficticio.invalid', '$2y$10$OezjjtT3ENqGdistXJjG5OdSzbl/3D51Nv2UTmAT5mtxXXYPg3Rg2', 'Capataz', @pt1, 'Frente 1-B', 1),
  ('Pantich Salgado Miguel Angel', 'pt1-c05@ph4-ficticio.invalid', '$2y$10$OezjjtT3ENqGdistXJjG5OdSzbl/3D51Nv2UTmAT5mtxXXYPg3Rg2', 'Capataz', @pt1, 'Frente 1-A', 1),
  ('Sanchez Cordero Abelardo Antonio', 'pt1-c06@ph4-ficticio.invalid', '$2y$10$OezjjtT3ENqGdistXJjG5OdSzbl/3D51Nv2UTmAT5mtxXXYPg3Rg2', 'Capataz', @pt1, 'Frente 1-B', 1),
  ('Romero Macalupu Hernan Adalberto', 'pt1-c07@ph4-ficticio.invalid', '$2y$10$OezjjtT3ENqGdistXJjG5OdSzbl/3D51Nv2UTmAT5mtxXXYPg3Rg2', 'Capataz', @pt1, 'Frente Logística y Patio', 1),
  ('Silva Sepulveda Nilson Ariel', 'pt1-c08@ph4-ficticio.invalid', '$2y$10$OezjjtT3ENqGdistXJjG5OdSzbl/3D51Nv2UTmAT5mtxXXYPg3Rg2', 'Capataz', @pt1, 'Frente 1-A', 1),
  ('Luis Humberto Moraga Contreras', 'pt1-c09@ph4-ficticio.invalid', '$2y$10$OezjjtT3ENqGdistXJjG5OdSzbl/3D51Nv2UTmAT5mtxXXYPg3Rg2', 'Capataz', @pt1, 'Frente 1-B', 1),
  ('Eguiluz Luengo Roberto Ernesto', 'pt1-c10@ph4-ficticio.invalid', '$2y$10$OezjjtT3ENqGdistXJjG5OdSzbl/3D51Nv2UTmAT5mtxXXYPg3Rg2', 'Capataz', @pt1, 'Frente Encargado patio', 1),
  ('Melillan Valenzuela Jose Patricio', 'pt1-c11@ph4-ficticio.invalid', '$2y$10$OezjjtT3ENqGdistXJjG5OdSzbl/3D51Nv2UTmAT5mtxXXYPg3Rg2', 'Capataz', @pt1, 'Frente B-3 y B-4', 1),
  ('Garcia Garcia Jorge Luis', 'pt1-c12@ph4-ficticio.invalid', '$2y$10$OezjjtT3ENqGdistXJjG5OdSzbl/3D51Nv2UTmAT5mtxXXYPg3Rg2', 'Capataz', @pt1, 'Frente B-1 y B-2', 1),
  ('Ramos Alarcon Julio Adrian', 'pt1-c13@ph4-ficticio.invalid', '$2y$10$OezjjtT3ENqGdistXJjG5OdSzbl/3D51Nv2UTmAT5mtxXXYPg3Rg2', 'Capataz', @pt1, 'Frente Hormigón', 1)
  ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), rol = 'Capataz', activo = 1,
    jefe_terreno_id = VALUES(jefe_terreno_id), frente = VALUES(frente);

-- Profesional de Terreno 2
INSERT INTO usuarios (nombre, correo, password, rol, debe_cambiar_password) VALUES ('Cerda Jimenez Valentino Rodolfo', 'pt2@ph4-ficticio.invalid', '$2y$10$OezjjtT3ENqGdistXJjG5OdSzbl/3D51Nv2UTmAT5mtxXXYPg3Rg2', 'Jefe_Terreno', 1)
  ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), rol = 'Jefe_Terreno', activo = 1;
SET @pt2 = (SELECT id FROM usuarios WHERE correo = 'pt2@ph4-ficticio.invalid');
INSERT INTO usuarios (nombre, correo, password, rol, jefe_terreno_id, frente, debe_cambiar_password) VALUES
  ('Molina Rodriguez Victor Hugo', 'pt2-c01@ph4-ficticio.invalid', '$2y$10$OezjjtT3ENqGdistXJjG5OdSzbl/3D51Nv2UTmAT5mtxXXYPg3Rg2', 'Capataz', @pt2, NULL, 1),
  ('Nahuelpi Santander Pedro Pascual', 'pt2-c02@ph4-ficticio.invalid', '$2y$10$OezjjtT3ENqGdistXJjG5OdSzbl/3D51Nv2UTmAT5mtxXXYPg3Rg2', 'Capataz', @pt2, 'Frente 2-A', 1),
  ('Galvez Larrañaga Sebastian Alejandro', 'pt2-c03@ph4-ficticio.invalid', '$2y$10$OezjjtT3ENqGdistXJjG5OdSzbl/3D51Nv2UTmAT5mtxXXYPg3Rg2', 'Capataz', @pt2, 'Frente Lote B-1 y B-2', 1),
  ('Cevas Salinas Samuel Jesus', 'pt2-c04@ph4-ficticio.invalid', '$2y$10$OezjjtT3ENqGdistXJjG5OdSzbl/3D51Nv2UTmAT5mtxXXYPg3Rg2', 'Capataz', @pt2, 'Frente 2-A y 2-B', 1),
  ('Mostacero Cruz Randolph Jordan', 'pt2-c05@ph4-ficticio.invalid', '$2y$10$OezjjtT3ENqGdistXJjG5OdSzbl/3D51Nv2UTmAT5mtxXXYPg3Rg2', 'Capataz', @pt2, 'Frente 2-B', 1),
  ('Cepeda Abarca Ricardo Antonio', 'pt2-c06@ph4-ficticio.invalid', '$2y$10$OezjjtT3ENqGdistXJjG5OdSzbl/3D51Nv2UTmAT5mtxXXYPg3Rg2', 'Capataz', @pt2, 'Frente 2-A', 1),
  ('Vargas Calisto Felipe Andres', 'pt2-c07@ph4-ficticio.invalid', '$2y$10$OezjjtT3ENqGdistXJjG5OdSzbl/3D51Nv2UTmAT5mtxXXYPg3Rg2', 'Capataz', @pt2, 'Frente 2-A', 1),
  ('Garrido Tobar Jose Alfredo', 'pt2-c08@ph4-ficticio.invalid', '$2y$10$OezjjtT3ENqGdistXJjG5OdSzbl/3D51Nv2UTmAT5mtxXXYPg3Rg2', 'Capataz', @pt2, 'Frente 2-B', 1),
  ('Rivera Guerrero Victor Alejandro', 'pt2-c09@ph4-ficticio.invalid', '$2y$10$OezjjtT3ENqGdistXJjG5OdSzbl/3D51Nv2UTmAT5mtxXXYPg3Rg2', 'Capataz', @pt2, 'Frente 2-B', 1)
  ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), rol = 'Capataz', activo = 1,
    jefe_terreno_id = VALUES(jefe_terreno_id), frente = VALUES(frente);
