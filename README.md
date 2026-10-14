# PHP Markdown Lite

A small, **dependency-free** Markdown-to-HTML renderer for PHP. One class, no Composer, no extensions required. Handy for rendering changelogs, docs pages, or user-supplied text safely.

## Usage

```php
require 'src/MarkdownLite.php';

echo MarkdownLite::toHtml( file_get_contents( 'CHANGELOG.md' ) );
```

Or run the bundled demo:

```bash
php examples/markdown-example.php > sample.html
```

## Supported syntax

| Syntax | Output |
|---|---|
| `# … ######` | `<h1>`–`<h6>` |
| `**bold**`, `__bold__` | `<strong>` |
| `*italic*`, `_italic_` | `<em>` |
| `~~struck~~` | `<del>` |
| `` `code` `` | `<code>` |
| ` ```lang … ``` ` | `<pre><code class="language-lang">` |
| `[text](url "title")` | `<a href>` |
| `![alt](src "title")` | `<img>` |
| `- item` / `1. item` | `<ul>` / `<ol>` (single level) |
| `> quote` | `<blockquote>` (recursive, nestable formatting) |
| `\| a \| b \|` + separator row | `<table>` with `:---`, `---:`, `:---:` alignment |
| `---` / `***` | `<hr>` |
| blank-line-separated text | `<p>` |

## Security model

1. The entire input is run through `htmlspecialchars()` **before** any Markdown parsing, so raw `<script>` or `<iframe>` tags in user input render as inert text.
2. Link and image URLs are allow-listed to `http:`, `https:`, `mailto:`, protocol-relative (`//`), root-relative (`/`), and page anchors (`#`). Images additionally allow `data:image/*;base64,`. Anything else (e.g. `javascript:`) degrades to plain text.
3. Fenced and inline code contents are stashed before inline formatting runs, so backtick code can never break out into markup.

## Notes

- Single-level lists and one code-fence style are deliberate scope limits — this is a "lite" renderer, not a CommonMark implementation.
- The blockquote renderer recurses, so formatting, lists, and code fences work inside quotes.

MIT licensed. Contributions welcome.
