<?php
/**
 * Results page.
 *
 * @var array|null $shop            selected shop (id, name, shipping/threshold cents)
 * @var int|null   $cartCents       current cart value in cents
 * @var array      $errors          validation errors
 * @var array|null $results         ['free_reached'=>bool, 'products'=>[], 'total'=>int, 'missing_cents'=>int]
 * @var array      $shops           all active shops (for the form)
 * @var string     $pageTitle
 * @var int        $maxResults
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
  <h2 class="results-heading">Passende Produkte ab <?= Money::formatEuro($missing) ?></h2>

  <?php if (!empty($categories)): ?>
    <form method="get" action="/" class="category-filter">
      <input type="hidden" name="shop" value="<?= View::e($shop['slug']) ?>">
      <input type="hidden" name="cart" value="<?= View::e($cartRaw) ?>">
      <label for="category">Kategorie:</label>
      <select id="category" name="category" data-autosubmit="1">
        <option value="">Alle</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= View::e($cat) ?>" <?= $selectedCategory === $cat ? 'selected' : '' ?>>
            <?= View::e($cat) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <noscript><button type="submit" class="btn-secondary">Anzeigen</button></noscript>
    </form>
  <?php endif; ?>

  <?php if ($results['products'] === []): ?>
    <section class="notice">
      <p>Aktuell keine passenden Produkte gefunden.
         Versuche einen anderen Warenkorbwert oder schau später wieder vorbei.</p>
    </section>
  <?php else: ?>
    <ul class="product-list">
      <?php foreach ($results['products'] as $p):
          $effective = \Versandkostenretter\Cart::effectiveExtraCostCents($p['price_cents'], $shop['shipping_cost_cents']);
          // Single outbound-link component: canonical URL in, safe URL out.
          $link = OutboundLink::build($p['url'], $shop['affiliate']);
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
                <a href="<?= $goUrl ?>" rel="nofollow noopener"><?= View::e($p['name']) ?></a>
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
              <a class="btn-secondary" href="<?= $goUrl ?>" rel="nofollow noopener">Zum Shop</a>
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
