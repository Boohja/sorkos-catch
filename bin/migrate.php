<?php
declare(strict_types=1);
$root=dirname(__DIR__);require $root.'/app/bootstrap.php';$config=Catch\Core\Config::load($root);$pdo=(new Catch\Core\Database($config))->connection();
$pdo->exec('CREATE TABLE IF NOT EXISTS catch_migrations (migration VARCHAR(255) PRIMARY KEY, applied_at DATETIME(6) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
$applied=$pdo->query('SELECT migration FROM catch_migrations')->fetchAll(PDO::FETCH_COLUMN);
foreach(glob($root.'/database/migrations/*.sql') as $file){$name=basename($file);if(in_array($name,$applied,true))continue;try{$pdo->exec(file_get_contents($file));$stmt=$pdo->prepare('INSERT INTO catch_migrations (migration,applied_at) VALUES (:name,UTC_TIMESTAMP(6))');$stmt->execute(['name'=>$name]);echo "Applied $name\n";}catch(Throwable $e){fwrite(STDERR,"Failed $name: {$e->getMessage()}\n");exit(1);}}
echo "Database is up to date.\n";
