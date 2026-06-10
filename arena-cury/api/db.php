<?php
// ============================================================
// db.php — conexão com o MySQL e funções auxiliares
// ============================================================

// Credenciais. Em Docker, o host é o NOME DO SERVIÇO do MySQL no compose
// (ex: "mysql" ou "db"). Ajuste DB_HOST se o seu serviço tiver outro nome.
define('DB_HOST', getenv('DB_HOST') ?: 'mysql');
define('DB_NAME', getenv('DB_NAME') ?: 'corujao');
define('DB_USER', getenv('DB_USER') ?: 'usuario_corujao');
define('DB_PASS', getenv('DB_PASS') ?: 'Juti2401#!#!');

// CORS + JSON (permite as telas acessarem a API)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function db() {
  static $pdo = null;
  if ($pdo === null) {
    try {
      $pdo = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
      );
    } catch (Exception $e) {
      http_response_code(500);
      echo json_encode(['erro' => 'Falha ao conectar no banco', 'detalhe' => $e->getMessage()]);
      exit;
    }
  }
  return $pdo;
}

// lê o corpo JSON de um POST
function body() {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function ok($data = []) { echo json_encode(['ok' => true] + $data); exit; }
function fail($msg, $code = 400) { http_response_code($code); echo json_encode(['ok' => false, 'erro' => $msg]); exit; }

// registra um evento para a TV animar
function emitir($tipo, $payload = []) {
  $st = db()->prepare('INSERT INTO eventos (tipo, payload) VALUES (?, ?)');
  $st->execute([$tipo, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
}

// ------------------------------------------------------------
// Manutenção: apaga a FOTO (base64) de pontos com mais de 24h.
// Mantém o ponto e o placar — só remove a imagem pesada, que só é útil
// no dia do evento (comprovante p/ contestação). Alivia o banco.
// Trava por arquivo: a checagem roda em toda requisição, mas o UPDATE
// executa no máximo 1x a cada 30 min e nunca derruba a requisição.
// ------------------------------------------------------------
function limparFotosAntigas($throttleSeg = 1800) {
  $marker = sys_get_temp_dir().'/arena_foto_cleanup';
  if (is_file($marker) && (time() - @filemtime($marker)) < $throttleSeg) return;
  @touch($marker); // marca antes de rodar p/ evitar corrida entre requisições simultâneas
  try {
    // 1) apaga fotos com +24h (mantém o ponto e o placar)
    db()->query("UPDATE pontos SET foto=NULL
                 WHERE foto IS NOT NULL AND criado_em < (NOW() - INTERVAL 24 HOUR)");
    // 2) poda a fila de eventos antiga (a TV só lê eventos novos) — mantém a tabela enxuta
    db()->query("DELETE FROM eventos WHERE criado_em < (NOW() - INTERVAL 2 DAY)");
  } catch (Exception $e) { /* manutenção é silenciosa: não pode quebrar a API */ }
}

// dispara a manutenção automática em toda requisição (a trava cuida da frequência)
limparFotosAntigas();
