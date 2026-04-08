<?php

namespace App\Service;

use Intervention\Image\Geometry\Factories\LineFactory;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Typography\FontFactory;

class OgImageGenerator
{
    private const WIDTH = 1200;
    private const HEIGHT = 630;
    private const ACCENT_BAR_WIDTH = 8;
    private const PADDING_X = 70;
    private const PADDING_TOP = 70;

    private const COLOR_SLATE_800 = '#1e293b';
    private const COLOR_SLATE_500 = '#64748b';
    private const COLOR_SLATE_400 = '#94a3b8';
    private const COLOR_SLATE_200 = '#e2e8f0';
    private const COLOR_PRIMARY_50 = '#f0f9ff';
    private const COLOR_PRIMARY_100 = '#e0f2fe';
    private const COLOR_PRIMARY_500 = '#0ea5e9';
    private const COLOR_PRIMARY_600 = '#0284c7';
    private const COLOR_PRIMARY_700 = '#0369a1';

    private const OUTCOME_COLORS = [
        'favorable' => '#34d399',
        'partial' => '#fbbf24',
        'acuerdo_mediacion' => '#818cf8',
        'unfavorable' => '#f87171',
        'inadmissible' => '#c084fc',
        'archivo' => '#38bdf8',
        'perdida_objeto' => '#94a3b8',
        'desistimiento' => '#94a3b8',
        'derivacion' => '#94a3b8',
        'retrotraer' => '#94a3b8',
        'queja' => '#94a3b8',
        'consulta' => '#94a3b8',
        'aclaracion' => '#94a3b8',
    ];

    private string $projectDir;
    private string $fontSerif;
    private string $fontSans;
    private ImageManager $manager;

    public function __construct(string $projectDir)
    {
        $this->projectDir = $projectDir;
        $this->fontSerif = $projectDir . '/resources/fonts/DMSerifDisplay-Regular.ttf';
        $this->fontSans = $projectDir . '/resources/fonts/DMSans-Variable.ttf';
        $this->manager = new ImageManager(new \Intervention\Image\Drivers\Gd\Driver());
    }

    public function generateHome(): string
    {
        $image = $this->createCanvas(self::COLOR_PRIMARY_500);

        $this->drawTitle($image, 'Ejerce tu derecho', self::COLOR_PRIMARY_600, 52);
        $this->drawTitle($image, 'de acceso a la', self::COLOR_PRIMARY_600, 52, 66);
        $this->drawTitle($image, 'información pública', self::COLOR_PRIMARY_500, 52, 132);

        $this->drawSubtitle($image, 'Gestiona solicitudes, controla plazos,', 230);
        $this->drawSubtitle($image, 'reclama con fundamento', 262);

        // Hero illustration on the right side, vertically centered
        $heroPath = $this->projectDir . '/public/images/hero.png';
        if (file_exists($heroPath)) {
            $hero = $this->manager->read($heroPath);
            $hero->scale(height: 280);
            $xPos = self::WIDTH - $hero->width() - 40;
            $yPos = (self::HEIGHT - $hero->height() - 80) / 2;
            $image->place($hero, 'top-left', (int) $xPos, (int) $yPos);
        }

        $this->drawFooter($image);

        return $this->encode($image);
    }

    /**
     * @param array<string, int> $outcomeStats outcome => count
     */
    public function generateResolucionesIndex(int $total, int $successRate, array $outcomeStats): string
    {
        $image = $this->createCanvas(self::COLOR_PRIMARY_500);

        $x = self::PADDING_X + self::ACCENT_BAR_WIDTH + 20;
        $y = self::PADDING_TOP + 50;

        // Draw colored segments via GD directly for pixel-perfect alignment
        $this->drawColoredTitle($image, $x, $y, 46, [
            ['Repositorio ', self::COLOR_SLATE_800],
            ['unificado', self::COLOR_PRIMARY_600],
            [' de resoluciones', self::COLOR_SLATE_800],
        ]);

        $this->drawColoredTitle($image, $x, $y + 58, 46, [
            ['de transparencia', self::COLOR_SLATE_800],
        ]);

        $stats = number_format($total, 0, ',', '.') . ' resoluciones  ·  ' . $successRate . '% tasa de estimación';
        $this->drawSubtitle($image, $stats, 160);

        // Outcome bar chart
        $favorable = ($outcomeStats['favorable'] ?? 0) + ($outcomeStats['partial'] ?? 0) + ($outcomeStats['acuerdo_mediacion'] ?? 0);
        $unfavorable = ($outcomeStats['unfavorable'] ?? 0) + ($outcomeStats['inadmissible'] ?? 0);
        $other = array_sum($outcomeStats) - $favorable - $unfavorable;
        $this->drawOutcomeBar($image, $favorable, $unfavorable, $other, $x, self::PADDING_TOP + 200);

        $this->drawFooter($image);

        return $this->encode($image);
    }

