<?php

/**
 * Results page.
 *
 * @var array|null $shop            selected shop (id, name, slug, shipping/threshold cents, affiliate)
 * @var int|null   $cartCents       current cart value in cents
 * @var string     $cartRaw         raw cart value as submitted (for stateless forms)
 * @var array      $errors          validation errors
 * @var array|null $results         ['free_reached'=>bool, 'products'=>[], 'total'=>int, 'missing_cents'=>int]
 * @var array      $shops           all active shops (for the form)
 * @var string     $pageTitle
 * @var int        $maxResults
 * @var array      $categories      distinct categories of the eligible set
 * @var string|null $selectedCategory
 * @var bool       $expanded        price-window mode: true = "Mehr Auswahl anzeigen"
 */

use Versandkostenretter\Money;
use Versandkostenretter\OutboundLink;
use Versandkostenretter\View;

require __DIR__ . '/layout_header.php';
?>

<section class="hero hero-small">
  <h1 class="hero-title">Ergebnis</h1>
</section>

<?php if ($errors !== []): ?>
  <section class="notice notice-error" role="alert">
    <ul>
      <?php foreach ($errors as $err): ?>
        <li><?= View::e($err) ?></li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<?php if ($shop !== null && $cartCents !== null): ?>
<section class="card summary-card" aria-label="Zusammenfassung">
  <dl class="summary-grid">
    <div class="summary-item">
      <dt>Shop</dt>
      <dd><?= View::e($shop['name']) ?></dd>
    </div>
    <div class="summary-item">
      <dt>Aktueller Warenkorb</dt>
      <dd><?= Money::formatEuro($cartCents) ?></dd>
    </div>
    <div class="summary-item">
      <dt>Gratisversand ab</dt>
      <dd><?= Money::formatEuro($shop['free_shipping_threshold_cents']) ?></dd>
    </div>
    <div class="summary-item">
      <dt>Standardversand</dt>
      <dd><?= Money::formatEuro($shop['shipping_cost_cents']) ?></dd>
    </div>
    <div class="summary-item summary-highlight">
      <dt>Dir fehlen</dt>
      <dd><?= Money::formatEuro(max(0, $shop['free_shipping_threshold_cents'] - $cartCents)) ?></dd>
    </div>
  </dl>
</section>
<?php endif; ?>

<?php if ($results !== null && ($results['free_reached'] ?? false)): ?>
  <section class="notice notice-success" role="status">
    <p>🎉 <strong>Glückwunsch!</strong> Dein Warenkorb hat den Gratisversand bereits erreicht.
       Du musst nichts mehr nachlegen.</p>
  </section>
