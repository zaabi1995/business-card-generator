<?php
require_once __DIR__ . '/../../includes/LogoLibrary.php';
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
$legacy = ['logo_svg_path'=>'/storage/logos/indexed/old.svg','logo_png_path'=>'/storage/logos/indexed/old.png'];
$paths = LogoLibrary::downloadPaths($legacy);
check($paths['svg'] === $legacy['logo_svg_path'], 'Legacy source preserved');
check(!isset($paths['ar_svg']), 'Legacy rows do not get imaginary layouts');
check(LogoLibrary::identityAssets(['logo_identity_assets'=>'{invalid']) === [], 'Malformed JSON handled');
$layouts = [];
foreach (['arabic','bilingual','international'] as $layout) {
    foreach (['normal','black','white'] as $tone) {
        foreach (['svg','pdf','png_2048','webp'] as $format) {
            $layouts[$layout]['assets'][$tone][$format] = "/storage/logos/indexed/$layout-$tone.$format";
        }
    }
}
$paths = LogoLibrary::downloadPaths($legacy + ['logo_identity_assets'=>json_encode(['layouts'=>$layouts])]);
foreach (['arabic'=>'ar_','bilingual'=>'','international'=>'int_'] as $layout=>$prefix) {
    foreach (['normal'=>'','black'=>'_dark','white'=>'_white'] as $tone=>$suffix) {
        foreach (['svg','pdf','webp','png_2048'] as $format) {
            $name = $format === 'png_2048' && $suffix ? 'png' : $format;
            check($paths[$prefix.$name.$suffix] === $layouts[$layout]['assets'][$tone][$format], 'Layout/tone mapping failed');
        }
    }
}
check(!LogoLibrary::canDownload(['logo_status'=>'takedown']), 'Takedown remains blocked');
check(!LogoLibrary::canDownload(['logo_status'=>'disputed']), 'Disputed remains blocked');
echo "Government download paths: legacy, layouts, tones and status checks passed\n";
