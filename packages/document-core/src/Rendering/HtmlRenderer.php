<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Rendering;

use Xmods\CommerceDocuments\DocumentSnapshot;

final class HtmlRenderer
{
    /** @var TemplateCatalog */
    private $catalog;

    public function __construct(TemplateCatalog $catalog)
    {
        $this->catalog = $catalog;
    }

    public function render(DocumentSnapshot $snapshot): string
    {
        $data = $snapshot->toArray();
        $labels = $this->catalog->labels($data['language']);
        $rows = '';
        foreach ($data['items'] as $item) {
            $rows .= '<tr><td>' . self::escape($item['description']) . '</td>'
                . '<td>' . self::escape($item['quantity']['scaled_units'] . '@' . $item['quantity']['scale']) . '</td>'
                . '<td>' . self::escape($item['net']) . '</td>'
                . '<td>' . self::escape($item['tax']) . '</td>'
                . '<td>' . self::escape($item['gross']) . '</td></tr>';
        }

        return '<!doctype html><html lang="' . self::escape($data['language']) . '"><head>'
            . '<meta charset="utf-8"><title>' . self::escape($data['document_number']) . '</title></head><body>'
            . '<h1>' . self::escape(strtoupper($data['document_type']) . ' ' . $data['document_number']) . '</h1>'
            . '<section><h2>' . self::escape($labels['seller']) . '</h2><p>'
            . self::escape($data['seller']['name']) . '</p></section>'
            . '<section><h2>' . self::escape($labels['buyer']) . '</h2><p>'
            . self::escape($data['buyer']['name']) . '</p></section>'
            . '<table><thead><tr><th>' . self::escape($labels['description']) . '</th><th>'
            . self::escape($labels['quantity']) . '</th><th>' . self::escape($labels['net'])
            . '</th><th>' . self::escape($labels['tax']) . '</th><th>'
            . self::escape($labels['gross']) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<p data-currency="' . self::escape($data['currency']) . '">'
            . self::escape($labels['gross']) . ': ' . self::escape($data['totals']['gross']) . '</p>'
            . '</body></html>';
    }

    private static function escape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
