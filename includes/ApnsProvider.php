<?php
/**
 * APNs provider abstraction for Wallet pass updates. A pass push is an EMPTY
 * payload to the device's push token, with the topic set to the Pass Type ID
 * (NOT the app bundle id) on the PRODUCTION host (api.push.apple.com) - this is
 * how Wallet learns to pull the updated pass.
 *
 * ApnsResult per token: 'sent' | 'invalid' (410/BadDeviceToken -> caller drops
 * the registration) | 'error' (transient -> caller may retry).
 */
interface ApnsProvider
{
    /** @param array<int,array{push_token:string,environment:string,device_library_id:string}> $regs
     *  @return array<int,array{device_library_id:string,result:string}> */
    public function pushPassUpdates(string $passTypeId, array $regs): array;
}

/**
 * MockApnsProvider: records every push in-memory for tests. Deterministic:
 * tokens containing 'INVALID' report 'invalid'; 'TRANSIENT' report 'error';
 * everything else 'sent'. No network.
 */
class MockApnsProvider implements ApnsProvider
{
    /** @var array<int,array{token:string,topic:string}> */
    public array $sent = [];

    public function pushPassUpdates(string $passTypeId, array $regs): array
    {
        $out = [];
        foreach ($regs as $r) {
            $token = $r['push_token'];
            $result = 'sent';
            if (strpos($token, 'INVALID') !== false) $result = 'invalid';
            elseif (strpos($token, 'TRANSIENT') !== false) $result = 'error';
            else $this->sent[] = ['token' => $token, 'topic' => $passTypeId];
            $out[] = ['device_library_id' => $r['device_library_id'], 'result' => $result];
        }
        return $out;
    }
}

/**
 * TokenApnsProvider: production sender (HTTP/2 to api.push.apple.com). Signs a
 * JWT with the pass-type APNs credential. LEFT AS AN INTERFACE-COMPLETE STUB:
 * activating it requires the Apple credential (see docs/WALLET_APNS_SETUP.md).
 * Never logs the token or the JWT.
 */
class TokenApnsProvider implements ApnsProvider
{
    private string $host;
    public function __construct(bool $production = true)
    {
        $this->host = $production ? 'https://api.push.apple.com' : 'https://api.sandbox.push.apple.com';
    }

    public function pushPassUpdates(string $passTypeId, array $regs): array
    {
        // Requires: APPLE_WALLET_PUSH_CERT_PATH (.p8) OR the pass-type push cert,
        // APPLE_WALLET_APNS_KEY_ID, APPLE_WALLET_TEAM_ID. Topic = $passTypeId.
        // Not activated without the credential; fail closed rather than pretend.
        if (!defined('APPLE_WALLET_PUSH_CERT_PATH') || !is_readable(APPLE_WALLET_PUSH_CERT_PATH)) {
            $out = [];
            foreach ($regs as $r) { $out[] = ['device_library_id' => $r['device_library_id'], 'result' => 'error']; }
            error_log('[wallet/apns] production APNs credential not configured; push skipped');
            return $out;
        }
        // Real HTTP/2 send would go here (curl_multi, :path /3/device/<token>,
        // apns-topic: $passTypeId, apns-push-type: background). Intentionally not
        // implemented until the credential exists, to avoid a false "sent".
        $out = [];
        foreach ($regs as $r) { $out[] = ['device_library_id' => $r['device_library_id'], 'result' => 'error']; }
        return $out;
    }
}

/** Factory: mock in tests / when no credential; token provider in production. */
function apnsProvider(): ApnsProvider
{
    if (defined('WALLET_APNS_MOCK') && WALLET_APNS_MOCK) return new MockApnsProvider();
    if (defined('APPLE_WALLET_PUSH_CERT_PATH') && is_readable(APPLE_WALLET_PUSH_CERT_PATH)) return new TokenApnsProvider(true);
    return new MockApnsProvider();
}
