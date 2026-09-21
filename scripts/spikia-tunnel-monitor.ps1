<#
.SYNOPSIS
    Monitor de tunel para Spikia: mantiene vivo ngrok/cloudflared ante una red dinamica
    y actualiza .env (SPIKIA_PUBLIC_BASE_URL) automaticamente cuando la URL publica cambia.

.DESCRIPTION
    - Levanta el tunel y lo VIGILA en bucle.
    - Si el proceso del tunel muere (p.ej. "failed to serve tunnel connection" por cambio
      de IP / parpadeo de red), lo reinicia solo.
    - Detecta la URL publica vigente y, si difiere de la del .env, ejecuta
      `php artisan spikia:set-tunnel-url <url>` (que reescribe .env y limpia caches).
    - No requiere recargar nada manualmente: al recargar la portada los QR ya apuntan
      a la URL nueva, porque el QR se genera con SpikiaUrl::public(route(...)).

.PARAMETER Port
    Puerto local donde corre Laravel (php artisan serve / Laragon). Default 8000.

.PARAMETER Provider
    'ngrok' (default) o 'cloudflared'.

.PARAMETER Domain
    Dominio reservado de ngrok (ej: wolframic-mutely-ayleen.ngrok-free.dev).
    Dejalo vacio ("") para usar un tunel con URL aleatoria.

.PARAMETER CheckIntervalSeconds
    Cada cuantos segundos se verifica el estado del tunel. Default 5.

.EXAMPLE
    .\scripts\spikia-tunnel-monitor.ps1
    .\scripts\spikia-tunnel-monitor.ps1 -Port 8000 -Provider ngrok -Domain "wolframic-mutely-ayleen.ngrok-free.dev"
    .\scripts\spikia-tunnel-monitor.ps1 -Provider cloudflared -Domain ""
#>

param(
    [int]$Port = 8000,
    [ValidateSet('ngrok', 'cloudflared')]
    [string]$Provider = 'cloudflared',
    [string]$Domain = '',
    [int]$CheckIntervalSeconds = 5
)

$ErrorActionPreference = 'Stop'

# Raiz del proyecto = carpeta padre de /scripts
$ProjectRoot = Split-Path -Parent $PSScriptRoot
$EnvPath     = Join-Path $ProjectRoot '.env'
$NgrokExe    = Join-Path $ProjectRoot 'ngrok.exe'
$CfExe       = Join-Path $ProjectRoot 'cloudflared.exe'
$CfLog       = Join-Path $env:TEMP 'spikia-cloudflared.log'

function Write-Log([string]$Message, [string]$Color = 'Gray') {
    $ts = Get-Date -Format 'HH:mm:ss'
    Write-Host "[$ts] $Message" -ForegroundColor $Color
}

function Get-EnvPublicUrl {
    if (-not (Test-Path $EnvPath)) { return '' }
    $line = Select-String -Path $EnvPath -Pattern '^SPIKIA_PUBLIC_BASE_URL=(.*)$' -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($null -eq $line) { return '' }
    return $line.Matches[0].Groups[1].Value.Trim().TrimEnd('/')
}

