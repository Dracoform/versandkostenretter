<?php

declare(strict_types=1);

/**
 * Minimal test runner (no framework, no external dependencies).
 * Usage: php tests/run.php
 */

require __DIR__ . '/../src/Money.php';
require __DIR__ . '/../src/Cart.php';
require __DIR__ . '/../src/View.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/OutboundLink.php';
require __DIR__ . '/../src/CategoryFilter.php';

final class TestRunner
{
    public int $pass = 0;
    public int $fail = 0;
    /** @var string[] */
    public array $messages = [];

    public function check(string $name, mixed $actual, mixed $expected): void
    {
        if ($actual === $expected) {
            $this->pass++;
            $this->messages[] = "PASS  {$name}";
        } else {
            $this->fail++;
            $this->messages[] = sprintf(
                "FAIL  %s\n      expected: %s\n      actual:   %s",
                $name,
                var_export($expected, true),
                var_export($actual, true)
            );
        }
    }

    public function assertTrue(string $name, bool $cond): void
    {
        $this->check($name, $cond, true);
    }

    public function report(): int
    {
        echo implode("\n", $this->messages), "\n\n";
        printf("Tests: %d, Failures: %d\n", $this->pass + $this->fail, $this->fail);
        return $this->fail === 0 ? 0 : 1;
    }
}

$t = new TestRunner();

use Versandkostenretter\Cart;
use Versandkostenretter\Database;
use Versandkostenretter\Money;
use Versandkostenretter\View;

/* =============================================================
 * Money parsing — decimal/money handling (integer cents only)
 * ============================================================= */
$t->check('parse "125.34" = 12534', Money::parseToCents('125.34'), 12534);
$t->check('parse "150.00" = 15000', Money::parseToCents('150.00'), 15000);
$t->check('parse "24.66" = 2466',   Money::parseToCents('24.66'), 2466);
$t->check('parse "24,66" (comma) = 2466', Money::parseToCents('24,66'), 2466);
$t->check('parse " 24.66 " (ws) = 2466', Money::parseToCents(' 24.66 '), 2466);
$t->check('parse "24.6" = 2460', Money::parseToCents('24.6'), 2460);
$t->check('parse "24" = 2400', Money::parseToCents('24'), 2400);
$t->check('parse "€ 24,99" = 2499', Money::parseToCents('€ 24,99'), 2499);
$t->check('parse "1.234,56" (de grouping) = 123456', Money::parseToCents('1.234,56'), 123456);
$t->check('parse "1,234.56" (en grouping) = 123456', Money::parseToCents('1,234.56'), 123456);
$t->check('parse "" = null', Money::parseToCents(''), null);
$t->check('parse "abc" = null', Money::parseToCents('abc'), null);
$t->check('parse "-5" = null (no negatives)', Money::parseToCents('-5'), null);
$t->check('parse "1.2.3" = null', Money::parseToCents('1.2.3'), null);
$t->check('parse "24.666" (3 decimals) = null', Money::parseToCents('24.666'), null);

/* classic floating point pitfall: 0.1 + 0.2 style */
$t->check('parse "0.30" = 30', Money::parseToCents('0.30'), 30);
$t->check('parse "0.1" = 10', Money::parseToCents('0.1'), 10);

/* round-trip */
$t->check('centsToDecimal(2466) = "24.66"', Money::centsToDecimal(2466), '24.66');
$t->check('centsToDecimal(0) = "0.00"', Money::centsToDecimal(0), '0.00');
$t->check('centsToDecimal(15000) = "150.00"', Money::centsToDecimal(15000), '150.00');
$t->check('centsToDecimal(-499) = "-4.99"', Money::centsToDecimal(-499), '-4.99');
$t->check('formatEuro(12534) = "125,34 €"', Money::formatEuro(12534), "125,34\xE2\x80\xAF€");
$t->check('formatEuro(150000) = "1.500,00 €"', Money::formatEuro(150000), "1.500,00\xE2\x80\xAF€");
$t->check('formatEuro(-599) = "-5,99 €"', Money::formatEuro(-599), "-5,99\xE2\x80\xAF€");

