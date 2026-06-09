<?php
// usuarios.php — logins de acesso ao agendamento (superintendentes)
// A recepcionista cria/gerencia. Senhas guardadas com hash.
// Ações: listar | criar | remover | resetar | login
require __DIR__.'/db.php';
$acao = $_GET['acao'] ?? '';

if ($acao === 'listar') {
  // nunca devolve a senha (nem o hash)
  $rows = db()->query("SELECT id, login, nome, superintendencia, criado_em FROM usuarios ORDER BY nome")->fetchAll();
  ok(['usuarios' => $rows]);
}

if ($acao === 'criar') {
  $d = body();
  $login = trim($d['login'] ?? ''); $senha = (string)($d['senha'] ?? '');
  $nome = trim($d['nome'] ?? ''); $sup = trim($d['superintendencia'] ?? '');
  if ($login==='' || $senha==='' || $nome==='') fail('Preencha login, senha e nome');
  if (strlen($senha) < 4) fail('A senha precisa ter ao menos 4 caracteres');
  // login único
  $c = db()->prepare('SELECT id FROM usuarios WHERE login=?'); $c->execute([$login]);
  if ($c->fetch()) fail('Esse login já existe');
  $hash = password_hash($senha, PASSWORD_DEFAULT);
  db()->prepare('INSERT INTO usuarios (login, senha_hash, nome, superintendencia) VALUES (?,?,?,?)')
      ->execute([$login,$hash,$nome,$sup]);
  ok(['id' => db()->lastInsertId()]);
}

if ($acao === 'remover') {
  $d = body(); $id=(int)($d['id']??0);
  if (!$id) fail('Informe o usuário');
  db()->prepare('DELETE FROM usuarios WHERE id=?')->execute([$id]);
  ok();
}

if ($acao === 'resetar') {
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
  // devolve um "token" simples (id + nome) — sessão leve no cliente
  ok(['usuario' => ['id'=>(int)$u['id'], 'login'=>$u['login'], 'nome'=>$u['nome'], 'superintendencia'=>$u['superintendencia']]]);
}

fail('Ação desconhecida: '.$acao, 404);
