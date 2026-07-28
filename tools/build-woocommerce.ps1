[CmdletBinding()]
param(
    [string]$OutputDirectory = ''
)

$ErrorActionPreference = 'Stop'
$repository = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
if ($OutputDirectory -eq '') {
    $OutputDirectory = Join-Path $repository 'dist'
}
$output = [System.IO.Path]::GetFullPath($OutputDirectory)
$work = Join-Path $output '.build-work'
$plugin = Join-Path $work 'commerce-documents-woocommerce'
$zip = Join-Path $output 'commerce-documents-woocommerce.zip'
$checksum = Join-Path $output 'commerce-documents-woocommerce.zip.sha256'

if (Test-Path -LiteralPath $work) {
    Remove-Item -LiteralPath $work -Recurse -Force
}
New-Item -ItemType Directory -Path $plugin -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $plugin 'vendor') -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $plugin 'packages') -Force | Out-Null

Copy-Item -LiteralPath (
    Join-Path $repository 'plugins\commerce-documents-woocommerce\commerce-documents-woocommerce.php'
) -Destination $plugin
Copy-Item -LiteralPath (Join-Path $repository 'tools\runtime-autoload.php') -Destination (
    Join-Path $plugin 'vendor\autoload.php'
)

foreach ($package in @('document-core', 'wordpress', 'woocommerce')) {
    Copy-Item -LiteralPath (Join-Path $repository "packages\$package") -Destination (
        Join-Path $plugin 'packages'
    ) -Recurse
}

$fixedTimestamp = [DateTime]::SpecifyKind(
    [DateTime]::Parse('2000-01-01T00:00:00'),
    [DateTimeKind]::Utc
)
Get-ChildItem -LiteralPath $work -Recurse -Force | ForEach-Object {
    $_.LastWriteTimeUtc = $fixedTimestamp
}
(Get-Item -LiteralPath $work).LastWriteTimeUtc = $fixedTimestamp

if (Test-Path -LiteralPath $zip) {
    Remove-Item -LiteralPath $zip -Force
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zipStream = [System.IO.File]::Open($zip, [System.IO.FileMode]::CreateNew)
try {
    $archive = [System.IO.Compression.ZipArchive]::new(
        $zipStream,
        [System.IO.Compression.ZipArchiveMode]::Create,
        $false
    )
    try {
        $files = Get-ChildItem -LiteralPath $plugin -Recurse -File |
            Sort-Object { $_.FullName.Substring($work.Length) }
        foreach ($file in $files) {
            $relative = $file.FullName.Substring($work.Length + 1).Replace('\', '/')
            $entry = $archive.CreateEntry(
                $relative,
                [System.IO.Compression.CompressionLevel]::Optimal
            )
            $entry.LastWriteTime = [DateTimeOffset]::new($fixedTimestamp)
            $sourceStream = [System.IO.File]::OpenRead($file.FullName)
            $entryStream = $entry.Open()
            try {
                $sourceStream.CopyTo($entryStream)
            } finally {
                $entryStream.Dispose()
                $sourceStream.Dispose()
            }
        }
    } finally {
        $archive.Dispose()
    }
} finally {
    $zipStream.Dispose()
}

$hash = (Get-FileHash -LiteralPath $zip -Algorithm SHA256).Hash.ToLowerInvariant()
[System.IO.File]::WriteAllText($checksum, "$hash  commerce-documents-woocommerce.zip`n")

Remove-Item -LiteralPath $work -Recurse -Force

Write-Output $zip
Write-Output $checksum
