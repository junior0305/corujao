<?php
// ============================================================
// email.php — envio por SMTP (Gmail) + "esqueci minha senha" (link).
// A senha NUNCA é reenviada (é hash); o email manda um LINK para criar
// uma senha nova. Configurações de SMTP ficam no config (admin define).
//
//  acao=config_get            (admin) → host/port/user/from + tem_senha (sem a senha)
//  acao=config_set {...}       (admin) → salva host/port/user/senha/from
//  acao=testar {para}          (admin) → envia um email de teste
//  acao=solicitar {ident}      (público) → gera token e envia o link de redefinição
//  acao=redefinir {token,senha}(público) → define a nova senha
// ============================================================
require __DIR__.'/db.php';
$acao = $_GET['acao'] ?? '';

// colunas/tabelas sob demanda
try { db()->exec("ALTER TABLE usuarios ADD COLUMN email VARCHAR(160) NOT NULL DEFAULT ''"); } catch (Exception $e) {}
foreach (['smtp_host VARCHAR(120) NOT NULL DEFAULT \'smtp.gmail.com\'',
          'smtp_port INT NOT NULL DEFAULT 587',
          'smtp_user VARCHAR(160) NOT NULL DEFAULT \'\'',
          'smtp_pass VARCHAR(200) NOT NULL DEFAULT \'\'',
          'smtp_from VARCHAR(160) NOT NULL DEFAULT \'\''] as $col) {
  try { db()->exec("ALTER TABLE config ADD COLUMN ".$col); } catch (Exception $e) {}
}
try {
  db()->exec("CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expira_em TIMESTAMP NOT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (token_hash)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

function smtpConfig() {
  try { $r = db()->query("SELECT smtp_host,smtp_port,smtp_user,smtp_pass,smtp_from FROM config WHERE id=1")->fetch(); }
  catch (Exception $e) { return null; }
  return $r ?: null;
}

// envia um email HTML via SMTP com STARTTLS (Gmail: smtp.gmail.com:587). [ok, detalhe]
function enviarEmail($para, $assunto, $html) {
  $c = smtpConfig();
  if (!$c || $c['smtp_user']==='' || $c['smtp_pass']==='') return [false, 'SMTP não configurado'];
  $host = $c['smtp_host'] ?: 'smtp.gmail.com'; $port = (int)($c['smtp_port'] ?: 587);
  $from = $c['smtp_from'] ?: $c['smtp_user'];
  $errno=0; $errstr='';
  $fp = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 20);
  if (!$fp) return [false, "Conexão SMTP falhou: $errstr"];
  stream_set_timeout($fp, 20);
  $read = function() use ($fp) { $d=''; while (($l=fgets($fp,515))!==false) { $d.=$l; if (strlen($l)<4 || $l[3]===' ') break; } return $d; };
  $cmd  = function($s) use ($fp,$read) { fwrite($fp, $s."\r\n"); return $read(); };
  $read();
  $cmd("EHLO arena");
  $r = $cmd("STARTTLS");
  if (strpos($r,'220')===false) { fclose($fp); return [false,'STARTTLS recusado']; }
  if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return [false,'TLS falhou']; }
  $cmd("EHLO arena");
  $cmd("AUTH LOGIN");
  $cmd(base64_encode($c['smtp_user']));
  $r = $cmd(base64_encode($c['smtp_pass']));
  if (strpos($r,'235')===false) { fclose($fp); return [false,'Login SMTP recusado (confira o e-mail e a senha de app)']; }
  if (strpos($cmd("MAIL FROM:<$from>"),'250')===false) { fclose($fp); return [false,'MAIL FROM recusado']; }
  if (strpos($cmd("RCPT TO:<$para>"),'250')===false)  { fclose($fp); return [false,'RCPT TO recusado']; }
  if (strpos($cmd("DATA"),'354')===false) { fclose($fp); return [false,'DATA recusado']; }
  $headers = "From: Arena Cury <$from>\r\nTo: <$para>\r\nSubject: $assunto\r\n"
           . "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
  fwrite($fp, $headers."\r\n".$html."\r\n.\r\n");
  $r = $read();
  $cmd("QUIT"); fclose($fp);
  return [strpos($r,'250')!==false, trim($r)];
}

function baseUrl() {
  $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'corujao.comandra.com.br';
  return $proto.'://'.$host;
}

