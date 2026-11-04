$ErrorActionPreference = 'Stop'
# Keep the legacy entry point, but use the same artwork for every generated icon.
& node (Join-Path $PSScriptRoot 'build-app-icons.mjs')
if ($LASTEXITCODE -ne 0) { throw 'CampusBite icon generation failed.' }
