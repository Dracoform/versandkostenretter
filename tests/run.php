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

exit($t->report());
