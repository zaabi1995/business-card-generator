<?php
/**
 * AppleWalletPass — pure-PHP PKPass generator.
 *
 * Clean-room implementation using only ext-openssl + ext-zip (both standard).
 * No composer dependency.
 *
 * Required config constants (see docs/superpowers/plans/2026-04-16-wallet-passes.md):
 *   APPLE_WALLET_ENABLED          bool
 *   APPLE_WALLET_CERT_PATH        path to PEM (unencrypted combined cert+key is ok;
 *                                 or encrypted — use APPLE_WALLET_CERT_PASSWORD)
 *   APPLE_WALLET_CERT_PASSWORD    string (empty if PEM is unencrypted)
 *   APPLE_WALLET_WWDR_PATH        path to Apple WWDR intermediate PEM
 *   APPLE_WALLET_PASS_TYPE_ID     e.g. pass.om.cardify.businesscard
 *   APPLE_WALLET_TEAM_ID          10-char Apple Team ID
 *   APPLE_WALLET_ORG_NAME         Organization name on the pass
 */

class AppleWalletPass
{
    /** @var array pass.json structure */
    private array $pass;

    /** @var array<string,string> filename => binary contents */
    private array $assets = [];

    public function __construct(array $pass)
    {
        $this->pass = $pass;
    }

    public function addAsset(string $filename, string $contents): void
    {
        $this->assets[$filename] = $contents;
    }

    /**
     * Build and return the signed .pkpass zip bytes.
     *
     * @throws RuntimeException
     */
    public function build(): string
    {
        $this->assertConfigured();

        // 1. pass.json
        $passJson = json_encode($this->pass, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($passJson === false) {
            throw new RuntimeException('pass.json encode failed');
        }

        $files = $this->assets;
        $files['pass.json'] = $passJson;

        // 2. manifest.json = { filename => sha1(contents) }
        $manifest = [];
        foreach ($files as $name => $bytes) {
            $manifest[$name] = sha1($bytes);
        }
        $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES);
        $files['manifest.json'] = $manifestJson;

        // 3. Detach-sign the manifest
        $files['signature'] = $this->signManifest($manifestJson);

        // 4. Zip it
        return $this->zipFiles($files);
    }

    private function assertConfigured(): void
    {
        foreach ([
            'APPLE_WALLET_CERT_PATH',
            'APPLE_WALLET_WWDR_PATH',
            'APPLE_WALLET_PASS_TYPE_ID',
            'APPLE_WALLET_TEAM_ID',
        ] as $c) {
            if (!defined($c) || constant($c) === '' || constant($c) === null) {
                throw new RuntimeException("Apple Wallet not configured: $c is missing");
            }
        }
        if (!is_readable(APPLE_WALLET_CERT_PATH)) {
            throw new RuntimeException('Apple Wallet cert not readable: ' . APPLE_WALLET_CERT_PATH);
        }
        if (!is_readable(APPLE_WALLET_WWDR_PATH)) {
            throw new RuntimeException('Apple Wallet WWDR not readable: ' . APPLE_WALLET_WWDR_PATH);
        }
    }

    /**
     * PKCS#7 detached signature of manifest.json, DER output.
     */
    private function signManifest(string $manifestJson): string
    {
        $tmpIn   = tempnam(sys_get_temp_dir(), 'pkpass_in_');
        $tmpOut  = tempnam(sys_get_temp_dir(), 'pkpass_sig_');
        file_put_contents($tmpIn, $manifestJson);

        $certPem = file_get_contents(APPLE_WALLET_CERT_PATH);
        $password = defined('APPLE_WALLET_CERT_PASSWORD') ? APPLE_WALLET_CERT_PASSWORD : '';

        // Load cert+key from combined PEM.
        $cert = openssl_x509_read($certPem);
        if ($cert === false) {
            @unlink($tmpIn); @unlink($tmpOut);
            throw new RuntimeException('Failed to read signing cert: ' . openssl_error_string());
        }
        $key = openssl_pkey_get_private($certPem, $password);
        if ($key === false) {
            @unlink($tmpIn); @unlink($tmpOut);
            throw new RuntimeException('Failed to read signing key: ' . openssl_error_string());
        }

        $ok = openssl_pkcs7_sign(
            $tmpIn,
            $tmpOut,
            $cert,
            [$key, $password],
            [],                                   // headers
            PKCS7_BINARY | PKCS7_DETACHED,
            APPLE_WALLET_WWDR_PATH                // extra cert (intermediate)
        );
        if (!$ok) {
            @unlink($tmpIn); @unlink($tmpOut);
            throw new RuntimeException('openssl_pkcs7_sign failed: ' . openssl_error_string());
        }

        // openssl_pkcs7_sign emits S/MIME (PEM-armored w/ email headers).
        // We need the raw DER body of the signed data.
        $smime = file_get_contents($tmpOut);
        @unlink($tmpIn);
        @unlink($tmpOut);

        // Extract the base64 body between the MIME boundaries.
        if (!preg_match('/Content-Disposition:[^\n]*\r?\n\r?\n([A-Za-z0-9+\/=\r\n]+?)\r?\n-{5,}/s', $smime, $m)) {
            // Fallback: split on blank line and take the last chunk before the final boundary.
            $parts = preg_split("/\r?\n\r?\n/", $smime);
            $body  = array_pop($parts);
            $body  = preg_replace('/-{5,}[^\n]*\n?/', '', $body);
        } else {
            $body = $m[1];
        }
        $der = base64_decode(preg_replace('/\s+/', '', $body), true);
        if ($der === false || $der === '') {
            throw new RuntimeException('Signature DER decode failed');
        }
        return $der;
    }

    private function zipFiles(array $files): string
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ext-zip is required for .pkpass generation');
        }
        $tmpZip = tempnam(sys_get_temp_dir(), 'pkpass_zip_');
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot open zip for writing');
        }
        foreach ($files as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->close();
        $bytes = file_get_contents($tmpZip);
        @unlink($tmpZip);
        return $bytes;
    }

    /** Is Apple Wallet fully configured & enabled? */
    public static function isEnabled(): bool
    {
        if (!defined('APPLE_WALLET_ENABLED') || !APPLE_WALLET_ENABLED) {
            return false;
        }
        foreach ([
            'APPLE_WALLET_CERT_PATH',
            'APPLE_WALLET_WWDR_PATH',
            'APPLE_WALLET_PASS_TYPE_ID',
            'APPLE_WALLET_TEAM_ID',
        ] as $c) {
            if (!defined($c) || !constant($c)) return false;
        }
        return is_readable(APPLE_WALLET_CERT_PATH) && is_readable(APPLE_WALLET_WWDR_PATH);
    }
}
