$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing
$assetDirectory = Join-Path (Split-Path $PSScriptRoot -Parent) 'app'

# Raster fallbacks use the same outlined CB geometry and palette as favicon.svg.
function Get-IconPng([int] $Size) {
    $bitmap = [System.Drawing.Bitmap]::new($Size, $Size)
    $graphics = [System.Drawing.Graphics]::FromImage($bitmap)
    $background = [System.Drawing.Drawing2D.GraphicsPath]::new()
    $c = [System.Drawing.Drawing2D.GraphicsPath]::new()
    $b = [System.Drawing.Drawing2D.GraphicsPath]::new()
    $fill = [System.Drawing.SolidBrush]::new([System.Drawing.ColorTranslator]::FromHtml('#fff0e1'))
    $border = [System.Drawing.Pen]::new([System.Drawing.ColorTranslator]::FromHtml('#f1d5bf'), 1.5)
    $letters = [System.Drawing.Pen]::new([System.Drawing.ColorTranslator]::FromHtml('#bf2634'), 5.5)
    $stream = [System.IO.MemoryStream]::new()
    try {
        $graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
        $graphics.ScaleTransform([single]($Size / 64.0), [single]($Size / 64.0))
        $background.AddArc(1, 1, 36, 36, 180, 90)
        $background.AddArc(27, 1, 36, 36, 270, 90)
        $background.AddArc(27, 27, 36, 36, 0, 90)
        $background.AddArc(1, 27, 36, 36, 90, 90)
        $background.CloseFigure()
        $graphics.FillPath($fill, $background)
        $graphics.DrawPath($border, $background)
        $letters.StartCap = $letters.EndCap = [System.Drawing.Drawing2D.LineCap]::Round
        $letters.LineJoin = [System.Drawing.Drawing2D.LineJoin]::Round
        $c.AddBezier(29, 24, 26, 20, 18, 20, 15, 25)
        $c.AddBezier(15, 25, 11, 31, 12, 39, 18, 43)
        $c.AddBezier(18, 43, 22, 46, 27, 44, 30, 41)
        $b.AddLine(38, 21, 38, 44)
        $b.StartFigure()
        $b.AddLine(38, 21, 44, 21)
        $b.AddBezier(44, 21, 52, 21, 52, 32, 44, 32)
        $b.AddLine(44, 32, 38, 32)
        $b.StartFigure()
        $b.AddBezier(44, 32, 54, 32, 54, 44, 44, 44)
        $b.AddLine(44, 44, 38, 44)
        $graphics.DrawPath($letters, $c)
        $graphics.DrawPath($letters, $b)
        $bitmap.Save($stream, [System.Drawing.Imaging.ImageFormat]::Png)
        return ,$stream.ToArray()
    } finally {
        $stream.Dispose(); $letters.Dispose(); $border.Dispose(); $fill.Dispose()
        $b.Dispose(); $c.Dispose(); $background.Dispose(); $graphics.Dispose(); $bitmap.Dispose()
    }
}

# apple-touch-icon.png and the manifest icons are rendered from the C.svg logo by tools/build-app-icons.mjs.
[byte[]] $iconPng = Get-IconPng 32
$container = [System.IO.MemoryStream]::new()
$writer = [System.IO.BinaryWriter]::new($container)
try {
    $writer.Write([uint16]0); $writer.Write([uint16]1); $writer.Write([uint16]1)
    $writer.Write([byte]32); $writer.Write([byte]32); $writer.Write([byte]0); $writer.Write([byte]0)
    $writer.Write([uint16]1); $writer.Write([uint16]32)
    $writer.Write([uint32]$iconPng.Length); $writer.Write([uint32]22)
    $writer.Write($iconPng); $writer.Flush()
    [System.IO.File]::WriteAllBytes((Join-Path $assetDirectory 'favicon.ico'), $container.ToArray())
} finally { $writer.Dispose(); $container.Dispose() }
Write-Output 'Built the CampusBite ICO without external dependencies.'