# --- Arranque del tunel segun proveedor -----------------------------------
function Start-Tunnel {
    if ($Provider -eq 'ngrok') {
        if (-not (Test-Path $NgrokExe)) { throw "No se encontro ngrok.exe en $ProjectRoot" }
        $ngrokArgs = @('http', "$Port", '--log', 'stdout')
        if ($Domain -ne '') { $ngrokArgs += @('--domain', $Domain) }
        Write-Log "Iniciando ngrok ($($ngrokArgs -join ' '))..." 'Cyan'
        return Start-Process -FilePath $NgrokExe -ArgumentList $ngrokArgs -PassThru -WindowStyle Hidden
    }

    if (-not (Test-Path $CfExe)) { throw "No se encontro cloudflared.exe en $ProjectRoot" }
    # Limpiar logs viejos para no leer una URL obsoleta tras un reinicio.
    foreach ($f in @($CfLog, "$CfLog.err")) {
        if (Test-Path $f) { Remove-Item $f -Force -ErrorAction SilentlyContinue }
    }
    $cfArgs = @('tunnel', '--url', "http://localhost:$Port")
    if ($Domain -ne '') { $cfArgs = @('tunnel', 'run', '--url', "http://localhost:$Port", $Domain) }
    Write-Log "Iniciando cloudflared ($($cfArgs -join ' '))..." 'Cyan'
    return Start-Process -FilePath $CfExe -ArgumentList $cfArgs -PassThru -WindowStyle Hidden `
        -RedirectStandardOutput $CfLog -RedirectStandardError "$CfLog.err"
}

# --- Deteccion de la URL publica vigente ----------------------------------
function Get-PublicUrl {
    if ($Provider -eq 'ngrok') {
        try {
            # ngrok expone su estado en la API local; si no responde, el tunel esta caido.
            $resp = Invoke-RestMethod -Uri 'http://127.0.0.1:4040/api/tunnels' -TimeoutSec 4
            $https = $resp.tunnels | Where-Object { $_.public_url -like 'https://*' } | Select-Object -First 1
            if ($https) { return $https.public_url.TrimEnd('/') }
        } catch {
            return ''   # API caida => tunel caido
        }
        return ''
    }

    # cloudflared: la URL del quick tunnel suele imprimirse en stderr; revisamos ambos logs.
    foreach ($logFile in @("$CfLog.err", $CfLog)) {
        if (Test-Path $logFile) {
            $m = Select-String -Path $logFile -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' -ErrorAction SilentlyContinue |
                Select-Object -Last 1
            if ($m) { return $m.Matches[0].Value.TrimEnd('/') }
        }
    }
    if ($Domain -ne '') { return "https://$Domain" }
    return ''
}

function Update-EnvUrl([string]$Url) {
    Write-Log "URL publica nueva detectada: $Url -> actualizando .env" 'Yellow'
    Push-Location $ProjectRoot
    try {
        & php artisan spikia:set-tunnel-url $Url
        Write-Log "Listo. Los QR usaran $Url al recargar la portada." 'Green'
    } catch {
        Write-Log "ERROR ejecutando artisan spikia:set-tunnel-url: $_" 'Red'
    } finally {
        Pop-Location
    }
}

# --- Bucle principal -------------------------------------------------------
Write-Log "Monitor de tunel Spikia | proveedor=$Provider puerto=$Port dominio='$Domain'" 'White'
Write-Log "Proyecto: $ProjectRoot" 'DarkGray'

$proc = $null
$lastAppliedUrl = Get-EnvPublicUrl
Write-Log "SPIKIA_PUBLIC_BASE_URL actual en .env: '$lastAppliedUrl'" 'DarkGray'

try {
    while ($true) {
        # 1) Asegurar que el proceso del tunel sigue vivo
        if ($null -eq $proc -or $proc.HasExited) {
            if ($proc -and $proc.HasExited) {
                Write-Log "El tunel cayo (exit code $($proc.ExitCode)). Reiniciando..." 'Red'
            }
            $proc = Start-Tunnel
            Start-Sleep -Seconds 6   # margen para que el tunel publique su URL
        }

        # 2) Detectar la URL publica vigente
        $current = Get-PublicUrl

        if ([string]::IsNullOrWhiteSpace($current)) {
            Write-Log "Tunel sin URL activa (red inestable). Reintentando..." 'DarkYellow'
            # Si el proceso sigue 'vivo' pero no sirve, lo matamos para forzar reinicio limpio.
            if ($proc -and -not $proc.HasExited) {
                try { $proc.Kill() } catch {}
            }
            $proc = $null
        }
        elseif ($current -ne $lastAppliedUrl) {
            Update-EnvUrl $current
            $lastAppliedUrl = $current
        }
        else {
            Write-Log "Tunel OK: $current" 'Green'
        }

        Start-Sleep -Seconds $CheckIntervalSeconds
    }
}
finally {
    Write-Log "Deteniendo monitor y tunel..." 'White'
    if ($proc -and -not $proc.HasExited) {
        try { $proc.Kill() } catch {}
    }
}
