#Requires -Version 5.1
$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$LocalPhp = Join-Path $Root 'php\php.exe'

if (Test-Path $LocalPhp) {
    Write-Host "[PHP] Klasorde php.exe zaten var."
    exit 0
}

if (Get-Command php -ErrorAction SilentlyContinue) {
    Write-Host "[PHP] Sistemde 'php' komutu var, indirme atlaniyor."
    exit 0
}

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$JsonUrl = 'https://windows.php.net/downloads/releases/releases.json'
$BaseZip = 'https://windows.php.net/downloads/releases/'

Write-Host "[PHP] Surum listesi indiriliyor..."
$jsonText = (Invoke-WebRequest -Uri $JsonUrl -UseBasicParsing).Content
$j = $jsonText | ConvertFrom-Json

function Get-NtsZipPath($obj) {
    if (-not $obj) { return $null }
    $pref = @('nts-vs17-x64', 'nts-vs16-x64')
    foreach ($k in $pref) {
        $slot = $obj.($k)
        if ($slot -and $slot.zip -and $slot.zip.path) {
            return [string]$slot.zip.path
        }
    }
    return $null
}

# Once 8.3 (VS16): daha cok PCde VC++ calisir; yoksa 8.4/8.2...
$zipName = $null
foreach ($branch in @('8.3', '8.4', '8.2', '8.1')) {
    if (-not ($j.PSObject.Properties.Name -contains $branch)) { continue }
    $branchObj = $j.($branch)
    $zipName = Get-NtsZipPath $branchObj
    if ($zipName) {
        Write-Host "[PHP] Surum: $branch -> $zipName"
        break
    }
}

if (-not $zipName) {
    Write-Host "[HATA] releases.json icinde uygun nts-x64 paketi bulunamadi."
    exit 1
}

$zipUrl = $BaseZip + $zipName
$tmpDir = Join-Path $env:TEMP ('printonnow-php-' + [Guid]::NewGuid().ToString('N'))
$zipFile = Join-Path $tmpDir $zipName
New-Item -ItemType Directory -Force -Path $tmpDir | Out-Null

try {
    Write-Host "[PHP] Indiriliyor (bir kere, ~30 MB): $zipUrl"
    Invoke-WebRequest -Uri $zipUrl -OutFile $zipFile -UseBasicParsing

    $stage = Join-Path $tmpDir 'stage'
    New-Item -ItemType Directory -Force -Path $stage | Out-Null
    Expand-Archive -LiteralPath $zipFile -DestinationPath $stage -Force

    $destPhp = Join-Path $Root 'php'
    if (Test-Path $destPhp) {
        Remove-Item -LiteralPath $destPhp -Recurse -Force
    }
    New-Item -ItemType Directory -Force -Path $destPhp | Out-Null

    $phpFound = Get-ChildItem -LiteralPath $stage -Filter 'php.exe' -Recurse -ErrorAction SilentlyContinue |
        Select-Object -First 1
    if (-not $phpFound) {
        Write-Host "[HATA] Zip acildi ama php.exe bulunamadi."
        exit 1
    }
    $sourceDir = $phpFound.DirectoryName
    Copy-Item -Path (Join-Path $sourceDir '*') -Destination $destPhp -Recurse -Force

    $iniDev = Join-Path $destPhp 'php.ini-development'
    $ini = Join-Path $destPhp 'php.ini'
    if (-not (Test-Path $ini) -and (Test-Path $iniDev)) {
        Copy-Item -LiteralPath $iniDev -Destination $ini
    }

    if (Test-Path $ini) {
        $c = Get-Content -LiteralPath $ini -Raw -Encoding UTF8
        $c = $c -replace '(?m)^\s*;\s*extension_dir\s*=\s*"ext"\s*$', 'extension_dir = "ext"'
        $c = $c -replace '(?m)^\s*;\s*extension_dir\s*=\s*"\./ext"\s*$', 'extension_dir = "ext"'
        $c = $c -replace '(?m)^\s*;\s*extension=curl\s*$', 'extension=curl'
        $c = $c -replace '(?m)^\s*;\s*extension=openssl\s*$', 'extension=openssl'
        $c = $c -replace '(?m)^\s*;\s*extension=mbstring\s*$', 'extension=mbstring'
        [IO.File]::WriteAllText($ini, $c, [Text.UTF8Encoding]::new($false))
    }

    $phpExe = Join-Path $destPhp 'php.exe'
    if (-not (Test-Path $phpExe)) {
        Write-Host "[HATA] Kurulum sonrasi php.exe bulunamadi."
        exit 1
    }

    & $phpExe -v
    Write-Host "[PHP] Kurulum tamam: $phpExe"
    exit 0
}
catch {
    Write-Host "[HATA] $($_.Exception.Message)"
    exit 1
}
finally {
    if (Test-Path $tmpDir) {
        Remove-Item -LiteralPath $tmpDir -Recurse -Force -ErrorAction SilentlyContinue
    }
}