    public function generateReclamados(int $total): string
    {
        $image = $this->createCanvas(self::COLOR_PRIMARY_500);

        $this->drawTitle($image, 'Administraciones reclamadas', self::COLOR_SLATE_800, 50);
        $this->drawTitle($image, 'ante los consejos de transparencia', self::COLOR_SLATE_800, 50, 64);

        $stats = number_format($total, 0, ',', '.') . ' administraciones reclamadas';
        $this->drawSubtitle($image, $stats, 164);
        $this->drawFooter($image);

        return $this->encode($image);
    }

    public function generateOrganismo(string $name, int $count, int $successRate): string
    {
        $image = $this->createCanvas(self::COLOR_PRIMARY_500);

        $this->drawWrappedTitle($image, $name, self::COLOR_SLATE_800, 48);
        $lines = $this->countWrappedLines($name, 48);
        $subtitleY = self::PADDING_TOP + (int) ($lines * 48 * 1.2) + 50;

        $this->drawText($image, 'Consejo de transparencia', self::PADDING_X + self::ACCENT_BAR_WIDTH + 20, $subtitleY, 24, self::COLOR_SLATE_500, $this->fontSans);

        $statsY = $subtitleY + 46;
        $stats = number_format($count, 0, ',', '.') . ' resoluciones  ·  ' . $successRate . '% estimadas';
        $this->drawText($image, $stats, self::PADDING_X + self::ACCENT_BAR_WIDTH + 20, $statsY, 26, self::COLOR_SLATE_500, $this->fontSans);

        $this->drawFooter($image);

        return $this->encode($image);
    }

    public function generateResolucion(string $subject, string $outcomeLabel, string $outcome, ?string $publicBody, string $referenceNumber): string
    {
        $accentColor = self::OUTCOME_COLORS[$outcome] ?? '#94a3b8';
        $image = $this->createCanvas($accentColor);

        $titleText = mb_strlen($subject) > 120 ? mb_substr($subject, 0, 117) . '...' : $subject;
        $this->drawWrappedTitle($image, $titleText, self::COLOR_SLATE_800, 44);
        $lines = $this->countWrappedLines($titleText, 44);
        $subtitleY = self::PADDING_TOP + (int) ($lines * 44 * 1.2) + 46;

        // Outcome badge
        $this->drawOutcomeBadge($image, $outcomeLabel, $accentColor, self::PADDING_X + self::ACCENT_BAR_WIDTH + 20, $subtitleY);

        // Public body name
        if ($publicBody) {
            $badgeWidth = $this->estimateTextWidth($outcomeLabel, 20) + 30;
            $pbX = self::PADDING_X + self::ACCENT_BAR_WIDTH + 20 + $badgeWidth + 16;
            $this->drawText($image, $publicBody, $pbX, $subtitleY + 6, 22, self::COLOR_SLATE_500, $this->fontSans);
        }

        // Reference number
        $refY = $subtitleY + 50;
        $this->drawText($image, $referenceNumber, self::PADDING_X + self::ACCENT_BAR_WIDTH + 20, $refY, 20, self::COLOR_SLATE_400, $this->fontSans);

        $this->drawFooter($image);

        return $this->encode($image);
    }

    public function generateReclamado(string $name, int $count, int $successRate): string
    {
        $image = $this->createCanvas(self::COLOR_PRIMARY_500);

        $this->drawWrappedTitle($image, $name, self::COLOR_SLATE_800, 46);
        $lines = $this->countWrappedLines($name, 46);
        $subtitleY = self::PADDING_TOP + (int) ($lines * 46 * 1.2) + 50;

        $this->drawText($image, 'Administración reclamada', self::PADDING_X + self::ACCENT_BAR_WIDTH + 20, $subtitleY, 24, self::COLOR_SLATE_500, $this->fontSans);

        $statsY = $subtitleY + 46;
        $stats = number_format($count, 0, ',', '.') . ' reclamaciones  ·  ' . $successRate . '% estimadas a favor del ciudadano';
        $this->drawText($image, $stats, self::PADDING_X + self::ACCENT_BAR_WIDTH + 20, $statsY, 24, self::COLOR_SLATE_500, $this->fontSans);

        $this->drawFooter($image);

        return $this->encode($image);
    }

