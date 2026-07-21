<?php

namespace App\Factory;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

final class MarkdownConverterFactory
{
    public static function create(): MarkdownConverter
    {
        // Everything rendered through markdown_to_html is model- or user-authored
        // (descriptions extracted from uploaded PDFs, chat output…). Raw HTML
        // embedded in that markdown must NEVER reach the page: a hostile PDF that
        // prompt-injects the extractor into emitting <script> would otherwise be
        // stored XSS. League's default html_input is 'allow' — override it.
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());

        return new MarkdownConverter($environment);
    }
}
