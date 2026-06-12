<?php
// usuarios.php — logins de acesso ao agendamento (superintendentes)
// A recepcionista cria/gerencia. Senhas guardadas com hash.
// Ações: listar | criar | remover | resetar | login
require __DIR__.'/db.php';
$acao = $_GET['acao'] ?? '';

// papel: admin | recepcao | agendamento (cria a coluna sob demanda)
try { db()->exec("ALTER TABLE usuarios ADD COLUMN papel VARCHAR(20) NOT NULL DEFAULT 'agendamento'"); } catch (Exception $e) {}

if ($acao === 'quem') {
  // quem está logado nesta sessão (para a recepção decidir se mostra o login)
  ok(['usuario' => sessaoUser(), 'exigir_login' => exigirLoginLigado()]);
}

if ($acao === 'sair') {
  sessaoIniciar();
  $_SESSION = [];
  @session_destroy();
  ok();
}

if ($acao === 'listar') {
  // nunca devolve a senha (nem o hash)
  $rows = db()->query("SELECT id, login, nome, papel, diretoria, superintendencia, criado_em FROM usuarios ORDER BY papel, nome")->fetchAll();
  ok(['usuarios' => $rows]);
}

if ($acao === 'criar') {
  $d = body();
  $login = trim($d['login'] ?? ''); $senha = (string)($d['senha'] ?? '');
  $nome = trim($d['nome'] ?? ''); $dir = trim($d['diretoria'] ?? ''); $sup = trim($d['superintendencia'] ?? '');
  $papel = in_array(($d['papel'] ?? ''), ['admin','recepcao','agendamento'], true) ? $d['papel'] : 'agendamento';
  if ($login==='' || $senha==='' || $nome==='') fail('Preencha login, senha e nome');
  if (strlen($senha) < 4) fail('A senha precisa ter ao menos 4 caracteres');
  exigirStaff(['admin','recepcao']);                       // criar usuário é ação de staff
  if ($papel !== 'agendamento') exigirStaff(['admin']);    // só admin cria admin/recepção
  // login único
  $c = db()->prepare('SELECT id FROM usuarios WHERE login=?'); $c->execute([$login]);
  if ($c->fetch()) fail('Esse login já existe');
  $hash = password_hash($senha, PASSWORD_DEFAULT);
  db()->prepare('INSERT INTO usuarios (login, senha_hash, nome, papel, diretoria, superintendencia) VALUES (?,?,?,?,?,?)')
      ->execute([$login,$hash,$nome,$papel,$dir,$sup]);
  ok(['id' => db()->lastInsertId()]);
}

if ($acao === 'remover') {
  exigirStaff(['admin']);
  $d = body(); $id=(int)($d['id']??0);
  if (!$id) fail('Informe o usuário');
  db()->prepare('DELETE FROM usuarios WHERE id=?')->execute([$id]);
  ok();
}

if ($acao === 'resetar') {
  exigirStaff(['admin']);
  $d = body(); $id=(int)($d['id']??0); $senha=(string)($d['senha']??'');
  if (!$id || strlen($senha)<4) fail('Informe usuário e nova senha (mín. 4)');
  $hash = password_hash($senha, PASSWORD_DEFAULT);
  db()->prepare('UPDATE usuarios SET senha_hash=? WHERE id=?')->execute([$hash,$id]);
  ok();
}

if ($acao === 'login') {
  $d = body();
  $login = trim($d['login'] ?? ''); $senha = (string)($d['senha'] ?? '');
  if ($login==='' || $senha==='') fail('Informe login e senha');
  $st = db()->prepare('SELECT * FROM usuarios WHERE login=?'); $st->execute([$login]);
  $u = $st->fetch();
  if (!$u || !password_verify($senha, $u['senha_hash'])) fail('Login ou senha inválidos', 401);
  // cria a sessão no servidor (cookie httponly)
  sessaoIniciar();
  $_SESSION['uid']   = (int)$u['id'];
  $_SESSION['nome']  = $u['nome'];
  $_SESSION['papel'] = $u['papel'] ?? 'agendamento';
  ok(['usuario' => ['id'=>(int)$u['id'], 'login'=>$u['login'], 'nome'=>$u['nome'],
                    'papel'=>$_SESSION['papel'], 'diretoria'=>$u['diretoria'], 'superintendencia'=>$u['superintendencia']]]);
}

fail('Ação desconhecida: '.$acao, 404);