/* =============================================================
 * Core scenario: cart 125.34, threshold 150.00 -> missing 24.66
 * ============================================================= */
$cart = Money::parseToCents('125.34');
$threshold = Money::parseToCents('150.00');
$missing = Cart::missingCents($cart, $threshold);
$t->check('125.34 / 150.00 -> missing 24.66 (2466 cents)', $missing, 2466);
unset($t->assertFalse);

$t->check('threshold NOT reached at 125.34', Cart::thresholdReached($cart, $threshold), false);
$t->check('threshold reached at exactly 150.00', Cart::thresholdReached($threshold, $threshold), true);
$t->check('threshold reached above 150.00', Cart::thresholdReached($threshold + 1, $threshold), true);
$t->check('missing = 0 when reached', Cart::missingCents($threshold + 1, $threshold), 0);
$t->check('missing = 0 when exactly reached', Cart::missingCents($threshold, $threshold), 0);

/* =============================================================
 * Qualification rules — money strictness
 * ============================================================= */
// 24.49 must NOT qualify (price < missing 24.66)
$t->check('24.49 does NOT qualify for missing 24.66',
    Cart::qualifies(Money::parseToCents('24.49'), $missing, true, 1, 1), false);
// 24.66 qualifies (>= boundary, strict int comparison)
$t->check('24.66 qualifies (boundary)',
    Cart::qualifies(Money::parseToCents('24.66'), $missing, true, 1, 1), true);
// 24.69 qualifies
$t->check('24.69 qualifies',
    Cart::qualifies(Money::parseToCents('24.69'), $missing, true, 1, 1), true);
// 24.65 must not (just below)
$t->check('24.65 does NOT qualify',
    Cart::qualifies(Money::parseToCents('24.65'), $missing, true, 1, 1), false);
// unavailable product at qualifying price must NOT appear
$t->check('unavailable product never qualifies',
    Cart::qualifies(Money::parseToCents('24.70'), $missing, false, 1, 1), false);
// product from ANOTHER shop must NEVER appear
$t->check('other-shop product never qualifies',
    Cart::qualifies(Money::parseToCents('24.66'), $missing, true, 2, 1), false);

/* =============================================================
 * Effective extra cost
 * ============================================================= */
$t->check('effective: 24.99 - 5.99 shipping = 19.00',
    Cart::effectiveExtraCostCents(Money::parseToCents('24.99'), Money::parseToCents('5.99')), 1900);
$t->check('effective can be negative: 2.00 - 5.99 = -3.99',
    Cart::effectiveExtraCostCents(200, 599), -399);

/* =============================================================
 * View escaping / URL safety (XSS)
 * ============================================================= */
$t->check('e() escapes quotes', View::e('<a href="x">'), '&lt;a href=&quot;x&quot;&gt;');
$t->check('safeUrl drops javascript:', View::safeUrl('javascript:alert(1)'), null);
$t->check('safeUrl drops data:', View::safeUrl('data:text/html,<b>'), null);
$t->check('safeUrl keeps https', View::safeUrl('https://example.com/p/1'), 'https://example.com/p/1');
$t->check('safeUrl keeps http', View::safeUrl('http://example.com'), 'http://example.com');
$t->check('safeUrl drops relative url', View::safeUrl('/foo'), null);
$t->check('safeUrl drops empty', View::safeUrl(''), null);
$t->check('safeUrl drops null', View::safeUrl(null), null);
$t->check('safeUrl drops " javascript:alert(1)" with leading space', View::safeUrl(' javascript:alert(1)'), null);
$t->check('safeUrl encodes interior spaces instead of dropping them', View::safeUrl('https://shop.example/p/a b'), 'https://shop.example/p/a%20b');
$t->check('safeUrl rejects newline injection (CRLF in URL)', View::safeUrl("https://shop.example/p\r\nx"), null);
$t->check('safeUrl rejects tab injection', View::safeUrl("https://shop.example/\tp/x"), null);
$t->check('safeUrl rejects null byte', View::safeUrl("https://shop.example/\0x"), null);

