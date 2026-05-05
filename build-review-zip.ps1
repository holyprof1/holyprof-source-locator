Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$sourceRoot = Join-Path $repoRoot 'trunk'
$packageRoot = Join-Path $repoRoot '_package'
$packagePluginRoot = Join-Path $packageRoot 'holyprof-source-locator'
$zipPath = Join-Path $repoRoot 'holyprof-source-locator.zip'
$zipTestRoot = Join-Path $repoRoot '_zip-test'
$desktopZipPath = 'C:\Users\HP\OneDrive\Desktop\search plugin\holyprof-source-locator.zip'

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

function Get-NormalizedZipEntries {
    param(
        [Parameter(Mandatory = $true)]
        [string] $ZipFilePath
    )

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $archive = [System.IO.Compression.ZipFile]::OpenRead($ZipFilePath)

    try {
        $entries = @()

        foreach ($entry in $archive.Entries) {
            $entries += $entry.FullName.Replace('\', '/')
        }

        return $entries
    }
    finally {
        $archive.Dispose()
    }
}

function Test-ZipEntries {
    param(
        [Parameter(Mandatory = $true)]
        [string[]] $Entries
    )

    $requiredEntries = @(
        'holyprof-source-locator/holyprof-source-locator.php',
        'holyprof-source-locator/readme.txt',
        'holyprof-source-locator/uninstall.php',
        'holyprof-source-locator/admin/AdminPage.php',
        'holyprof-source-locator/assets/admin.css',
        'holyprof-source-locator/assets/admin.js',
        'holyprof-source-locator/includes/SearchEngine.php'
    )
    $forbiddenPrefixes = @(
        'holyprof-source-locator-review/',
        'trunk/',
        'holyprof-source-locator/holyprof-source-locator/',
        '.svn/',
        '.git/',
        '.github/'
    )

    foreach ($requiredEntry in $requiredEntries) {
        if ($Entries -notcontains $requiredEntry) {
            throw "Packaged zip is invalid. Missing expected zip entry: $requiredEntry"
        }
    }

    foreach ($entry in $Entries) {
        foreach ($forbiddenPrefix in $forbiddenPrefixes) {
            if ($entry.StartsWith($forbiddenPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
                throw "Packaged zip is invalid. Forbidden zip entry detected: $entry"
            }
        }

        if ($entry -match '(^|/)(?:\.svn|\.git)(/|$)') {
            throw "Packaged zip is invalid. Source-control entry detected: $entry"
        }

        if ($entry -match '\.zip$') {
            throw "Packaged zip is invalid. Nested zip detected: $entry"
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

$zipEntries = Get-NormalizedZipEntries -ZipFilePath $zipPath
Test-ZipEntries -Entries $zipEntries

Expand-Archive -LiteralPath $zipPath -DestinationPath $zipTestRoot -Force

$expectedPluginFile = Join-Path $zipTestRoot 'holyprof-source-locator\holyprof-source-locator.php'
$wrongReviewPath = Join-Path $zipTestRoot 'holyprof-source-locator-review\holyprof-source-locator\holyprof-source-locator.php'
$wrongNestedPath = Join-Path $zipTestRoot 'holyprof-source-locator\holyprof-source-locator\holyprof-source-locator.php'

if (-not (Test-Path -LiteralPath $expectedPluginFile)) {
    throw "Packaged zip is invalid. Missing expected plugin file: $expectedPluginFile"
}

if (Test-Path -LiteralPath $wrongReviewPath) {
    throw "Packaged zip is invalid. Unexpected review path exists after extraction: $wrongReviewPath"
}

if (Test-Path -LiteralPath $wrongNestedPath) {
    throw "Packaged zip is invalid. Unexpected nested plugin path exists after extraction: $wrongNestedPath"
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

Write-Host "Plugin zip built successfully:"
Write-Host " - $zipPath"
Write-Host " - $desktopZipPath"
