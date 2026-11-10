-- =====================================================================
-- Trabalho 1 - API REST PetCare (PHP puro + MySQL)
-- Arquivo: banco.sql
--
-- O que este script faz:
--   1. Apaga o banco petcare se ele existir
--   2. Cria o banco e as 4 tabelas (tutores, pets, usuarios, tokens)
--   3. Insere os dados de exemplo (5 tutores, 11 pets, 1 usuario de teste)
--
-- Como rodar:
--   phpMyAdmin > aba Importar > escolher este arquivo > Executar
--   ou no terminal: mysql -u root < banco.sql
--
-- REGRA DE EXCLUSAO ADOTADA: ON DELETE CASCADE
--   Ao excluir um tutor, todos os pets dele sao apagados na mesma operacao.
--   Justificativa vai no README (arquivo 5).
--
-- ATENCAO: falta UM passo manual antes de commitar.
--   No INSERT de usuarios existe o marcador __HASH_DA_SENHA__.
--   Ele precisa ser trocado pelo hash real. Instrucoes no fim do arquivo.
-- =====================================================================

DROP DATABASE IF EXISTS petcare;

CREATE DATABASE petcare
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE petcare;

-- ---------------------------------------------------------------------
-- tutores: os donos dos pets
-- ---------------------------------------------------------------------
CREATE TABLE tutores (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome      VARCHAR(100) NOT NULL,
  telefone  VARCHAR(20)  NOT NULL,
  email     VARCHAR(120) NOT NULL,
  criado_em TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tutores_email (email)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- pets: cada pet pertence a um tutor
-- ON DELETE CASCADE: apagar o tutor apaga os pets dele
-- ---------------------------------------------------------------------
CREATE TABLE pets (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  nome            VARCHAR(60)   NOT NULL,
  especie         ENUM('cachorro','gato','ave','roedor','outro') NOT NULL,
  raca            VARCHAR(60)   NULL,          -- pode ser nula
  data_nascimento DATE          NOT NULL,
  peso_kg         DECIMAL(5,2)  NOT NULL,
  tutor_id        INT UNSIGNED  NOT NULL,
  criado_em       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pets_tutor (tutor_id),
  KEY idx_pets_especie (especie),
  CONSTRAINT fk_pets_tutor
    FOREIGN KEY (tutor_id) REFERENCES tutores (id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT ck_pets_peso CHECK (peso_kg > 0 AND peso_kg <= 200)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- usuarios: quem tem permissao de escrita na API (funcionarios)
-- senha_hash guarda o resultado de password_hash(), nunca a senha pura
-- ---------------------------------------------------------------------
CREATE TABLE usuarios (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome       VARCHAR(100) NOT NULL,
  email      VARCHAR(120) NOT NULL,
  senha_hash VARCHAR(255) NOT NULL,
  criado_em  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_email (email)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- tokens: sessoes ativas. Token aleatorio de 64 caracteres hexadecimais
-- ---------------------------------------------------------------------
CREATE TABLE tokens (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id INT UNSIGNED NOT NULL,
  token      CHAR(64)     NOT NULL,
  expira_em  DATETIME     NOT NULL,
  criado_em  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tokens_token (token),
  KEY idx_tokens_usuario (usuario_id),
  KEY idx_tokens_expira (expira_em),
  CONSTRAINT fk_tokens_usuario
    FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- DADOS DE EXEMPLO
-- ---------------------------------------------------------------------

INSERT INTO tutores (id, nome, telefone, email) VALUES
  (1, 'Ana Paula Souza',      '(11) 98888-1010', 'ana.souza@email.com'),
  (2, 'Bruno Carvalho Lima',  '(11) 97777-2020', 'bruno.lima@email.com'),
  (3, 'Carla Mendes Ferreira','(21) 96666-3030', 'carla.mendes@email.com'),
  (4, 'Diego Alves Rocha',    '(31) 95555-4040', 'diego.rocha@email.com'),
  (5, 'Elisa Nunes Barbosa',  '(41) 94444-5050', 'elisa.barbosa@email.com');

-- 11 pets. Ana (tutor 1) tem 3 pets e Bruno (tutor 2) tem 2,
-- cobrindo o requisito de "algum tutor com mais de um pet".
INSERT INTO pets (nome, especie, raca, data_nascimento, peso_kg, tutor_id) VALUES
  ('Thor',    'cachorro', 'Golden Retriever', '2020-03-14', 32.50, 1),
  ('Mel',     'gato',     'Siames',           '2021-07-02',  4.20, 1),
  ('Toby',    'cachorro', 'SRD',              '2016-06-15', 22.00, 1),
  ('Rex',     'cachorro', 'Pastor Alemao',   '2018-01-09', 38.00, 2),
  ('Luna',    'gato',     'Persa',            '2023-05-30',  3.80, 2),
  ('Bob',     'cachorro', 'Poodle',           '2019-09-12',  6.40, 3),
  ('Kiara',   'gato',     'SRD',              '2020-12-05',  4.00, 3),
  ('Simba',   'gato',     'Maine Coon',       '2021-02-18',  7.10, 4),
  ('Perola',  'roedor',   'Hamster Sirio',    '2024-01-25',  0.20, 4),
  ('Pipoca',  'outro',    'Coelho Anao',      '2022-08-08',  1.50, 5),
  ('Nina',    'ave',      'Calopsita',        '2022-11-20',  0.10, 5);

-- Usuario de teste (usado no POST /login e documentado no README)
-- e-mail: admin@petcare.com
-- senha:  petcare123
INSERT INTO usuarios (nome, email, senha_hash) VALUES
  ('Recepcao PetCare', 'admin@petcare.com', '$2y$10$QGAsPnHyzS6MD5IgtYdOq.Lddf3E/O8jcnC1LCwdXOGQvW6UjMF4a');

-- Conferencia rapida (opcional, execute manualmente se quiser):
-- SELECT COUNT(*) FROM tutores;  -- esperado: 5
-- SELECT COUNT(*) FROM pets;     -- esperado: 11
-- SELECT t.nome, COUNT(p.id) FROM tutores t
--   LEFT JOIN pets p ON p.tutor_id = t.id GROUP BY t.id;

-- =====================================================================
-- PASSO MANUAL OBRIGATORIO (so uma vez, antes de commitar)
--
-- 1. Abra o terminal na pasta do PHP do XAMPP:
--      C:\xampp\php\
-- 2. Rode este comando:
--      php -r "echo password_hash('petcare123', PASSWORD_DEFAULT), PHP_EOL;"
-- 3. Copie a linha inteira que aparecer, algo como
--      $2y$10$AbCdEf...   (60 caracteres)
-- 4. Cole no lugar de __HASH_DA_SENHA__ no INSERT acima, mantendo as aspas.
-- 5. Rode o script e teste o login.
--
-- O hash muda a cada execucao do comando (o salt e aleatorio), mas todos
-- os gerados para 'petcare123' validam com password_verify. Este arquivo
-- NAO e entregavel enquanto o marcador __HASH_DA_SENHA__ estiver no lugar.
-- =====================================================================