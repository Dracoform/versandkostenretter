<?php
/** @var array $shops */
/** @var string $pageTitle */

require __DIR__ . '/layout_header.php';
?>

<section class="hero">
  <h1 class="hero-title">Rette deinen Warenkorb!</h1>
  <p class="hero-sub">Kleine Extras. Große Wirkung.</p>
  <p class="hero-expl">
    Du stehst kurz vor dem Gratisversand? Wir zeigen dir günstige Produkte
    aus <strong>deinem Shop</strong>, mit denen du die Schwelle erreichst.
  </p>
  <figure class="hero-illustration">
    <img src="<?= \Versandkostenretter\Assets::url('/assets/images/hero-raccoon.webp') ?>"
         alt="Maskottchen: Ein Waschbär auf einem Rettungsring zieht einen Einkaufswagen sicher durch die Wellen."
         width="900" height="437" fetchpriority="high" decoding="async">
  </figure>
</section>

<section class="card form-card" aria-labelledby="form-heading">
  <h2 id="form-heading" class="visually-hidden">Warenkorbwert prüfen</h2>
  <!-- GET-only lookup: read-only operation, works without JavaScript,
       sets no cookies, results are shareable/bookmarkable URLs. -->
  <form method="get" action="/" class="search-form" novalidate>
    <div class="field">
      <label for="shop">Shop auswählen</label>
      <select id="shop" name="shop" required>
        <option value="" selected disabled>Bitte wählen …</option>
        <?php foreach ($shops as $shop): ?>
          <option value="<?= \Versandkostenretter\View::e($shop['slug']) ?>">
            <?= \Versandkostenretter\View::e($shop['name']) ?>
            (Gratis ab <?= \Versandkostenretter\Money::formatEuro($shop['free_shipping_threshold_cents']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="cart">Aktueller Warenkorbwert</label>
      <div class="money-input">
        <input type="text" id="cart" name="cart"
               inputmode="decimal" autocomplete="off"
               placeholder="z. B. 125,34" required>
      </div>
    </div>

    <button type="submit" class="btn-primary">Show me the goods!</button>
  </form>
</section>

<?php if (($rescueAttempts ?? 0) > 0): ?>
<p class="rescue-counter">
  <span aria-hidden="true">🛟</span>
  Schon <strong><?= \Versandkostenretter\View::e(number_format($rescueAttempts, 0, ',', '.')) ?></strong>
  <?= $rescueAttempts === 1 ? 'Rettungsversuch' : 'Rettungsversuche' ?> gestartet!
</p>
<?php endif; ?>

<?php if (empty($shops)): ?>
<section class="notice">
  <p>Aktuell sind keine Shops verfügbar. Bitte schau später wieder vorbei.</p>
</section>
<?php endif; ?>

<section class="info-strip">
  <div class="info-item">
    <span class="info-icon" aria-hidden="true">🛒</span>
    <p>Shop wählen &amp; Warenkorbwert eingeben</p>
  </div>
  <div class="info-item">
    <span class="info-icon" aria-hidden="true">🎯</span>
    <p>Wir rechnen aus, was dir zur Gratisversand-Schwelle fehlt</p>
  </div>
  <div class="info-item">
    <span class="info-icon" aria-hidden="true">🦝</span>
    <p>Du bekommst passende Extras – aufsteigend nach Preis</p>
  </div>
</section>

<?php require __DIR__ . '/layout_footer.php'; ?>
