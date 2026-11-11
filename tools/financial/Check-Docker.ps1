param([string]$ProjectPath = (Join-Path $PSScriptRoot '..\..'))
$ErrorActionPreference = 'Stop'
$ProjectPath = (Resolve-Path -LiteralPath $ProjectPath).Path
if (!(Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'No se encontro Docker. Instala/inicia Docker Desktop con contenedores Linux antes de verificar este perfil.'
}
Push-Location $ProjectPath
try {
    & docker compose version
    if ($LASTEXITCODE -ne 0) { throw 'Docker Compose V2 no esta disponible.' }
    # --quiet: no expanded configuration, passwords or .env contents in the output.
    & docker compose --env-file docker/e2/compose.env -f docker/e2/compose.yaml config --quiet
    if ($LASTEXITCODE -ne 0) { throw 'Compose no pudo validar la estructura. Conserva el error sin compartir secretos.' }
    & docker info --format '{{.OSType}}'
    if ($LASTEXITCODE -ne 0) { throw 'Docker Engine no responde. Abre Docker Desktop y espera a que termine de iniciar.' }
    $os = & docker info --format '{{.OSType}}'
    if ($LASTEXITCODE -ne 0 -or "$os".Trim() -ne 'linux') { throw 'Este perfil requiere contenedores Linux.' }
    Write-Host 'OK: Compose valido y Docker Engine Linux accesible.'
    Write-Host 'No se construyeron imagenes, no se iniciaron contenedores, no se abrio .env ni se conectaron bases.'
    Write-Host 'Pendientes: build, configuracion local, conectividad SQL/Mongo y revision web.'
} finally {
    Pop-Location
}
