<?php
/**
 * Human-driven client for the public Oman Business Platform
 * establishment search (business.gov.om/portal/searchEstablishments).
 *
 * The portal puts a BotDetect CAPTCHA in front of the search. This class
 * fetches that challenge and submits the code a person typed. It does not
 * solve, reload-race, or skip the CAPTCHA. execution=e1s1 is a live Spring
 * Web Flow key and is not reused from a bookmark.
 */
final class BusinessGovOm
{
    public const BASE = 'https://www.business.gov.om';
    public const SOURCE = 'Oman Business Platform';

    public static function dir(): string
    {
        $dir = sys_get_temp_dir() . '/cardify-bgo';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        return $dir;
    }

    /** Open a fresh English search session and return the CAPTCHA challenge. */
    public static function start(): array
    {
        $id = bin2hex(random_bytes(16));
        $cookie = self::dir() . '/' . $id . '.ck';
        $stateFile = self::dir() . '/' . $id . '.json';
        $response = self::request('GET', self::BASE . '/portal/searchEstablishments?locale=en', $cookie, null);
        $form = self::parseForm($response['body']);
        if ($form['captcha_image'] === '') {
            throw new RuntimeException('Portal did not return a CAPTCHA. Search was not started.');
        }
        $image = self::request('GET', self::absolute($form['captcha_image']), $cookie, null);
        $state = [
            'cookie' => $cookie,
            'action' => $form['action'],
            'fields' => $form['fields'],
            'created' => time(),
        ];
        file_put_contents($stateFile, json_encode($state));
        return [
            'session' => $id,
            'image_type' => self::imageType($image['type']),
            'image' => base64_encode($image['body']),
            'step' => 'captcha',
        ];
    }

    /** Submit the CAPTCHA code a person typed. Returns the next form, still unsolved if it failed. */
    public static function verify(string $session, string $captchaCode): array
    {
        $state = self::load($session);
        $captchaCode = trim($captchaCode);
        if ($captchaCode === '') {
            throw new InvalidArgumentException('Type the CAPTCHA code from the image.');
        }
        $fields = $state['fields'];
        $fields['captchaCode'] = $captchaCode;
        $fields['_eventId__verify'] = '';
        $response = self::request('POST', self::absolute($state['action']), $state['cookie'], $fields);
        $form = self::parseForm($response['body']);
        $state['action'] = $form['action'];
        $state['fields'] = $form['fields'];
        $state['created'] = time();
        file_put_contents(self::dir() . '/' . $session . '.json', json_encode($state));
        if ($form['captcha_image'] !== '') {
            $image = self::request('GET', self::absolute($form['captcha_image']), $state['cookie'], null);
            return [
                'session' => $session,
                'step' => 'captcha',
                'error' => 'The portal rejected that code. Try the new image.',
                'image_type' => self::imageType($image['type']),
                'image' => base64_encode($image['body']),
            ];
        }
        return [
            'session' => $session,
            'step' => 'search',
            'inputs' => $form['inputs'],
            'results' => self::parseTables($response['body']),
        ];
    }

    /** Post the current portal form with the values the admin typed. */
    public static function submit(string $session, array $posted): array
    {
        $state = self::load($session);
        $fields = $state['fields'];
        foreach ($posted as $name => $value) {
            if (is_string($name) && isset($fields[$name]) && is_string($value)) {
                $fields[$name] = mb_substr($value, 0, 200);
            }
        }
        if (!isset($posted['_event'])) {
            $fields['_eventId_search'] = '';
        } else {
            $event = preg_replace('/[^A-Za-z0-9_]/', '', (string) $posted['_event']) ?: 'search';
            $fields['_eventId_' . $event] = '';
        }
        $response = self::request('POST', self::absolute($state['action']), $state['cookie'], $fields);
        $form = self::parseForm($response['body']);
        $state['action'] = $form['action'];
        $state['fields'] = $form['fields'];
        $state['created'] = time();
        file_put_contents(self::dir() . '/' . $session . '.json', json_encode($state));
        $out = [
            'session' => $session,
            'step' => $form['captcha_image'] !== '' ? 'captcha' : 'search',
            'inputs' => $form['inputs'],
            'results' => self::parseTables($response['body']),
        ];
        if ($form['captcha_image'] !== '') {
            $image = self::request('GET', self::absolute($form['captcha_image']), $state['cookie'], null);
            $out['image_type'] = self::imageType($image['type']);
            $out['image'] = base64_encode($image['body']);
            $out['error'] = 'The portal asked for the CAPTCHA again.';
        }
        return $out;
    }

