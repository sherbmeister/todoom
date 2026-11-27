<?php
/** Inline donation link; footer structure and layout stay owned by each app. */
function app_coffee_support_html(string $appName): string
{
    $url = 'https://www.paypal.com/donate/?' . http_build_query([
        'business' => 'mariusnicolaeserbanica@gmail.com',
        'currency_code' => 'GBP',
        'item_name' => 'Support ' . $appName . ' development',
    ], '', '&', PHP_QUERY_RFC3986);
    $href = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    return '<a class="app-coffee-button" href="' . $href . '" target="_blank" rel="noopener noreferrer external" title="Support the developer with a PayPal donation"><span class="app-coffee-cup" aria-hidden="true">☕</span><span>Buy me a coffee</span></a>';
}
