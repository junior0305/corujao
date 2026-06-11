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

// lê o corpo JSON de um POST (com cache — pode ser chamado mais de uma vez por requisição)
function body() {
  static $data = null;
  if ($data === null) {
    $raw = file_get_contents('php://input');
    $d = json_decode($raw, true);
    $data = is_array($d) ? $d : [];
  }
  return $data;
}

// ------------------------------------------------------------
// Código de acesso do dia (anti-"brincadeira" no tablet).
// Fica em config.codigo_acesso. SEGURO POR PADRÃO: se estiver vazio,
// o bloqueio está DESLIGADO e tudo funciona como antes.
// ------------------------------------------------------------
function codigoConfigurado() {
  // só um SELECT rápido (caminho quente). Se a coluna ainda não existe (ninguém
  // definiu código), o catch devolve '' => bloqueio desligado (seguro por padrão).
  // A coluna é criada no acesso.php (chamado pela recepção/tablet).
  try { $r = db()->query("SELECT codigo_acesso FROM config WHERE id=1")->fetch(); }
  catch (Exception $e) { return ''; }
  return $r ? trim((string)$r['codigo_acesso']) : '';
}

// IP real do cliente. Atrás do Traefik, o IP do celular vem no X-Forwarded-For;
// o ÚLTIMO valor é o que o Traefik adicionou (confiável, não falsificável). Sem proxy,
// cai no REMOTE_ADDR. (Por enquanto só para DIAGNÓSTICO — "Ver minha rede".)
function ipCliente() {
  $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
  if ($xff !== '') {
    $partes = array_map('trim', explode(',', $xff));
    $ip = end($partes);
    if ($ip !== '') return $ip;
  }
  return $_SERVER['REMOTE_ADDR'] ?? '';
}

// IPs liberados (lista separada por vírgula). Vazio => bloqueio de rede desligado.
function redeLiberada() {
  try { $r = db()->query("SELECT rede_liberada FROM config WHERE id=1")->fetch(); }
  catch (Exception $e) { return ''; }
  return $r ? trim((string)$r['rede_liberada']) : '';
}

// ------------------------------------------------------------
// PIN por equipe (gerado na recepção, válido só HOJE).
// pinValido: confere se o PIN é o da equipe (se equipe_id vier) e do dia de hoje.
// ------------------------------------------------------------
function pinValido($pin, $equipe_id = null) {
  $pin = trim((string)$pin);
  if ($pin === '') return false;
  try {
    if ($equipe_id) {
      $st = db()->prepare("SELECT 1 FROM equipes WHERE id=? AND pin=? AND pin_dia=CURDATE() LIMIT 1");
      $st->execute([(int)$equipe_id, $pin]);
    } else {
      $st = db()->prepare("SELECT 1 FROM equipes WHERE pin=? AND pin_dia=CURDATE() LIMIT 1");
      $st->execute([$pin]);
    }
    return (bool)$st->fetch();
  } catch (Exception $e) { return false; } // coluna pin ainda não existe => trata como desligado
}

// flag em config: exigir_pin (0/1). Desligado por padrão (seguro).
function exigirPinLigado() {
  try { $r = db()->query("SELECT exigir_pin FROM config WHERE id=1")->fetch(); }
  catch (Exception $e) { return false; }
  return $r ? ((int)$r['exigir_pin'] === 1) : false;
}

// porteiro das ações do tablet: exige (1) o PIN da equipe (se ligado) e (2) estar na rede liberada.
// As duas camadas são INDEPENDENTES e ambas DESLIGADAS por padrão.
function exigirCodigo() {
  // (1) PIN por equipe
  if (exigirPinLigado()) {
    $d = body();
    $pin = isset($d['codigo']) ? trim((string)$d['codigo']) : '';
    $eid = isset($d['equipe_id']) ? (int)$d['equipe_id'] : null;
    if (!pinValido($pin, $eid)) {
      http_response_code(403);
      echo json_encode(['ok'=>false, 'erro'=>'PIN inválido ou expirado. Peça seu PIN na recepção.', 'codigo_invalido'=>true]);
      exit;
    }
  }
  // (2) rede liberada (só celulares na rede do salão)
  $rede = redeLiberada();
  if ($rede !== '') {
    $ip = ipCliente();
    $lista = array_filter(array_map('trim', explode(',', $rede)));
    if (!in_array($ip, $lista, true)) {
      http_response_code(403);
      echo json_encode(['ok'=>false, 'erro'=>'Fora da rede liberada. Conecte-se ao wi-fi do salão.', 'rede_invalida'=>true]);
      exit;
    }
  }
}

// ------------------------------------------------------------
// Sessão do dia (zeragem automática). O placar/online/duelos valem só da
// sessão atual. Na virada do dia, zera o estado VIVO (histórico preservado).
// ------------------------------------------------------------
function encerrarDia() {
  // zera o estado vivo e abre nova sessão. NÃO apaga pontos (ficam para o BI).
  try {
    db()->exec("UPDATE duelos SET status='encerrado', encerrado_em=NOW() WHERE status IN ('aguardando','ativo')");
    db()->exec("UPDATE equipes SET online=0");
    db()->exec("DELETE FROM presencas");
    db()->exec("UPDATE config SET sessao_inicio = NOW() WHERE id=1");
  } catch (Exception $e) {}
}
function garantirSessaoDoDia() {
  try {
    $r = db()->query("SELECT (sessao_inicio IS NULL) AS vazio, (DATE(sessao_inicio) < CURDATE()) AS virou FROM config WHERE id=1")->fetch();
  } catch (Exception $e) {
    // coluna ainda não existe: cria uma vez (a próxima chamada já funciona). Sem ALTER no caminho quente.
    try { db()->exec("ALTER TABLE config ADD COLUMN sessao_inicio TIMESTAMP NULL"); } catch (Exception $e2) {}
    return;
  }
  if (!$r) return;
  if ((int)$r['vazio'] === 1) { // 1ª vez: começa hoje 00:00 (preserva pontos de hoje)
    db()->exec("UPDATE config SET sessao_inicio = CONCAT(CURDATE(),' 00:00:00') WHERE id=1");
    return;
  }
  if ((int)$r['virou'] === 1) encerrarDia(); // sessão é de ontem (ou antes) => vira o dia
}

function ok($data = []) { echo json_encode(['ok' => true] + $data); exit; }
function fail($msg, $code = 400) { http_response_code($code); echo json_encode(['ok' => false, 'erro' => $msg]); exit; }

// registra um evento para a TV animar
function emitir($tipo, $payload = []) {
  $st = db()->prepare('INSERT INTO eventos (tipo, payload) VALUES (?, ?)');
  $st->execute([$tipo, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
}

// OBS: a limpeza de fotos +24h e a poda de eventos NÃO rodam mais aqui (eram
// executadas em toda requisição e podiam pesar). Agora ficam no endpoint
// dedicado api/limpar_fotos.php, disparado pela TV a cada 30 min.