    public static function parseForm(string $html): array
    {
        $action = '';
        if (preg_match('/<form[^>]*id="searchCompanyForm"[^>]*action="([^"]+)"/i', $html, $m)
            || preg_match('/<form[^>]*action="([^"]+)"[^>]*id="searchCompanyForm"/i', $html, $m)
            || preg_match('/<form[^>]*action="([^"]*searchEstablishments[^"]*)"/i', $html, $m)) {
            $action = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
        $fields = [];
        $inputs = [];
        if (preg_match('/<form\b[^>]*>(.*)<\/form>/is', $html, $fm)) {
            $form = $fm[1];
        } else {
            $form = $html;
        }
        if (preg_match_all('/<input\b[^>]*>/i', $form, $tags)) {
            foreach ($tags[0] as $tag) {
                if (!preg_match('/name="([^"]+)"/i', $tag, $nm)) {
                    continue;
                }
                $name = html_entity_decode($nm[1], ENT_QUOTES, 'UTF-8');
                $value = '';
                if (preg_match('/value="([^"]*)"/i', $tag, $vm)) {
                    $value = html_entity_decode($vm[1], ENT_QUOTES, 'UTF-8');
                }
                $type = 'text';
                if (preg_match('/type="([^"]+)"/i', $tag, $tm)) {
                    $type = strtolower($tm[1]);
                }
                if ($type === 'hidden') {
                    $fields[$name] = $value;
                } elseif (in_array($type, ['text', 'search'], true)) {
                    $inputs[] = ['name' => $name, 'value' => $value, 'type' => $type];
                    $fields[$name] = $value;
                }
            }
        }
        $captcha = '';
        if (preg_match('/class="BDC_CaptchaImage"[^>]*src="([^"]+)"/i', $html, $im)
            || preg_match('/src="([^"]*botdetectcaptcha\?get=image[^"]*)"/i', $html, $im)) {
            $captcha = html_entity_decode($im[1], ENT_QUOTES, 'UTF-8');
        }
        return ['action' => $action, 'fields' => $fields, 'inputs' => $inputs, 'captcha_image' => $captcha];
    }

    public static function parseTables(string $html): array
    {
        $tables = [];
        if (!preg_match_all('/<table\b[^>]*>(.*?)<\/table>/is', $html, $found)) {
            return $tables;
        }
        foreach ($found[1] as $inner) {
            $rows = [];
            if (!preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $inner, $trs)) {
                continue;
            }
            foreach ($trs[1] as $tr) {
                if (!preg_match_all('/<t[dh]\b[^>]*>(.*?)<\/t[dh]>/is', $tr, $cells)) {
                    continue;
                }
                $line = [];
                foreach ($cells[1] as $cell) {
                    $text = trim(html_entity_decode(strip_tags($cell), ENT_QUOTES, 'UTF-8'));
                    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
                    $line[] = $text;
                }
                if (implode('', $line) !== '') {
                    $rows[] = $line;
                }
            }
            if (count($rows) > 1) {
                $tables[] = $rows;
            }
        }
        return $tables;
    }

    private static function load(string $session): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $session)) {
            throw new InvalidArgumentException('Unknown portal session.');
        }
        $file = self::dir() . '/' . $session . '.json';
        if (!is_file($file)) {
            throw new RuntimeException('Portal session expired. Start again.');
        }
        $state = json_decode((string) file_get_contents($file), true);
        if (!is_array($state) || (time() - (int) ($state['created'] ?? 0)) > 900) {
            throw new RuntimeException('Portal session expired. Start again.');
        }
        return $state;
    }

    private static function absolute(string $url): string
    {
        if (str_starts_with($url, 'http')) {
            return $url;
        }
        return self::BASE . $url;
    }

    private static function imageType(string $headerType): string
    {
        if (str_contains($headerType, 'png')) {
            return 'image/png';
        }
        return 'image/jpeg';
    }

    private static function request(string $method, string $url, string $cookie, ?array $post): array
    {
        $ch = curl_init($url);
        $headers = ['Accept: text/html,application/xhtml+xml'];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_COOKIEJAR => $cookie,
            CURLOPT_COOKIEFILE => $cookie,
            CURLOPT_USERAGENT => 'CardifyAdmin/1.0 (human establishment lookup)',
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post ?? []));
        }
        $body = curl_exec($ch);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code >= 400) {
            throw new RuntimeException('Portal request failed: ' . ($err ?: ('HTTP ' . $code)));
        }
        return ['body' => $body, 'type' => $type];
    }
}
