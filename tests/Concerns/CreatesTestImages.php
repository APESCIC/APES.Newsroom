<?php

namespace Tests\Concerns;

use Illuminate\Http\UploadedFile;

trait CreatesTestImages
{
    protected function jpegBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 40, 120, 110));
        imagefilledrectangle($image, 0, 0, (int) ($width / 4), (int) ($height / 4), imagecolorallocate($image, 250, 200, 0));
        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    protected function pngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * A JPEG with an EXIF block holding a camera make, an orientation and a
     * GPS latitude reference, so tests can prove all three are handled.
     */
    protected function jpegWithExif(int $width, int $height, int $orientation = 1, string $make = 'TestCam'): string
    {
        $jpeg = $this->jpegBytes($width, $height);

        $makeValue = $make."\0";
        $entries = 3;
        $ifd0Size = 2 + $entries * 12 + 4;
        $makeOffset = 8 + $ifd0Size;
        $gpsOffset = $makeOffset + strlen($makeValue);

        $tiff = 'II'.pack('v', 42).pack('V', 8);
        $tiff .= pack('v', $entries);
        $tiff .= pack('vvVV', 0x010F, 2, strlen($makeValue), $makeOffset);
        $tiff .= pack('vvV', 0x0112, 3, 1).pack('vv', $orientation, 0);
        $tiff .= pack('vvVV', 0x8825, 4, 1, $gpsOffset);
        $tiff .= pack('V', 0);
        $tiff .= $makeValue;
        $tiff .= pack('v', 1);
        $tiff .= pack('vvV', 0x0001, 2, 2)."N\0\0\0";
        $tiff .= pack('V', 0);

        $payload = "Exif\0\0".$tiff;
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    protected function uploadedFrom(string $bytes, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'hero');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }
}
