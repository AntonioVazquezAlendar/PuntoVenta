$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$issuerDirectory = Join-Path $projectRoot 'storage/emisor-privado'
$publicFile = Join-Path $projectRoot 'app/Config/licencia_publica.pem'
$outputDirectory = Join-Path $projectRoot 'storage/licencias-emitidas'
try {
    Write-Host 'Emisor privado de licencias PuntoVenta'
    Write-Host 'Esta herramienta y los archivos privados se conservan solo en tu equipo.'
    if (-not (Test-Path -LiteralPath (Join-Path $issuerDirectory 'privada.pem'))) {
        Write-Host 'Se prepararan las llaves del proveedor. Elige y conserva una contrasena de al menos 12 caracteres.'
        & node (Join-Path $PSScriptRoot 'emisor.js') iniciar --directorio $issuerDirectory --publica $publicFile
        if ($LASTEXITCODE -ne 0) { throw 'No se pudo preparar el emisor. Revisa LEEME.txt.' }
    }
    Write-Host '1. Renovacion mensual (30 dias)'
    Write-Host '2. Renovacion anual (365 dias)'
    $choice = Read-Host 'Elige 1 o 2'
    if ($choice -notin @('1', '2')) { throw 'Selecciona 1 o 2.' }
    $plan = if ($choice -eq '1') { 'mensual' } else { 'anual' }
    $installationCode = (Read-Host 'Pega el codigo PV- del cliente').Trim()
    $businessName = (Read-Host 'Nombre del negocio').Trim()
    $outputFile = Join-Path $outputDirectory ('clave-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '-' + [Guid]::NewGuid().ToString('N').Substring(0,8) + '.txt')
    & node (Join-Path $PSScriptRoot 'emisor.js') emitir --directorio $issuerDirectory --instalacion $installationCode --negocio $businessName --plan $plan --salida $outputFile
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo emitir. Revisa el mensaje anterior y LEEME.txt.' }
    Write-Host 'Entrega al cliente el contenido del archivo de clave. No entregues tu carpeta privada.'
} catch {
    Write-Host ('Error: ' + $_.Exception.Message) -ForegroundColor Red
}
[void](Read-Host 'Pulsa Enter para terminar')
