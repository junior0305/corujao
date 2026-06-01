<?php
// teste.php — abra http://IP:8080/api/teste.php para conferir a conexão
require __DIR__.'/db.php';
$info = [];
try {
  $info['conexao'] = 'OK';
  $info['tabelas'] = array_map(fn($r)=>array_values($r)[0], db()->query('SHOW TABLES')->fetchAll());
  $info['equipes_cadastradas'] = (int)db()->query('SELECT COUNT(*) c FROM equipes')->fetch()['c'];
  $info['config'] = db()->query('SELECT * FROM config WHERE id=1')->fetch();
} catch (Exception $e) {
  $info['erro'] = $e->getMessage();
}
echo json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
