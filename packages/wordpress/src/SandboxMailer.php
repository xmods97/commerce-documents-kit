<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WordPress;

use InvalidArgumentException;
use RuntimeException;
use Xmods\CommerceDocuments\Contracts\Mailer;
use Xmods\CommerceDocuments\DocumentSnapshot;

/**
 * Writes RFC 2045 .eml files to a local directory.
 *
 * It never calls wp_mail, never opens a socket and has no transport. This is the
 * only Mailer wired anywhere, so no message can leave the machine.
 */
final class SandboxMailer implements Mailer
{
    /** @var string */
    private $directory;
    /** @var string */
    private $from;

    public function __construct(string $directory, string $from = 'sandbox@localhost')
    {
        if (self::hasHeaderBreak($from) || filter_var(self::addressOf($from), FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Sandbox sender address is invalid.');
        }
        $directory = rtrim($directory, "\\/");
        if ($directory === '' || (!is_dir($directory) && !@mkdir($directory, 0700, true))) {
            throw new RuntimeException('Sandbox mail directory is unavailable.');
        }
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('Sandbox mail directory is not writable.');
        }
        $this->directory = $directory;
        $this->from = $from;
    }

    public function send(
        DocumentSnapshot $snapshot,
        string $recipient,
        string $subject,
        string $message,
        string $pdfBinary
    ): void {
        // Header fields must not contain a line break. The body may — folding a
        // multi-line body into a single line was rejecting legitimate messages.
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false || self::hasHeaderBreak($subject)) {
            throw new InvalidArgumentException('Sandbox email headers or recipient are invalid.');
        }
        $id = (string) ($snapshot->toArray()['document_id'] ?? 'document');
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $id)) {
            throw new InvalidArgumentException('Document identifier is invalid.');
        }

        $boundary = 'cdk-' . bin2hex(random_bytes(12));
        $eml = 'MIME-Version: 1.0' . "\r\n"
            . 'Date: ' . gmdate('D, d M Y H:i:s') . " +0000\r\n"
            . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@commerce-documents.local>' . "\r\n"
            . 'From: ' . $this->from . "\r\n"
            . 'To: ' . $recipient . "\r\n"
            . 'Subject: ' . self::encodeHeader($subject) . "\r\n"
            . 'Content-Type: multipart/mixed; boundary="' . $boundary . "\"\r\n\r\n"
            . 'This is a multi-part message in MIME format.' . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode(self::normalizeBody($message)))
            . '--' . $boundary . "\r\n"
            . "Content-Type: application/pdf\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . 'Content-Disposition: attachment; filename="' . $id . '.pdf"' . "\r\n\r\n"
            . chunk_split(base64_encode($pdfBinary))
            . '--' . $boundary . "--\r\n";

        $this->writeExclusively($id, $eml);
    }

    /**
     * Creates the file with O_EXCL so an existing file is never overwritten and a
     * pre-created symlink cannot capture the write. The random suffix means two
     * sends in the same second produce two files rather than one.
     */
    private function writeExclusively(string $id, string $contents): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $path = $this->directory . DIRECTORY_SEPARATOR
                . $id . '-' . gmdate('Ymd\THis') . '-' . bin2hex(random_bytes(6)) . '.eml';
            $handle = @fopen($path, 'xb');
            if ($handle === false) {
                continue;
            }
            // Restrict before writing: the file carries the recipient address, the
            // buyer's name and the rendered document.
            @chmod($path, 0600);
            $written = fwrite($handle, $contents);
            fclose($handle);
            if ($written === false) {
                throw new RuntimeException('Sandbox email could not be written.');
            }
            return;
        }

        throw new RuntimeException('Sandbox email could not be written.');
    }

    private static function normalizeBody(string $body): string
    {
        return (string) preg_replace('/\r\n|\r|\n/', "\r\n", $body);
    }

    private static function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/D', $value) === 1) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private static function hasHeaderBreak(string $value): bool
    {
        return preg_match('/[\r\n]/', $value) === 1;
    }

    private static function addressOf(string $from): string
    {
        if (preg_match('/<([^>]+)>\s*$/D', $from, $match) === 1) {
            return $match[1];
        }
        return trim($from);
    }
}
