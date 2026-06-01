-- ============================================================
-- ARENA CURY — estrutura do banco (MySQL / MariaDB)
-- Base: corujao
-- Rode isto uma vez para criar as tabelas.
-- ============================================================
SET NAMES utf8mb4;
SET time_zone = '-03:00';

-- Equipes (a hierarquia: diretoria > superintendência > gerência)
CREATE TABLE IF NOT EXISTS equipes (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  diretoria     VARCHAR(120) NOT NULL,
  superintendencia VARCHAR(120) NOT NULL,
  gerencia      VARCHAR(120) NOT NULL,
  online        TINYINT(1) NOT NULL DEFAULT 0,
  criado_em     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_equipe (diretoria, superintendencia, gerencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Corretores (pertencem a uma equipe)
CREATE TABLE IF NOT EXISTS corretores (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  equipe_id  INT NOT NULL,
  nome       VARCHAR(120) NOT NULL,
  criado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (equipe_id) REFERENCES equipes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Pontos / pendências (cada marcação de visita ou documentação)
-- status: pendente | aprovado | rejeitado
CREATE TABLE IF NOT EXISTS pontos (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  equipe_id    INT NOT NULL,
  corretor_id  INT NULL,
  tipo         ENUM('visita','documentacao') NOT NULL,
  valor        INT NOT NULL,                       -- 1 para visita, 3 para doc (vem das regras)
  status       ENUM('pendente','aprovado','rejeitado') NOT NULL DEFAULT 'pendente',
  motivo_rejeicao VARCHAR(255) NULL,
  foto         MEDIUMTEXT NULL,                     -- comprovante em base64
  duelo_id     INT NULL,                            -- se foi marcado durante um duelo
  criado_em    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  decidido_em  TIMESTAMP NULL,
  FOREIGN KEY (equipe_id) REFERENCES equipes(id) ON DELETE CASCADE,
  FOREIGN KEY (corretor_id) REFERENCES corretores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Duelos (batalhas)
-- nivel: gerencia | corretor   | regra: meta | tempo  | status: aguardando | ativo | encerrado | recusado | expirado
CREATE TABLE IF NOT EXISTS duelos (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  nivel        ENUM('gerencia','corretor') NOT NULL,
  regra        ENUM('meta','tempo') NOT NULL,
  regra_valor  INT NOT NULL,                        -- nº de visitas (meta) ou minutos (tempo)
  status       ENUM('aguardando','ativo','encerrado','recusado','expirado') NOT NULL DEFAULT 'aguardando',
  vencedor_equipe_id INT NULL,
  criado_em    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  iniciado_em  TIMESTAMP NULL,
  encerrado_em TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Participantes de cada duelo (2 a 5)
CREATE TABLE IF NOT EXISTS duelo_participantes (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  duelo_id    INT NOT NULL,
  equipe_id   INT NOT NULL,
  corretor_id INT NULL,                              -- preenchido se nivel = corretor
  cor         VARCHAR(20) NULL,                      -- cor sorteada do astronauta
  pontos      INT NOT NULL DEFAULT 0,                -- placar DENTRO do duelo (zera ao iniciar)
  ordem       INT NOT NULL DEFAULT 0,                -- 1=desafiante, 2=desafiado, 3..5=entraram depois
  FOREIGN KEY (duelo_id) REFERENCES duelos(id) ON DELETE CASCADE,
  FOREIGN KEY (equipe_id) REFERENCES equipes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reservas do salão
CREATE TABLE IF NOT EXISTS reservas (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  dia         DATE NOT NULL,
  horario     TIME NOT NULL,
  nivel       ENUM('dir','sup','ger') NOT NULL,
  nome        VARCHAR(160) NOT NULL,
  participantes INT NOT NULL DEFAULT 0,              -- diretoria = 100 (salão todo)
  buffet      TINYINT(1) NOT NULL DEFAULT 0,
  buffet_hora TIME NULL,
  criado_em   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Configurações da sessão (pontuação, duração da rodada, etc) — uma linha só
CREATE TABLE IF NOT EXISTS config (
  id            INT PRIMARY KEY DEFAULT 1,
  pts_visita    INT NOT NULL DEFAULT 1,
  pts_documentacao INT NOT NULL DEFAULT 3,
  rodada_min    INT NOT NULL DEFAULT 120,            -- 0 = sem limite
  rodada_status ENUM('parada','ativa','pausada','encerrada') NOT NULL DEFAULT 'parada',
  rodada_inicio TIMESTAMP NULL,
  CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO config (id) VALUES (1);

-- Eventos para a TV (fila de coisas a animar: ponto aprovado, desafio, etc)
-- A TV lê os eventos novos e os anima. tipo: visita | documentacao | desafio | aceite | recusa | expira | entra_duelo | nocaute
CREATE TABLE IF NOT EXISTS eventos (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  tipo       VARCHAR(40) NOT NULL,
  payload    JSON NULL,                              -- dados do evento (nomes, cores, placar...)
  consumido  TINYINT(1) NOT NULL DEFAULT 0,
  criado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
