<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WordPress;

use InvalidArgumentException;
use RuntimeException;
use Xmods\CommerceDocuments\Contracts\Mailer;
use Xmods\CommerceDocuments\DocumentSnapshot;

/** Writes .eml files locally; it never calls wp_mail or opens a network connection. */
final class SandboxMailer implements Mailer
{
    /** @var string */
    private $directory;

    public function __construct(string $directory)
    {
        $directory = rtrim($directory, "\\/");
        if ($directory === '' || (!is_dir($directory) && !@mkdir($directory, 0700, true))) {
            throw new RuntimeException('Sandbox mail directory is unavailable.');
        }
        if (!is_writable($directory)) {
            throw new RuntimeException('Sandbox mail directory is not writable.');
        }
        $this->directory = $directory;
    }

    public function send(
        DocumentSnapshot $snapshot,
        string $recipient,
        string $subject,
        string $message,
        string $pdfBinary
    ): void {
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/[\r\n]/', $subject . $message)
        ) {
            throw new InvalidArgumentException('Sandbox email headers or recipient are invalid.');
        }
        $id = (string) ($snapshot->toArray()['document_id'] ?? 'document');
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $id)) {
            throw new InvalidArgumentException('Document identifier is invalid.');
        }
        $filename = $this->directory . DIRECTORY_SEPARATOR . $id . '-' . gmdate('YmdHis') . '.eml';
        $boundary = 'cdk-' . bin2hex(random_bytes(12));
        $encodedPdf = chunk_split(base64_encode($pdfBinary));
        $eml = 'To: ' . $recipient . "\r\n"
            . 'Subject: ' . $subject . "\r\n"
            . 'Content-Type: multipart/mixed; boundary="' . $boundary . "\"\r\n\r\n"
            . '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n"
            . $message . "\r\n\r\n--" . $boundary . "\r\n"
            . "Content-Type: application/pdf\r\nContent-Transfer-Encoding: base64\r\n"
            . 'Content-Disposition: attachment; filename="' . $id . '.pdf"' . "\r\n\r\n"
            . $encodedPdf . '--' . $boundary . "--\r\n";
        if (file_put_contents($filename, $eml, LOCK_EX) === false) {
            throw new RuntimeException('Sandbox email could not be written.');
        }
    }
}