    private function createCanvas(string $accentColor): ImageInterface
    {
        $image = $this->manager->create(self::WIDTH, self::HEIGHT);

        // Background: light gradient approximation (solid slate-50 with a subtle tint)
        $image->fill('#f8fafc');

        // Subtle gradient overlay: draw a semi-transparent primary-50 rectangle on the right half
        $image->drawRectangle(self::WIDTH / 2, 0, function ($draw) {
            $draw->size(self::WIDTH / 2, self::HEIGHT);
            $draw->background('rgba(240, 249, 255, 0.5)');
        });

        // Decorative circle in top-right
        $image->drawCircle(self::WIDTH - 80, -40, function ($circle) {
            $circle->radius(200);
            $circle->background('rgba(224, 242, 254, 0.4)');
        });

        // Left accent bar
        $image->drawRectangle(0, 0, function ($draw) use ($accentColor) {
            $draw->size(self::ACCENT_BAR_WIDTH, self::HEIGHT);
            $draw->background($accentColor);
        });

        // Subtle bottom border
        $image->drawLine(function (LineFactory $line) {
            $line->from(0, self::HEIGHT - 80);
            $line->to(self::WIDTH, self::HEIGHT - 80);
            $line->color(self::COLOR_SLATE_200);
            $line->width(1);
        });

        return $image;
    }

    private function drawTitle(ImageInterface $image, string $text, string $color, int $size, int $yOffset = 0): void
    {
        $x = self::PADDING_X + self::ACCENT_BAR_WIDTH + 20;
        $y = self::PADDING_TOP + $yOffset + $size;

        $image->text($text, $x, $y, function (FontFactory $font) use ($size, $color) {
            $font->filename($this->fontSerif);
            $font->size($size);
            $font->color($color);
            $font->lineHeight(1.2);
        });
    }

    /**
     * Draw text segments with different colors on a single line using GD directly,
     * ensuring pixel-perfect inter-segment alignment.
     *
     * @param array<array{0: string, 1: string}> $segments [text, hexColor]
     */
    private function drawColoredTitle(ImageInterface $image, int $x, int $y, int $size, array $segments): void
    {
        $gd = $image->core()->native();
        $cx = $x;

        foreach ($segments as [$text, $hex]) {
            $rgb = sscanf($hex, '#%02x%02x%02x');
            $color = imagecolorallocate($gd, $rgb[0], $rgb[1], $rgb[2]);
            $pts = imagettftext($gd, $size, 0, $cx, $y, $color, $this->fontSerif, $text);
            // Advance cursor to lower-right x
            $cx = $pts[2];
        }
    }

    private function drawTitleAt(ImageInterface $image, string $text, string $color, int $size, int $x, int $y): void
    {
        $image->text($text, $x, $y, function (FontFactory $font) use ($size, $color) {
            $font->filename($this->fontSerif);
            $font->size($size);
            $font->color($color);
            $font->lineHeight(1.2);
        });
    }

    private function drawOutcomeBar(ImageInterface $image, int $favorable, int $unfavorable, int $other, int $x, int $y): void
    {
        $total = $favorable + $unfavorable + $other;
        if ($total === 0) {
            return;
        }

        $barWidth = self::WIDTH - $x - self::PADDING_X;
        $barHeight = 16;
        $gap = 3;

        $favW = max(1, (int) round($barWidth * $favorable / $total));
        $unfW = max(1, (int) round($barWidth * $unfavorable / $total));
        $othW = max(1, $barWidth - $favW - $unfW - 2 * $gap);

        // Favorable (green)
        $image->drawRectangle($x, $y, function ($draw) use ($favW, $barHeight) {
            $draw->size($favW, $barHeight);
            $draw->background('#34d399');
        });

        // Unfavorable (red)
        $ux = $x + $favW + $gap;
        $image->drawRectangle($ux, $y, function ($draw) use ($unfW, $barHeight) {
            $draw->size($unfW, $barHeight);
            $draw->background('#f87171');
        });

        // Other (slate)
        $ox = $ux + $unfW + $gap;
        $image->drawRectangle($ox, $y, function ($draw) use ($othW, $barHeight) {
            $draw->size($othW, $barHeight);
            $draw->background('#cbd5e1');
        });

        // Legend below the bar
        $legendY = $y + $barHeight + 28;
        $legendX = $x;
        $legends = [
            ['Favorables: ' . number_format($favorable, 0, ',', '.'), '#34d399'],
            ['Desfavorables: ' . number_format($unfavorable, 0, ',', '.'), '#f87171'],
            ['Otras: ' . number_format($other, 0, ',', '.'), '#cbd5e1'],
        ];

        foreach ($legends as [$label, $color]) {
            // Dot
            $image->drawCircle($legendX + 6, $legendY - 4, function ($circle) use ($color) {
                $circle->radius(5);
                $circle->background($color);
            });

            $this->drawText($image, $label, $legendX + 18, $legendY, 18, self::COLOR_SLATE_500, $this->fontSans);
            $legendX += $this->estimateTextWidth($label, 18) + 50;
        }
    }

