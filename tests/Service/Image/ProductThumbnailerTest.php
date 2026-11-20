<?php

declare(strict_types=1);

namespace App\Tests\Service\Image;

use App\Service\Image\ProductThumbnailer;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ProductThumbnailerTest extends TestCase
{
    private string $dir;
    private Filesystem $storage;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/thumb-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->storage = new Filesystem(new LocalFilesystemAdapter($this->dir));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function testThumbPathKeepsTheNameAndSwitchesToWebp(): void
    {
        self::assertSame('thumbs/400/20250421_11:25:49_cube.webp', ProductThumbnailer::thumbPath('20250421_11:25:49_cube.jpg', 400));
        self::assertSame('thumbs/800/a.b.webp', ProductThumbnailer::thumbPath('a.b.png', 800));
    }

    public function testGeneratesDownscaledWebpOnceAndSkipsThumbsAndUnknownTypes(): void
    {
        $img = imagecreatetruecolor(1200, 900);
        ob_start();
        imagejpeg($img);
        $this->storage->write('big.jpg', (string) ob_get_clean());
        $thumbnailer = new ProductThumbnailer($this->storage, new NullLogger());

        $written = $thumbnailer->generate('big.jpg');

        self::assertSame(['thumbs/400/big.webp', 'thumbs/800/big.webp'], $written);
        [$w, $h] = getimagesizefromstring($this->storage->read('thumbs/400/big.webp'));
        self::assertSame([400, 300], [$w, $h]);
        // Second run: nothing to do.
        self::assertSame([], $thumbnailer->generate('big.jpg'));
        self::assertFalse($thumbnailer->supports('thumbs/400/big.webp'));
        self::assertFalse($thumbnailer->supports('doc.pdf'));
    }

    public function testSmallOriginalIsNotUpscaled(): void
    {
        $img = imagecreatetruecolor(300, 300);
        ob_start();
        imagepng($img);
        $this->storage->write('small.png', (string) ob_get_clean());

        (new ProductThumbnailer($this->storage, new NullLogger()))->generate('small.png');

        [$w] = getimagesizefromstring($this->storage->read('thumbs/800/small.webp'));
        self::assertSame(300, $w);
    }

    public function testBrokenImageIsSkippedQuietly(): void
    {
        $this->storage->write('broken.jpg', 'not an image');

        self::assertSame([], (new ProductThumbnailer($this->storage, new NullLogger()))->generate('broken.jpg'));
    }
}
