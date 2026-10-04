<?php
require_once INCLUDES_DIR . '/LogoAccess.php';
$accessLang = currentLocale() === 'ar' ? 'ar' : 'en';
$accessLabels = require __DIR__ . '/../../lang/' . $accessLang . '/logoaccess.php';
$accessEsc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$accessReturn = '/logo-access.php?company=' . (int)($logoAccessCompany ?? 0) . '&format=' . rawurlencode($logoAccessFormat ?? '') . '&lang=' . $accessLang;
?>
<section class="logo-access" data-logo-access data-lang="<?= $accessLang ?>" data-company="<?= (int)($logoAccessCompany ?? 0) ?>" data-format="<?= $accessEsc($logoAccessFormat ?? '') ?>" data-page="<?= !empty($logoAccessPage) ? '1' : '0' ?>" data-returned="<?= !empty($logoAccessReturned) ? '1' : '0' ?>">
    <?php if (!empty($logoAccessPage)): ?>
    <h1 id="logo-access-dialog-title"><?= $accessEsc(t('logoaccess.title')) ?></h1>
    <?php else: ?>
    <h2 id="logo-access-dialog-title"><?= $accessEsc(t('logoaccess.title')) ?></h2>
    <?php endif; ?>
    <p data-access-quota class="logo-access-muted" role="status" aria-live="polite"></p>
    <div class="logo-access-tiers">
        <div><span><?= t('logoaccess.guest') ?></span><strong><?= t('logoaccess.guest_count') ?></strong></div>
        <div><span><?= t('logoaccess.account') ?></span><strong><?= t('logoaccess.member_count') ?></strong></div>
        <div><span><?= t('logoaccess.unlimited') ?></span><strong>∞</strong></div>
    </div>
    <p class="logo-access-small"><?= t('logoaccess.distinct') ?></p>
    <p data-access-reset class="logo-access-small"><?= t('logoaccess.daily') ?></p>
    <p data-access-error class="logo-access-error" role="alert" hidden></p>

    <div data-access-guest>
        <form data-access-email>
            <label for="logo-access-email"><?= t('logoaccess.email') ?></label>
            <input id="logo-access-email" name="email" type="email" autocomplete="email" dir="ltr" maxlength="120" required>
            <button class="logo-access-primary" type="submit"><?= t('logoaccess.send_code') ?></button>
        </form>
        <form data-access-code hidden>
            <p data-access-code-message class="logo-access-muted"></p>
            <label for="logo-access-code"><?= t('logoaccess.code') ?></label>
            <input id="logo-access-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" dir="ltr" pattern="[0-9]{6}" minlength="6" maxlength="6" required>
            <button class="logo-access-primary" type="submit"><?= t('logoaccess.verify') ?></button>
            <button type="button" data-access-change class="logo-access-link"><?= t('logoaccess.change_email') ?></button>
        </form>
        <a class="logo-access-link" href="/login.php?redirect=<?= rawurlencode($accessReturn) ?>"><?= t('logoaccess.existing_login') ?></a>
    </div>

    <div data-access-member hidden>
        <p data-access-member-email class="logo-access-small"></p>
        <button data-access-continue class="logo-access-primary" type="button" hidden><?= t('logoaccess.continue_download') ?></button>
        <div data-access-reward hidden>
            <button data-access-watch class="logo-access-secondary" type="button"><?= t('logoaccess.watch') ?></button>
            <p class="logo-access-small"><?= t('logoaccess.reward_note') ?></p>
        </div>
    </div>

    <div data-access-upgrade class="logo-access-offer">
        <h3><?= t('logoaccess.upgrade') ?></h3>
        <p class="logo-access-price"><?= $accessEsc(t('logoaccess.price', ['price' => number_format(LogoAccess::price(), 3, '.', ''), 'days' => LogoAccess::days()])) ?></p>
        <p class="logo-access-small"><?= t('logoaccess.pass_note') ?></p>
        <button data-access-buy class="logo-access-secondary" type="button"><?= t('logoaccess.upgrade') ?></button>
        <form data-access-billing hidden>
            <p class="logo-access-small"><?= t('logoaccess.billing_note') ?></p>
            <div class="logo-access-fields">
                <div><label for="logo-access-first"><?= t('logoaccess.first_name') ?></label><input id="logo-access-first" name="first_name" autocomplete="given-name" maxlength="60" required></div>
                <div><label for="logo-access-last"><?= t('logoaccess.last_name') ?></label><input id="logo-access-last" name="last_name" autocomplete="family-name" maxlength="60" required></div>
            </div>
            <label for="logo-access-phone"><?= t('logoaccess.phone') ?></label>
            <input id="logo-access-phone" name="phone" type="tel" autocomplete="tel" dir="ltr" value="+968" required>
            <button class="logo-access-primary" type="submit"><?= t('logoaccess.prepare_payment') ?></button>
        </form>
        <div data-access-payments hidden>
            <p class="logo-access-small"><?= t('logoaccess.payment_ready') ?></p>
            <div data-ap="wrap" hidden>
                <apple-pay-button data-ap="button" role="button" aria-label="<?= $accessEsc(t('logoaccess.pay_apple')) ?>" buttonstyle="black" type="plain" style="--apple-pay-button-width:100%;--apple-pay-button-height:48px;--apple-pay-button-border-radius:10px;display:block;width:100%;height:48px;"></apple-pay-button>
                <p data-ap="status" hidden role="status" aria-live="polite"></p>
            </div>
            <a data-access-card class="logo-access-primary" href="/logo-access.php"><?= t('logoaccess.pay_card') ?></a>
            <p data-ap="unavailable" class="logo-access-small" hidden><?= t('logoaccess.apple_unavailable') ?></p>
            <p class="logo-access-small"><?= t('logoaccess.secure') ?></p>
        </div>
    </div>
    <p data-access-active class="logo-access-success" hidden></p>
    <p class="logo-access-small"><?= t('logoaccess.terms_note') ?> <a href="<?= $accessLang === 'ar' ? '/ar' : '' ?>/logos/terms"><?= t('logoaccess.terms') ?></a> · <a href="<?= $accessLang === 'ar' ? '/ar' : '' ?>/privacy"><?= t('logoaccess.privacy') ?></a></p>
    <button data-access-signout class="logo-access-link" type="button" hidden><?= t('logoaccess.sign_out') ?></button>
</section>
<script<?= cspNonceAttr() ?>>window.cardifyLogoAccessLabels = <?= json_encode($accessLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="/assets/js/paymob-apple-pay.js?v=<?= filemtime(__DIR__ . '/../../assets/js/paymob-apple-pay.js') ?>" defer></script>
<script src="/assets/js/logo-access.js?v=<?= filemtime(__DIR__ . '/../../assets/js/logo-access.js') ?>" defer></script>