/* =============================================================
 * Database guardrail: only VSKR_ tables referenced
 * ============================================================= */
$t->check('guardrail: VSKR_shops query passes',
    Database::assertOnlyVskrTables('SELECT id FROM VSKR_shops WHERE active = 1'), []);
$t->check('guardrail: joined VSKR query passes',
    Database::assertOnlyVskrTables('SELECT * FROM VSKR_products p JOIN VSKR_shops s ON p.shop_id = s.id'), []);
$t->check('guardrail: non-VSKR table flagged',
    Database::assertOnlyVskrTables('SELECT * FROM shops'), ['shops']);
$t->check('guardrail: mixed violation flagged',
    Database::assertOnlyVskrTables('SELECT * FROM users JOIN VSKR_shops ON 1=1'), ['users']);


/* =============================================================
 * OutboundLink — affiliate future-proofing (disabled by default)
 * ============================================================= */
use Versandkostenretter\OutboundLink;

$canonical = 'https://shop.example/product/123';

// disabled (null config) -> canonical URL unchanged, not flagged affiliate
$t->check('affiliate disabled (null) -> canonical URL unchanged',
    OutboundLink::build($canonical, null), ['url' => $canonical, 'is_affiliate' => false]);
// explicit disabled
$t->check('affiliate disabled (enabled=false) -> canonical URL unchanged',
    OutboundLink::build($canonical, ['enabled' => false, 'mode' => 'query', 'param' => 'ref', 'value' => 'x']),
    ['url' => $canonical, 'is_affiliate' => false]);

// query mode: parameter added
$t->check('affiliate query mode adds parameter',
    OutboundLink::build($canonical, ['enabled' => true, 'mode' => 'query', 'param' => 'ref', 'value' => 'example']),
    ['url' => 'https://shop.example/product/123?ref=example', 'is_affiliate' => true]);

// query mode: existing query string preserved
$t->check('affiliate query mode preserves existing query params',
    OutboundLink::build('https://shop.example/product/123?foo=bar', ['enabled' => true, 'mode' => 'query', 'param' => 'ref', 'value' => 'example']),
    ['url' => 'https://shop.example/product/123?foo=bar&ref=example', 'is_affiliate' => true]);

// existing param of same name is not duplicated/overwritten
$t->check('affiliate query mode does not duplicate existing param',
    OutboundLink::build('https://shop.example/product/123?ref=merchant', ['enabled' => true, 'mode' => 'query', 'param' => 'ref', 'value' => 'example']),
    ['url' => 'https://shop.example/product/123?ref=merchant', 'is_affiliate' => true]);

// URL-encoding safety: special chars in value get encoded, original query intact
$t->check('affiliate query mode encodes safely',
    OutboundLink::build('https://shop.example/p/a b?x=1 2', ['enabled' => true, 'mode' => 'query', 'param' => 'ref', 'value' => 'a&b=c']),
    ['url' => 'https://shop.example/p/a%20b?x=1%202&ref=a%26b%3Dc', 'is_affiliate' => true]);

// template mode: encoded canonical URL substituted
$t->check('affiliate template mode substitutes encoded URL',
    OutboundLink::build($canonical, ['enabled' => true, 'mode' => 'template', 'template' => 'https://aff.example/go?url={url}&id=1']),
    ['url' => 'https://aff.example/go?url=https%3A%2F%2Fshop.example%2Fproduct%2F123&id=1', 'is_affiliate' => true]);

// template without placeholder -> fail safe to canonical, not affiliate
$t->check('affiliate template without {url} placeholder -> canonical fallback',
    OutboundLink::build($canonical, ['enabled' => true, 'mode' => 'template', 'template' => 'https://aff.example/go']),
    ['url' => $canonical, 'is_affiliate' => false]);

// unknown mode -> disabled
$t->check('affiliate unknown mode -> canonical fallback',
    OutboundLink::build($canonical, ['enabled' => true, 'mode' => 'weird']),
    ['url' => $canonical, 'is_affiliate' => false]);

