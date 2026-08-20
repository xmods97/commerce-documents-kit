<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Xmods\CommerceDocuments\WordPress\SandboxMailer;

/**
 * Where a sandbox capture is allowed to live.
 *
 * A `.eml` holds the buyer's name, their email address and the whole rendered
 * document. The capture directory comes from a wp-config constant, so an
 * operator can point it anywhere — including somewhere the web server serves.
 * The mailer refuses that rather than relying on a deny file only some servers
 * honour.
 *
 * ABSPATH is a constant and cannot be undefined once set, so each case runs in
 * its own process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SandboxMailerLocationTest extends TestCase
{
    /** @var string[] */
    private $created = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $path) {
            if (is_dir($path)) {
                foreach (glob($path . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($path);
            }
        }
        $this->created = [];
    }

    public function testACaptureDirectoryInsideTheWebRootIsRefused(): void
    {
        $root = $this->webRoot();
        $inside = $root . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'sandbox-mail';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/outside the web root/');
        new SandboxMailer($inside);
    }

    public function testTheWebRootItselfIsRefused(): void
    {
        $root = $this->webRoot();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/outside the web root/');
        new SandboxMailer($root);
    }

    public function testATraversalPathResolvingBackInsideTheWebRootIsRefused(): void
    {
        $root = $this->webRoot();
        $traversal = $root . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . '..'
            . DIRECTORY_SEPARATOR . 'escaped';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/outside the web root/');
        new SandboxMailer($traversal);
    }

    public function testADirectoryOutsideTheWebRootIsAccepted(): void
    {
        $this->webRoot();
        $outside = $this->scratch('outside');

        $mailer = new SandboxMailer($outside);

        self::assertInstanceOf(SandboxMailer::class, $mailer);
    }

    public function testWithoutAWordPressContextTheGuardStandsAside(): void
    {
        // No ABSPATH: a command line or a test run, where nothing is served.
        self::assertFalse(defined('ABSPATH'));

        $mailer = new SandboxMailer($this->scratch('cli'));

        self::assertInstanceOf(SandboxMailer::class, $mailer);
    }

    public function testTheDefaultSenderIsANonRoutableAddress(): void
    {
        $reflection = new \ReflectionMethod(SandboxMailer::class, '__construct');
        $default = $reflection->getParameters()[1]->getDefaultValue();

        self::assertSame('sandbox@example.invalid', $default);
        // .invalid is reserved by RFC 2606 and can never resolve, so a capture
        // that escaped into a real mail path still could not be delivered.
        self::assertStringEndsWith('@example.invalid', $default);
    }

    public function testRetentionRemovesOnlyExpiredOwnedCaptures(): void
    {
        $directory = $this->scratch('retention');
        $mailer = new SandboxMailer($directory);
        $expired = $directory . DIRECTORY_SEPARATOR . 'doc_old-20200101T000000-aaaaaaaaaaaa.eml';
        $recent = $directory . DIRECTORY_SEPARATOR . 'doc_recent-20200101T000000-bbbbbbbbbbbb.eml';
        $foreign = $directory . DIRECTORY_SEPARATOR . 'operator-note.eml';
        file_put_contents($expired, 'expired');
        file_put_contents($recent, 'recent');
        file_put_contents($foreign, 'foreign');
        touch($expired, time() - (8 * 86400));
        touch($recent, time() - (2 * 86400));
        touch($foreign, time() - (30 * 86400));

        self::assertSame(1, $mailer->purgeExpired(7));
        self::assertFileDoesNotExist($expired);
        self::assertFileExists($recent);
        self::assertFileExists($foreign);
    }

    public function testRetentionWindowMustBeBounded(): void
    {
        $mailer = new SandboxMailer($this->scratch('retention-bounds'));

        $this->expectException(InvalidArgumentException::class);
        $mailer->purgeExpired(0);
    }

    /** Creates a throwaway web root and points ABSPATH at it. */
    private function webRoot(): string
    {
        $root = $this->scratch('webroot');
        if (!is_dir($root . DIRECTORY_SEPARATOR . 'wp-content')) {
            mkdir($root . DIRECTORY_SEPARATOR . 'wp-content', 0700, true);
            $this->created[] = $root . DIRECTORY_SEPARATOR . 'wp-content';
        }
        if (!defined('ABSPATH')) {
            define('ABSPATH', $root . DIRECTORY_SEPARATOR);
        }
        return $root;
    }

    private function scratch(string $name): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'cdk-sandbox-' . $name . '-' . bin2hex(random_bytes(6));
        mkdir($path, 0700, true);
        $this->created[] = $path;
        return $path;
    }
}
