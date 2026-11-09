param(
    [string]$ProjectPath = (Join-Path $PSScriptRoot '..\..'),
    [switch]$AsJson
)
$ErrorActionPreference = 'Stop'
$checks = New-Object 'System.Collections.Generic.List[object]'
function Add-Check([string]$Code, [string]$Status, [string]$Message) {
    $checks.Add([pscustomobject]@{code=$Code; status=$Status; message=$Message})
}
function Invoke-Probe([string]$Executable, [string]$Arguments) {
    $info = New-Object System.Diagnostics.ProcessStartInfo
    $info.FileName = $Executable
    $info.Arguments = $Arguments
    $info.UseShellExecute = $false
    $info.CreateNoWindow = $true
    $info.RedirectStandardOutput = $true
    $info.RedirectStandardError = $true
    $process = New-Object System.Diagnostics.Process
    $process.StartInfo = $info
    try {
        if (!$process.Start()) { throw 'Probe failed' }
        $stdout = $process.StandardOutput.ReadToEndAsync()
        $stderr = $process.StandardError.ReadToEndAsync()
        if (!$process.WaitForExit(10000)) {
            $process.Kill()
            $process.WaitForExit()
            throw 'Probe timeout'
        }
        $output = $stdout.GetAwaiter().GetResult()
        $null = $stderr.GetAwaiter().GetResult()
        if ($process.ExitCode -ne 0) { throw 'Probe failed' }
        return $output.Trim()
    } finally { $process.Dispose() }
}
try {
    $root = (Resolve-Path -LiteralPath $ProjectPath).Path
    if (!(Test-Path -LiteralPath (Join-Path $root 'artisan') -PathType Leaf)) { throw 'Invalid project' }
} catch {
    if ($AsJson) { [pscustomobject]@{scope='STATIC_LOCAL'; runtime_verified=$false; checks=@(@{code='PROJECT';status='ERROR';message='No se encontro un proyecto Laravel en ProjectPath.'})} | ConvertTo-Json -Depth 5 }
    else { Write-Output 'ERROR PROJECT: No se encontro un proyecto Laravel en ProjectPath.' }
    exit 1
}
if (Test-Path -LiteralPath (Join-Path $root 'composer.lock')) {
    $text = [System.IO.File]::ReadAllText((Join-Path $root 'composer.lock')).Replace("`r`n","`n")
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try { $hash = ([System.BitConverter]::ToString($sha.ComputeHash([System.Text.Encoding]::UTF8.GetBytes($text)))).Replace('-','').ToLowerInvariant() } finally { $sha.Dispose() }
    if ($hash -ne 'a9442d225207677369450b30b34d6314c86e0859598408ef95526bad8e707f4a') { Add-Check 'LOCK composer.lock' 'WARN' 'Lock distinto de la base 9c1c91a. Revalidar requisitos; las reglas de version del diagnostico pertenecen a esa base.' }
    else { Add-Check 'LOCK composer.lock' 'OK' 'Coincide con la base revisada 9c1c91a.' }
}
if (Test-Path -LiteralPath (Join-Path $root 'package-lock.json')) {
    $text = [System.IO.File]::ReadAllText((Join-Path $root 'package-lock.json')).Replace("`r`n","`n")
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try { $hash = ([System.BitConverter]::ToString($sha.ComputeHash([System.Text.Encoding]::UTF8.GetBytes($text)))).Replace('-','').ToLowerInvariant() } finally { $sha.Dispose() }
    if ($hash -ne '07945cde1b098433b4abf87ae84b3e473b7522764c3bcad7ddc86ae0a1be31b2') { Add-Check 'LOCK package-lock.json' 'WARN' 'Lock distinto de la base 9c1c91a. Revalidar requisitos; las reglas de version del diagnostico pertenecen a esa base.' }
    else { Add-Check 'LOCK package-lock.json' 'OK' 'Coincide con la base revisada 9c1c91a.' }
}
$files = @('composer.json','composer.lock','package.json','package-lock.json','.env.example',
    'config/database.php','config/financial.php','config/financial_team4.php',
    'config/financial_marketplace.php','config/financial_identity_authorization.php',
    'config/financial_institutional_expiration.php','phpunit.xml')