if ($acao === 'config_get') {
  exigirStaff(['admin']);
  $c = smtpConfig() ?: [];
  ok(['host'=>$c['smtp_host']??'smtp.gmail.com', 'port'=>(int)($c['smtp_port']??587),
      'user'=>$c['smtp_user']??'', 'from'=>$c['smtp_from']??'', 'tem_senha'=>!empty($c['smtp_pass'])]);
}

if ($acao === 'config_set') {
  exigirStaff(['admin']);
  $d = body();
  $host = trim((string)($d['host'] ?? 'smtp.gmail.com'));
  $port = (int)($d['port'] ?? 587);
  $user = trim((string)($d['user'] ?? ''));
  $from = trim((string)($d['from'] ?? '')) ?: $user;
  $senha = (string)($d['senha'] ?? ''); // se vazio, mantém a atual
  if ($senha !== '') {
    db()->prepare("UPDATE config SET smtp_host=?, smtp_port=?, smtp_user=?, smtp_from=?, smtp_pass=? WHERE id=1")
        ->execute([$host,$port,$user,$from,$senha]);
  } else {
    db()->prepare("UPDATE config SET smtp_host=?, smtp_port=?, smtp_user=?, smtp_from=? WHERE id=1")
        ->execute([$host,$port,$user,$from]);
  }
  ok();
}

if ($acao === 'testar') {
  exigirStaff(['admin']);
  $d = body(); $para = trim((string)($d['para'] ?? ''));
  if ($para === '') fail('Informe um e-mail de destino');
  [$ok,$det] = enviarEmail($para, 'Arena Cury - teste de e-mail', '<p>Funcionou! O envio de e-mail da Arena Cury está configurado. ✅</p>');
  if ($ok) ok(['enviado'=>true]); else fail('Falha no envio: '.$det, 500);
}

if ($acao === 'solicitar') {
  // público: nunca revela se o usuário existe. Gera token e envia o link.
  $d = body(); $ident = trim((string)($d['ident'] ?? ''));
  if ($ident !== '') {
    $st = db()->prepare("SELECT id, nome, email FROM usuarios WHERE login=? OR (email<>'' AND email=?) LIMIT 1");
    $st->execute([$ident, $ident]); $u = $st->fetch();
    if ($u && !empty($u['email'])) {
      $token = bin2hex(random_bytes(24));
      $hash = hash('sha256', $token);
      db()->prepare("INSERT INTO password_resets (usuario_id, token_hash, expira_em) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 60 MINUTE))")
          ->execute([(int)$u['id'], $hash]);
      $link = baseUrl().'/redefinir.html?t='.$token;
      $html = '<div style="font-family:Arial,sans-serif">'
            . '<h2>Redefinir sua senha — Arena Cury</h2>'
            . '<p>Olá, '.htmlspecialchars($u['nome']).'. Clique no botão abaixo para criar uma nova senha (válido por 1 hora):</p>'
            . '<p><a href="'.$link.'" style="background:#00a0ff;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none">Criar nova senha</a></p>'
            . '<p>Ou copie este link: <br>'.$link.'</p>'
            . '<p style="color:#888;font-size:12px">Se você não pediu, ignore este e-mail.</p></div>';
      enviarEmail($u['email'], 'Arena Cury - redefinir senha', $html);
    }
  }
  ok(['enviado'=>true]); // resposta sempre igual (anti-enumeração)
}

if ($acao === 'redefinir') {
  $d = body(); $token = trim((string)($d['token'] ?? '')); $senha = (string)($d['senha'] ?? '');
  if ($token === '' || strlen($senha) < 4) fail('Token inválido ou senha curta (mín. 4)');
  $hash = hash('sha256', $token);
  $st = db()->prepare("SELECT id, usuario_id FROM password_resets WHERE token_hash=? AND expira_em > NOW() LIMIT 1");
  $st->execute([$hash]); $row = $st->fetch();
  if (!$row) fail('Link inválido ou expirado. Peça um novo.', 400);
  db()->prepare("UPDATE usuarios SET senha_hash=? WHERE id=?")
      ->execute([password_hash($senha, PASSWORD_DEFAULT), (int)$row['usuario_id']]);
  db()->prepare("DELETE FROM password_resets WHERE usuario_id=?")->execute([(int)$row['usuario_id']]);
  ok(['redefinido'=>true]);
}

fail('Ação desconhecida: '.$acao, 404);
