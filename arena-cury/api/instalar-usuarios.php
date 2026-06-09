<?php
// instalar-usuarios.php — cria a tabela de usuários (logins de agendamento).
// Abra UMA VEZ: https://corujao.comandra.com.br/api/instalar-usuarios.php
require __DIR__.'/db.php';
$log=[];
try{
  db()->exec("CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    login VARCHAR(60) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    nome VARCHAR(120) NOT NULL,
    diretoria VARCHAR(120) NULL,
    superintendencia VARCHAR(120) NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $log[]="OK: tabela usuarios";
  // garante a coluna diretoria caso a tabela já existisse sem ela
  try{ db()->exec("ALTER TABLE usuarios ADD COLUMN diretoria VARCHAR(120) NULL"); $log[]="OK: coluna diretoria"; }
  catch(Exception $e){ $log[]="diretoria: já existia"; }
}catch(Exception $e){ $log[]="obs: ".$e->getMessage(); }

// cria um admin inicial se a tabela estiver vazia (login: admin / senha: admin123)
$n=(int)db()->query("SELECT COUNT(*) c FROM usuarios")->fetch()['c'];
if($n===0){
  $hash=password_hash("admin123",PASSWORD_DEFAULT);
  db()->prepare("INSERT INTO usuarios (login,senha_hash,nome,superintendencia) VALUES (?,?,?,?)")
      ->execute(["admin",$hash,"Administrador",null]);
  $log[]="OK: usuário inicial criado -> login: admin / senha: admin123 (troque depois)";
}else{ $log[]="já havia $n usuário(s)"; }

echo json_encode(['resultado'=>$log], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