foreach ($file in $files) {
    if (Test-Path -LiteralPath (Join-Path $root $file) -PathType Leaf) { Add-Check ('FILE '+$file) 'OK' 'Archivo presente.' }
    else { Add-Check ('FILE '+$file) 'ERROR' 'Falta el archivo requerido.' }
}
foreach ($entry in @(@('.env','WARN','Falta .env; preparar manualmente siguiendo configuracion.md.'),
    @('vendor/autoload.php','ERROR','Faltan dependencias PHP; instalar desde composer.lock.'),
    @('node_modules','WARN','Faltan dependencias de interfaz; usar npm.cmd ci para compilar.'),
    @('public/build/manifest.json','WARN','No hay compilacion Vite versionada/disponible; compilar o usar Vite en desarrollo.'))) {
    if (Test-Path -LiteralPath (Join-Path $root $entry[0])) { Add-Check ('LOCAL '+$entry[0]) 'OK' 'Presente; contenido no inspeccionado.' }
    else { Add-Check ('LOCAL '+$entry[0]) $entry[1] $entry[2] }
}
if (Test-Path -LiteralPath (Join-Path $root 'bootstrap/cache/config.php')) {
    Add-Check 'CONFIG_CACHE' 'WARN' 'Hay configuracion cacheada. El diagnostico no la carga ni la borra; puede diferir de .env.'
} else { Add-Check 'CONFIG_CACHE' 'OK' 'Sin archivo de configuracion cacheada.' }
$php = Get-Command php -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
if (!$php) { Add-Check 'PHP' 'ERROR' 'PHP CLI no esta en PATH.' }
else {
    try {
        # Static PHP only: no autoload, Laravel, .env, phpinfo or database connection.
        $code = "echo json_encode(['version'=>PHP_VERSION,'extensions'=>get_loaded_extensions(),'memory_limit'=>ini_get('memory_limit')]);"
        $probe = Invoke-Probe $php.Source ('-r "'+$code+'"') | ConvertFrom-Json
        $v = [version]($probe.version -replace '-.*$','')
        if ($v -ge [version]'8.4.1' -and $v -lt [version]'8.6') { Add-Check 'PHP' 'OK' ('Version '+$probe.version+' compatible con el rango revisado del lock actual.') }
        else { Add-Check 'PHP' 'ERROR' 'El lock revisado con dependencias de desarrollo requiere PHP 8.4.1 o posterior dentro de 8.4/8.5. Revalidar si cambia composer.lock.' }
        foreach ($ext in @('ctype','filter','hash','mbstring','openssl','session','tokenizer','dom','fileinfo','PDO','pdo_sqlsrv','mongodb','xml','xmlwriter')) {
            if (@($probe.extensions) -contains $ext) { Add-Check ('EXT '+$ext) 'OK' 'Extension cargada en PHP CLI.' }
            else { Add-Check ('EXT '+$ext) 'ERROR' 'Extension no cargada en PHP CLI.' }
        }
        Add-Check 'PHP_MEMORY' 'INFO' ('Limite CLI: '+$probe.memory_limit+'. Para la suite con PDF se verifico 512M mediante php -d; este script no cambia el limite.')
        Add-Check 'PHP_PLATFORM' 'SKIP' 'No se evaluaron todas las restricciones Composer ni las versiones de extensiones. La instalacion debe satisfacer el lock.'
    } catch { Add-Check 'PHP_PROBE' 'ERROR' 'No se pudo obtener el diagnostico PHP en 10 segundos. No se muestra salida interna.' }
}
$node = Get-Command node -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
if (!$node) { Add-Check 'NODE' 'ERROR' 'Node.js no esta en PATH.' }
else {
    try {
        $nv = Invoke-Probe $node.Source '--version'
        $v = [version]($nv.TrimStart('v') -replace '-.*$','')
        $compatible = ($v.Major -eq 20 -and $v -ge [version]'20.19.0') -or $v -ge [version]'22.12.0'
        if ($compatible) { Add-Check 'NODE' 'OK' ('Version '+$nv+' dentro del rango de engines revisado para Vite 8.2.2.') }
        else { Add-Check 'NODE' 'ERROR' 'El lock revisado exige Node 20.19+ de la rama 20, o 22.12+. Node 21 no cumple ese rango.' }
    } catch { Add-Check 'NODE_PROBE' 'ERROR' 'No se pudo comprobar Node.js en 10 segundos.' }
}
foreach ($name in @('composer','npm.cmd')) {
    if (Get-Command $name -ErrorAction SilentlyContinue) { Add-Check ('COMMAND '+$name) 'OK' 'Comando localizado; no ejecutado.' }
    else { Add-Check ('COMMAND '+$name) 'ERROR' 'No se encontro el comando en PATH.' }
}
Add-Check 'DATABASES' 'SKIP' 'SQL Server y MongoDB no fueron contactados; no se verificaron acceso, replica set, datos ni migraciones aplicadas.'
Add-Check 'INTEGRATIONS' 'SKIP' 'No se consultaron E1/E3/E4/E6 ni se validaron credenciales o permisos.'
Add-Check 'OPERATIONS' 'SKIP' 'No se ejecutaron pruebas, scheduler, workers, conciliacion, entrega de eventos ni comandos Artisan.'
$failures = @($checks | Where-Object {$_.status -eq 'ERROR'}).Count
$warnings = @($checks | Where-Object {$_.status -eq 'WARN'}).Count
if ($AsJson) {
    [pscustomobject]@{scope='STATIC_LOCAL'; runtime_verified=$false; errors=$failures; warnings=$warnings; checks=$checks.ToArray()} | ConvertTo-Json -Depth 6
} else {
    Write-Output 'Diagnostico local E2 de solo lectura. No certifica disponibilidad operativa.'
    foreach ($check in $checks) { Write-Output ('{0} {1}: {2}' -f $check.status,$check.code,$check.message) }
    Write-Output ('Errores: {0}. Advertencias: {1}. Bases e integraciones no comprobadas.' -f $failures,$warnings)
}
if ($failures -gt 0) { exit 1 }
exit 0
