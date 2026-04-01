<?php

namespace App\Twig;

use App\Service\Admin\ImageUploader;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ImageUrlExtension extends AbstractExtension
{
    public function __construct(
        private readonly ImageUploader $imageUploader,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('s3_image_url', [$this, 'getImageUrl']),
        ];
    }

    public function getImageUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        return $this->imageUploader->getUrl($path);
    }
}
