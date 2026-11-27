<?php
/** Shared, dependency-free donation link for web and packaged clients. */
function app_coffee_support_html(string $appName, string $stylesheet = 'assets/support.css?v=20261127-footer2'): string
{
    $url = 'https://www.paypal.com/donate/?' . http_build_query([
        'business' => 'mariusnicolaeserbanica@gmail.com',
        'currency_code' => 'GBP',
        'item_name' => 'Support ' . $appName . ' development',
    ], '', '&', PHP_QUERY_RFC3986);
    $href = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $cssHref = htmlspecialchars($stylesheet, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<link rel="stylesheet" href="$cssHref">
<div class="app-coffee-support">
  <a class="app-coffee-button" href="$href" target="_blank" rel="noopener noreferrer external" title="Support the developer with a PayPal donation">
    <span class="app-coffee-cup" aria-hidden="true">☕</span><span>Buy me a coffee</span>
  </a>
</div>
HTML;
}
