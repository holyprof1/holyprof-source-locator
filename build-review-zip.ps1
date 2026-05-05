Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$sourceRoot = Join-Path $repoRoot 'trunk'
$packageRoot = Join-Path $repoRoot '_package'
$packagePluginRoot = Join-Path $packageRoot 'holyprof-source-locator'
$zipPath = Join-Path $repoRoot 'holyprof-source-locator-review.zip'
$zipTestRoot = Join-Path $repoRoot '_zip-test'
$desktopZipPath = 'C:\Users\HP\OneDrive\Desktop\search plugin\holyprof-source-locator-review.zip'

function Remove-IfExists {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Path
    )

    if (Test-Path -LiteralPath $Path) {
        Remove-Item -LiteralPath $Path -Recurse -Force
    }
}

function Test-ExcludedPath {
    param(
        [Parameter(Mandatory = $true)]
        [string] $RelativePath,

        [Parameter(Mandatory = $true)]
        [bool] $IsDirectory
    )

    $normalized = $RelativePath.Replace('\', '/').TrimStart('/')

    if ($normalized -eq '') {
        return $false
    }

    $segments = $normalized -split '/'
    $excludedDirectories = @('.svn', '.git', '.github', 'node_modules', 'vendor', 'dist', 'build', 'backup', '.backup', '_zip-test', '_package', 'temp', 'tmp', 'temporary')

    foreach ($segment in $segments) {
        if ($excludedDirectories -contains $segment) {
            return $true
        }
    }

    if (-not $IsDirectory) {
        $leafName = [System.IO.Path]::GetFileName($normalized)
        $lowerLeafName = $leafName.ToLowerInvariant()

        if ($lowerLeafName.EndsWith('.zip') -or $lowerLeafName.EndsWith('.log') -or $lowerLeafName.EndsWith('.tmp') -or $lowerLeafName.EndsWith('.bak')) {
            return $true
        }

        if ($lowerLeafName.EndsWith('~')) {
            return $true
        }
    }

    return $false
}

function Copy-TrunkContents {
    param(
        [Parameter(Mandatory = $true)]
        [string] $FromPath,

        [Parameter(Mandatory = $true)]
        [string] $ToPath
    )

    $items = Get-ChildItem -LiteralPath $FromPath -Force -Recurse

    foreach ($item in $items) {
        $relativePath = $item.FullName.Substring($FromPath.Length).TrimStart('\')

        if (Test-ExcludedPath -RelativePath $relativePath -IsDirectory $item.PSIsContainer) {
            continue
        }

        $destinationPath = Join-Path $ToPath $relativePath

        if ($item.PSIsContainer) {
            New-Item -ItemType Directory -Path $destinationPath -Force | Out-Null
            continue
        }

        $destinationDirectory = Split-Path -Parent $destinationPath

        if (-not (Test-Path -LiteralPath $destinationDirectory)) {
            New-Item -ItemType Directory -Path $destinationDirectory -Force | Out-Null
        }

        Copy-Item -LiteralPath $item.FullName -Destination $destinationPath -Force
    }
}

function Invoke-PhpLint {
    param(
        [Parameter(Mandatory = $true)]
        [string[]] $Paths
    )

    foreach ($path in $Paths) {
        Write-Host "Linting $path"
        & php -l $path

        if ($LASTEXITCODE -ne 0) {
            throw "PHP lint failed for $path"
        }
    }
}

if (-not (Test-Path -LiteralPath $sourceRoot)) {
    throw "Source trunk not found: $sourceRoot"
}

Remove-IfExists -Path $zipPath
Remove-IfExists -Path $packageRoot
Remove-IfExists -Path $zipTestRoot

New-Item -ItemType Directory -Path $packagePluginRoot -Force | Out-Null
Copy-TrunkContents -FromPath $sourceRoot -ToPath $packagePluginRoot

Compress-Archive -Path $packagePluginRoot -DestinationPath $zipPath -CompressionLevel Optimal -Force

Expand-Archive -LiteralPath $zipPath -DestinationPath $zipTestRoot -Force

$expectedPluginFile = Join-Path $zipTestRoot 'holyprof-source-locator\holyprof-source-locator.php'

if (-not (Test-Path -LiteralPath $expectedPluginFile)) {
    throw "Packaged zip is invalid. Missing expected plugin file: $expectedPluginFile"
}

Invoke-PhpLint -Paths @(
    (Join-Path $zipTestRoot 'holyprof-source-locator\holyprof-source-locator.php'),
    (Join-Path $zipTestRoot 'holyprof-source-locator\includes\SearchEngine.php'),
    (Join-Path $zipTestRoot 'holyprof-source-locator\admin\AdminPage.php'),
    (Join-Path $zipTestRoot 'holyprof-source-locator\uninstall.php')
)

Copy-Item -LiteralPath $zipPath -Destination $desktopZipPath -Force
Remove-IfExists -Path $zipTestRoot
Remove-IfExists -Path $packageRoot

Write-Host "Review zip built successfully:"
Write-Host " - $zipPath"
Write-Host " - $desktopZipPath"
