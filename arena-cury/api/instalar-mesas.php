<?php
// instalar-mesas.php — cria a tabela "mesas" e as colunas novas em "reservas".
// Abra UMA VEZ: https://corujao.comandra.com.br/api/instalar-mesas.php
require __DIR__.'/db.php';
$log = [];
function tenta($desc,$sql,&$log){ try{ db()->exec($sql); $log[]="OK: $desc"; }
  catch(Exception $e){ $m=$e->getMessage();
    if(stripos($m,'exists')!==false||stripos($m,'Duplicate')!==false){$log[]="já existia: $desc";}
    else {$log[]="ERRO em $desc: ".$m;} } }

// tabela mesas
tenta('criar tabela mesas',
 "CREATE TABLE IF NOT EXISTS mesas (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(80) NOT NULL,
  lugares INT NOT NULL DEFAULT 20, ordem INT NOT NULL DEFAULT 0, criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP)
  ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $log);

// colunas novas em reservas
tenta('coluna reservas.mesa_id',"ALTER TABLE reservas ADD COLUMN mesa_id INT NULL",$log);
tenta('coluna reservas.lugares',"ALTER TABLE reservas ADD COLUMN lugares INT NOT NULL DEFAULT 0",$log);
tenta('coluna reservas.equipe_id',"ALTER TABLE reservas ADD COLUMN equipe_id INT NULL",$log);

// cria 5 mesas padrão se não houver nenhuma
$n = (int)db()->query("SELECT COUNT(*) c FROM mesas")->fetch()['c'];
if ($n === 0) {
  for ($i=1;$i<=5;$i++){ db()->prepare("INSERT INTO mesas (nome,lugares,ordem) VALUES (?,?,?)")
      ->execute(["Mesa $i",20,$i]); }
  $log[]="OK: 5 mesas padrão criadas";
} else { $log[]="já havia $n mesas"; }

$mesas = db()->query("SELECT id,nome,lugares FROM mesas ORDER BY ordem")->fetchAll();
echo json_encode(['resultado'=>$log,'mesas'=>$mesas,
  'proximo'=>'Agora teste o agendamento em /gerente.html'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