// unsafe canonical URL is rejected even with affiliate config
$unsafeThrown = false;
try {
    OutboundLink::build('javascript:alert(1)', ['enabled' => true, 'mode' => 'query', 'param' => 'ref', 'value' => 'x']);
} catch (RuntimeException $e) {
    $unsafeThrown = true;
}
$t->assertTrue('affiliate builder rejects unsafe canonical URL', $unsafeThrown);

// template producing an unsafe URL -> canonical fallback
$t->check('affiliate template producing unsafe URL -> canonical fallback',
    OutboundLink::build($canonical, ['enabled' => true, 'mode' => 'template', 'template' => 'javascript:{url}']),
    ['url' => $canonical, 'is_affiliate' => false]);

// per-shop isolation: configFromShopRow only reads that shop row
$t->check('configFromShopRow: disabled row -> null',
    OutboundLink::configFromShopRow(['affiliate_enabled' => 0, 'affiliate_mode' => 'query']), null);
$t->check('configFromShopRow: enabled row -> config of THAT row only',
    OutboundLink::configFromShopRow(['affiliate_enabled' => 1, 'affiliate_mode' => 'query', 'affiliate_param' => 'ref', 'affiliate_value' => 'shopA', 'affiliate_template' => null]),
    ['enabled' => true, 'mode' => 'query', 'param' => 'ref', 'value' => 'shopA', 'template' => null]);
$t->check('configFromShopRow: shop B config independent of shop A',
    OutboundLink::configFromShopRow(['affiliate_enabled' => 1, 'affiliate_mode' => 'template', 'affiliate_param' => null, 'affiliate_value' => null, 'affiliate_template' => 'https://t.example/?u={url}']),
    ['enabled' => true, 'mode' => 'template', 'param' => null, 'value' => null, 'template' => 'https://t.example/?u={url}']);

// canonical VSKR_products.url is never modified by link generation
$original = 'https://shop.example/product/123?foo=bar';
$copy = $original;
OutboundLink::build($copy, ['enabled' => true, 'mode' => 'query', 'param' => 'ref', 'value' => 'example']);
$t->check('link generation does not mutate the canonical URL string', $copy, $original);



/* =============================================================
 * CategoryFilter — opportunistic, shop-local filtering
 * ============================================================= */
use Versandkostenretter\CategoryFilter;

// no categories -> no usable choices
$t->check('category: all-null categories -> no filter choices',
    CategoryFilter::distinct([
        ['category' => null], ['category' => null],
    ]), []);
$t->check('category: empty-string categories -> no filter choices',
    CategoryFilter::distinct([['category' => ''], ['category' => '   ']]), []);
$t->check('category: mixed null/valid -> only valid kept',
    CategoryFilter::distinct([['category' => null], ['category' => 'Farben'], ['category' => '']]),
    ['Farben']);

// distinct + stable order (price-ascending first appearance)
$t->check('category: distinct values, first-appearance order',
    CategoryFilter::distinct([
        ['category' => 'Erweiterung'], ['category' => 'Zubehör'],
        ['category' => 'Erweiterung'], ['category' => 'Brettspiel'],
    ]), ['Erweiterung', 'Zubehör', 'Brettspiel']);

// usability: valid, empty, unknown, over-long
$t->check('category: usable -> true', CategoryFilter::isUsable(['Farben', 'Werkzeug'], 'Farben'), true);
$t->check('category: empty request -> not usable', CategoryFilter::isUsable(['Farben'], ''), false);
$t->check('category: null request -> not usable', CategoryFilter::isUsable(['Farben'], null), false);
$t->check('category: unknown category -> not usable (graceful fallback)', CategoryFilter::isUsable(['Farben'], 'NichtVorhanden'), false);
$t->check('category: over-long request -> not usable', CategoryFilter::isUsable(['x'], str_repeat('a', 191)), false);
$t->check('category: exact string match required (no normalization)', CategoryFilter::isUsable(['Warhammer 40.000'], 'Warhammer 40K'), false);


exit($t->report());
