<?php
declare(strict_types=1);
$root = $argv[1] ?? '';
$dom = new DOMDocument();
libxml_use_internal_errors(true);
if (!$dom->loadHTML(file_get_contents($root . '/cart.html'))) throw new RuntimeException('Cart HTML did not parse.');
$xpath = new DOMXPath($dom);
$cards = $xpath->query('//section[@data-cart-store]');
if ($cards->length !== 2) throw new RuntimeException('Expected two separate store cards.');
$ids = [];
foreach ($xpath->query('//*[@id]') as $node) {
    $id = $node->getAttribute('id');
    if (isset($ids[$id])) throw new RuntimeException('Duplicate element ID in grouped cart.');
    $ids[$id] = true;
}
foreach ($cards as $card) {
    $store = $card->getAttribute('data-cart-store');
    if ($xpath->query('.//a[@href="checkout.php?store=' . $store . '"]', $card)->length !== 1) throw new RuntimeException('Store checkout link escaped its card.');
    if ($xpath->query('.//details[@data-store-details="' . $store . '"]/summary', $card)->length !== 1) throw new RuntimeException('Missing accessible per-store item expander.');
    if ($xpath->query('.//form[input[@name="action" and @value="clear_store"]]/input[@name="store_id" and @value="' . $store . '"]', $card)->length !== 1) throw new RuntimeException('Store removal form is not scoped correctly.');
}
foreach ($xpath->query('//link[@rel="stylesheet"]/@href|//script[@src]/@src') as $asset) {
    $file = explode('?', $asset->nodeValue)[0];
    if (!is_file($root . '/' . $file)) throw new RuntimeException('Missing synthetic preview asset.');
}
echo "PASS: two independent cart cards, unique IDs, accessible expanders, scoped forms and local assets.\n";
