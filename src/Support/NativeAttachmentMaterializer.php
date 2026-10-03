<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\Base64Video;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\Video;

/** @internal */
final class NativeAttachmentMaterializer
{
    public static function fromVerifiedContent(File $attachment, string $content): File
    {
        $mime = $attachment->mimeType();
        $base64 = base64_encode($content);
        $materialized = match (true) {
            $attachment instanceof Image => new Base64Image($base64, $mime),
            $attachment instanceof Document => new Base64Document($base64, $mime),
            $attachment instanceof Audio => new Base64Audio($base64, $mime),
            $attachment instanceof Video => new Base64Video($base64, $mime),
            default => $attachment,
        };

        if ($materialized !== $attachment) {
            $materialized->as($attachment->name());
        }

        return $materialized;
    }
}