<?php elseif ($results !== null && !($results['free_reached'] ?? false)): ?>
  <?php $missing = $results['missing_cents']; ?>
  <h2 class="results-heading">
    Passende Produkte ab <?= Money::formatEuro($missing) ?>
    <?= $expanded ? '' : 'bis ' . Money::formatEuro($missing + 200) ?>
  </h2>

  <?php if (!empty($categories)): ?>
    <form method="get" action="/" class="category-filter">
      <input type="hidden" name="shop" value="<?= View::e($shop['slug']) ?>">
      <input type="hidden" name="cart" value="<?= View::e($cartRaw) ?>">
      <?php if ($expanded): ?><input type="hidden" name="expanded" value="1"><?php endif; ?>
      <label for="category">Kategorie:</label>
      <select id="category" name="category" data-autosubmit="1">
        <option value="">Alle</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= View::e($cat) ?>" <?= ($selectedCategory ?? null) === $cat ? 'selected' : '' ?>>
            <?= View::e($cat) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <noscript><button type="submit" class="btn-secondary">Anzeigen</button></noscript>
    </form>
  <?php endif; ?>

  <?php // Price-window toggle: stateless GET link, preserves shop/cart/category. ?>
  <p class="price-mode-toggle">
    <?php if ($expanded): ?>
      <a href="/?<?= http_build_query(['shop' => $shop['slug'], 'cart' => $cartRaw, 'category' => $selectedCategory ?? '']) ?>">Nur die günstigsten Füller anzeigen</a>
    <?php else: ?>
      <a href="/?<?= http_build_query(['shop' => $shop['slug'], 'cart' => $cartRaw, 'category' => $selectedCategory ?? '', 'expanded' => '1']) ?>">Mehr Auswahl anzeigen</a>
    <?php endif; ?>
  </p>

  <?php if ($results['products'] === []): ?>
    <section class="notice">
      <p>Aktuell keine passenden Produkte gefunden.
         Versuche es mit „Mehr Auswahl anzeigen“ oder schau später wieder vorbei.</p>
    </section>
  <?php else: ?>
    <ul class="product-list">
      <?php foreach ($results['products'] as $p):
          $effective = \Versandkostenretter\Cart::effectiveExtraCostCents($p['price_cents'], $shop['shipping_cost_cents']);
          // Single outbound-link component: canonical URL in, safe URL out.
          // Unsafe/invalid merchant URLs throw inside OutboundLink; such
          // products render without links (never an unsafe href).
          try {
              $outboundUrl = OutboundLink::build($p['url'], $shop['affiliate'])['url'];
          } catch (\Throwable) {
              $outboundUrl = null;
          }
          // Links route through the privacy-preserving local outbound
          // endpoint (aggregate click counter); it performs the identical
          // OutboundLink transformation server-side.
          $goUrl = '/go/' . View::e($p['external_id']);
          $safeImg = View::safeUrl($p['image_url']);
      ?>
        <li class="product-card">
          <?php
          // Display permission: merchant images only when the shop allows it.
          // When disabled: neutral LOCAL placeholder, zero external requests.
          if ($safeImg !== null && !empty($shop['product_images_enabled'])): ?>
            <img class="product-img" src="<?= View::e($safeImg) ?>" alt="" loading="lazy" width="96" height="96">
          <?php else: ?>
            <span class="product-img product-img-fallback" aria-hidden="true">📦</span>
          <?php endif; ?>
          <div class="product-body">
            <h3 class="product-name">
              <?php if ($outboundUrl !== null): ?>
                <a href="<?= $goUrl ?>" target="_blank" rel="nofollow noopener noreferrer"><?= View::e($p['name']) ?></a>
              <?php else: ?>
                <?= View::e($p['name']) ?>
              <?php endif; ?>
            </h3>
            <?php if ($p['category'] !== null): ?>
              <p class="product-cat"><?= View::e($p['category']) ?></p>
            <?php endif; ?>
            <p class="product-effective">
              Effektiv: <strong><?= Money::formatEuro($effective) ?></strong>
              <span class="hint">(Produktpreis minus <?= Money::formatEuro($shop['shipping_cost_cents']) ?> Versand, den du sonst zahlst)</span>
            </p>
          </div>
          <div class="product-price">
            <?= Money::formatEuro($p['price_cents']) ?>
            <?php if ($outboundUrl !== null): ?>
              <a class="btn-secondary" href="<?= $goUrl ?>" target="_blank" rel="nofollow noopener noreferrer">Zum Shop</a>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if (($results['total'] ?? 0) > count($results['products'])): ?>
      <p class="more-hint">Es gibt noch <?= (int) $results['total'] - count($results['products']) ?> weitere passende Produkte – es werden die günstigsten <?= count($results['products']) ?> angezeigt.</p>
    <?php endif; ?>

    <p class="disclaimer">
      Aus technischen Gründen beziehen sich alle Berechnungen auf die
      Standardlieferung per DHL. Abweichende Versandarten, Sperrgut-,
      Auslands- oder sonstige Sonderversandkosten werden nicht berücksichtigt.
    </p>
  <?php endif; ?>
<?php endif; ?>

<p class="back-link"><a href="/">← Neue Suche</a></p>

<?php require __DIR__ . '/layout_footer.php'; ?>
