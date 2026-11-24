<?php
// Crea, la primera vez, la base de Finanzas en SQL Server y su usuario propio (no el administrador «sa»).
// Lee las contraseñas de /config/campus.env y de la variable CAMPUS_SQL_SA_PASSWORD; no imprime ninguna.
// MongoDB no necesita preparación: sus bases se crean solas y la réplica la inicia el propio contenedor.
$cfg = [];
foreach (file('/config/campus.env', FILE_IGNORE_NEW_LINES) as $l) {
    if (preg_match('/^([A-Za-z0-9_]+)=(.*)$/', $l, $m)) { $cfg[$m[1]] = $m[2]; }
}
$sa = (string) getenv('CAMPUS_SQL_SA_PASSWORD');
$db = $cfg['SQLSRV_DATABASE'] ?? ''; $user = $cfg['SQLSRV_USERNAME'] ?? ''; $pw = $cfg['SQLSRV_PASSWORD'] ?? '';
if (! preg_match('/^[A-Za-z0-9_]+$/', $db) || ! preg_match('/^[A-Za-z0-9_]+$/', $user) || ! preg_match('/^[A-Za-z0-9]+$/', $pw) || $sa === '') {
    fwrite(STDERR, "Configuración incompleta: ejecuta ./campus.sh preparar\n"); exit(2);
}
$pdo = null;
for ($i = 0; $i < 90; $i++) {
    try { $pdo = new PDO('sqlsrv:Server=sqlserver,1433;Database=master;Encrypt=yes;TrustServerCertificate=yes;LoginTimeout=3', 'sa', $sa, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); break; }
    catch (Throwable) { sleep(2); }
}
if (! $pdo) { fwrite(STDERR, "SQL Server no respondió en 3 minutos. Revisa: docker compose logs sqlserver\n"); exit(1); }
if ((int) $pdo->query("SELECT COUNT(*) FROM sys.server_principals WHERE name = '$user'")->fetchColumn() === 0) {
    $pdo->exec("CREATE LOGIN [$user] WITH PASSWORD = '$pw', CHECK_POLICY = OFF");
}
if ((int) $pdo->query("SELECT COUNT(*) FROM sys.databases WHERE name = '$db'")->fetchColumn() === 0) {
    $pdo->exec("CREATE DATABASE [$db]");
}
$pdo->exec("USE [$db]; IF NOT EXISTS (SELECT 1 FROM sys.database_principals WHERE name = '$user') CREATE USER [$user] FOR LOGIN [$user]; ALTER ROLE db_owner ADD MEMBER [$user];");
echo "Base de Finanzas lista ($db).\n";
