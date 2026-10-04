<?php
if (!defined('INCLUDES_DIR')) { http_response_code(404); exit; }
$identity = LogoLibrary::identityAssets($company);
if (!$canDownload || empty($identity['layouts'])) return;
?>
<div class="cardify-government-layouts" aria-labelledby="government-layouts-title">
    <h3 id="government-layouts-title" class="text-lg font-bold text-gray-900"><?= logo_hero_esc(t('logos.gov_identity_title')) ?></h3>
    <p class="mt-1 text-sm text-gray-600"><?= logo_hero_esc(t('logos.gov_identity_source', ['page' => $identity['pdf_page'] ?? ''])) ?></p>
    <p class="mt-1 text-sm text-gray-600"><?= logo_hero_esc(t('logos.gov_identity_note')) ?></p>
    <?php foreach (['arabic' => 'ar_', 'bilingual' => '', 'international' => 'int_'] as $layout => $prefix): ?>
        <?php if (empty($identity['layouts'][$layout])) continue; ?>
        <h4 class="mt-5 font-semibold text-gray-900"><?= logo_hero_esc(t('logos.gov_layout_' . $layout)) ?></h4>
        <?php if ($layout === 'international'): ?><p class="mt-1 text-sm text-gray-600"><?= logo_hero_esc(t('logos.gov_international_note')) ?></p><?php endif; ?>
        <div class="cardify-government-grid">
            <?php foreach (['normal' => '', 'black' => '_dark', 'white' => '_white'] as $tone => $suffix):
                $assets = $identity['layouts'][$layout]['assets'][$tone] ?? [];
                if (empty($assets['svg'])) continue;
            ?>
            <div class="cardify-government-tile">
                <div class="cardify-government-preview<?= $tone === 'white' ? ' cardify-government-preview-dark' : '' ?>">
                    <img src="<?= logo_hero_esc($assets['svg']) ?>" alt="<?= logo_hero_esc($company['name_en'] . ', ' . t('logos.gov_layout_' . $layout) . ', ' . t('logos.gov_tone_' . $tone)) ?>" loading="lazy" width="320" height="180">
                </div>
                <p class="mt-2 text-sm font-medium text-gray-900"><?= logo_hero_esc(t('logos.gov_tone_' . $tone)) ?></p>
                <div class="cardify-government-links">
                    <?php foreach (['svg' => 'SVG', 'pdf' => 'PDF', 'png_2048' => 'PNG'] as $type => $label):
                        if (empty($assets[$type])) continue;
                        $format = $prefix . ($type === 'png_2048' && $suffix ? 'png' : $type) . $suffix;
                    ?>
                    <a class="cardify-dl-btn" href="/logo-download?company=<?= (int) $companyId ?>&amp;format=<?= logo_hero_esc($format) ?>" rel="nofollow" download>
                        <span><?= $label ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div>
<style>
.cardify-government-layouts { padding: 1.5rem; border-top: 1px solid #e5e7eb; }
.cardify-government-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; margin-top: .75rem; }
.cardify-government-tile { min-width: 0; }
.cardify-government-preview { background: #fff; border: 1px solid #e5e7eb; border-radius: .75rem; height: 160px; display: flex; align-items: center; justify-content: center; padding: 1rem; }
.cardify-government-preview-dark { background: #111827; border-color: #111827; }
.cardify-government-preview img { display: block; width: 100%; height: 100%; object-fit: contain; }
.cardify-government-links { display: flex; flex-wrap: wrap; gap: .4rem; margin-top: .5rem; }
.cardify-government-links .cardify-dl-btn { padding: .4rem .6rem; font-size: .75rem; }
@media (max-width: 640px) { .cardify-government-grid { grid-template-columns: 1fr; } .cardify-government-preview { height: 180px; } }
</style>
