<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Unit;

use Desertica\Billing\Storage\FileStorage;
use PHPUnit\Framework\TestCase;

final class FileStoragePermissionsTest extends TestCase
{
    public function testFilesAreOwnerOnlyAndDirectoriesAreNotWorldAccessible(): void
    {
        $base = sys_get_temp_dir() . '/billing-perm-' . bin2hex(random_bytes(4));
        $previous = umask(0);

        try {
            (new FileStorage($base))->write('abc', FileStorage::XML, '<x/>');
            clearstatcache();

            self::assertSame(0600, fileperms($base . '/abc/document.xml') & 0777);
            self::assertSame(0750, fileperms($base . '/abc') & 0777);
        } finally {
            umask($previous);
            @unlink($base . '/abc/document.xml');
            @rmdir($base . '/abc');
            @rmdir($base);
        }
    }
}