    private function drawWrappedTitle(ImageInterface $image, string $text, string $color, int $size): void
    {
        $x = self::PADDING_X + self::ACCENT_BAR_WIDTH + 20;
        $maxWidth = self::WIDTH - $x - self::PADDING_X;

        $lines = $this->wrapText($text, $size, $maxWidth);
        $lineHeight = (int) ($size * 1.2);

        foreach ($lines as $i => $line) {
            $y = self::PADDING_TOP + ($i * $lineHeight) + $size;
            $image->text($line, $x, $y, function (FontFactory $font) use ($size, $color) {
                $font->filename($this->fontSerif);
                $font->size($size);
                $font->color($color);
                $font->lineHeight(1.2);
            });
        }
    }

    private function drawSubtitle(ImageInterface $image, string $text, int $yOffset): void
    {
        $x = self::PADDING_X + self::ACCENT_BAR_WIDTH + 20;
        $y = self::PADDING_TOP + $yOffset;

        $image->text($text, $x, $y, function (FontFactory $font) {
            $font->filename($this->fontSans);
            $font->size(26);
            $font->color(self::COLOR_SLATE_500);
        });
    }

    private function drawText(ImageInterface $image, string $text, int $x, int $y, int $size, string $color, string $fontFile): void
    {
        $image->text($text, $x, $y, function (FontFactory $font) use ($size, $color, $fontFile) {
            $font->filename($fontFile);
            $font->size($size);
            $font->color($color);
        });
    }

    private function drawOutcomeBadge(ImageInterface $image, string $label, string $color, int $x, int $y): void
    {
        $textWidth = $this->estimateTextWidth($label, 20);
        $badgeWidth = $textWidth + 24;
        $badgeHeight = 34;

        // Badge background (rounded rectangle approximated by a rectangle)
        $image->drawRectangle($x, $y - 4, function ($draw) use ($badgeWidth, $badgeHeight, $color) {
            $draw->size($badgeWidth, $badgeHeight);
            $draw->background($color . '22');
            $draw->border($color, 1);
        });

        $image->text($label, $x + 12, $y + 18, function (FontFactory $font) use ($color) {
            $font->filename($this->fontSans);
            $font->size(20);
            $font->color($color);
        });
    }

    private function drawFooter(ImageInterface $image): void
    {
        $y = self::HEIGHT - 40;
        $leftX = self::PADDING_X + self::ACCENT_BAR_WIDTH + 20;

        $image->text('PideInfo', $leftX, $y, function (FontFactory $font) {
            $font->filename($this->fontSans);
            $font->size(22);
            $font->color(self::COLOR_SLATE_400);
        });

        // URL on the right
        $image->text('pideinfo.es', self::WIDTH - self::PADDING_X - 100, $y, function (FontFactory $font) {
            $font->filename($this->fontSans);
            $font->size(20);
            $font->color(self::COLOR_SLATE_400);
        });
    }

    private function wrapText(string $text, int $fontSize, int $maxWidth): array
    {
        $words = explode(' ', $text);
        $lines = [];
        $currentLine = '';

        foreach ($words as $word) {
            $testLine = $currentLine === '' ? $word : $currentLine . ' ' . $word;
            $testWidth = $this->estimateTextWidth($testLine, $fontSize);

            if ($testWidth > $maxWidth && $currentLine !== '') {
                $lines[] = $currentLine;
                $currentLine = $word;
            } else {
                $currentLine = $testLine;
            }
        }

        if ($currentLine !== '') {
            $lines[] = $currentLine;
        }

        return $lines;
    }

    private function countWrappedLines(string $text, int $fontSize): int
    {
        $x = self::PADDING_X + self::ACCENT_BAR_WIDTH + 20;
        $maxWidth = self::WIDTH - $x - self::PADDING_X;

        return count($this->wrapText($text, $fontSize, $maxWidth));
    }

    private function estimateTextWidth(string $text, int $fontSize): int
    {
        return $this->measureTextWidth($text, $fontSize, $this->fontSerif);
    }

    /**
     * Measure text advance width by rendering on a scratch image.
     * imagettftext returns the actual glyph positions, which is more
     * reliable than imagettfbbox for computing where the next word starts.
     */
    private function measureTextWidth(string $text, int $fontSize, string $fontFile): int
    {
        $tmp = imagecreatetruecolor(1, 1);
        $col = imagecolorallocate($tmp, 0, 0, 0);
        $pts = imagettftext($tmp, $fontSize, 0, 0, $fontSize, $col, $fontFile, $text);
        imagedestroy($tmp);

        if ($pts === false) {
            return (int) (mb_strlen($text) * $fontSize * 0.55);
        }

        // pts[2] is the x of the lower-right corner after drawing
        return abs($pts[2] - $pts[0]);
    }

    private function encode(ImageInterface $image): string
    {
        return $image->toPng()->toString();
    }
}
