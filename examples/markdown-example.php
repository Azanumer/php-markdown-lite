<?php
/**
 * MarkdownLite usage example.
 *
 * Run: php examples/markdown-example.php > sample.html
 */

require __DIR__ . '/../src/MarkdownLite.php';

$markdown = <<<'MD'
# MarkdownLite demo

This is **bold**, *italic*, ~~struck~~, and `inline code`.

## Links and images

[GitHub](https://github.com) — [unsafe scheme](javascript:alert(1)) renders as plain text.

![Logo](/assets/logo.png "Company logo")

## A list

- First item
- Second item with **bold**

1. Numbered one
2. Numbered two

> A blockquote
> with *two* lines.

| Name  | Price | In stock |
|:------|------:|:--------:|
| Widget|  9.99 |   yes    |
| Gadget| 19.99 |    no    |

```php
echo 'fenced code block';
```

<script>alert('XSS')</script> renders as harmless text.
MD;

$html = MarkdownLite::toHtml( $markdown );

if ( php_sapi_name() === 'cli' ) {
    echo $html;
} else {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>MarkdownLite demo</title></head><body>'
        . $html . '</body></html>';
}
