<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Logs;

/**
 * Minimizes what a log entry carries before it reaches the model, without losing its diagnostic value.
 *
 * - IP addresses are masked: IPv4 keeps the first two octets (`203.0.x.x`), IPv6 its /48 prefix, in the remoteAddr
 *   field and inside any text; in the user agent an address right after a slash is a version ("Chrome/141.0.0.0") and
 *   stays, since the version of the client is what makes it useful.
 * - Control characters, ANSI escape sequences and invisible or bidirectional marks are removed: a file name or a
 *   message is written by users, and those are the characters that hide or disguise text.
 * - A text is cut at a limit; an exception keeps class, message, code, place and ten frames of class->function and
 *   file:line, never the arguments.
 * - With file names hidden ({@see self::fileNames()}, {@see self::url()}), the names and paths of files in messages and
 *   URLs become a fixed marker. The place and the frames of an exception are the server's own PHP code and stay.
 */
final class LogRedactor {
    /** Frames of a stack trace kept. */
    public const TRACE_FRAMES = 10;
    /** Previous exceptions kept below the one logged: three levels in all. */
    public const PREVIOUS_LEVELS = 2;
    /** Longest exception message, as the longest log message. */
    public const MESSAGE_LIMIT = 2000;
    /** Maximum bytes inspected per field before any regex. */
    public const PROCESSING_LIMIT = 16384;
    /** Shown in place of a value that is not an IP address. */
    private const NOT_AN_ADDRESS = 'x';
    /**
     * IPv4 addresses inside a text, not glued to a word or to another number: "1.2.3.4.5" and "v31.0.9.1" are versions,
     * while a full stop, a comma or a colon after the address is punctuation and stays outside the match. %s is where
     * the characters the address may not follow go: a slash too in a user agent, where "Chrome/141.0.0.0" is a version.
     */
    private const IPV4 = '/(?<![\w.%s])\d{1,3}(?:\.\d{1,3}){3}(?!\.?\d)(?!\w)/';
    /**
     * Candidates for an IPv6 address inside a text; filter_var() decides, so a clock time or a MAC address stays. A
     * trailing colon may be punctuation ("Remote IP: 2001:db8::9:"), so the callback tries once without it.
     * CIDR suffixes are consumed and reduced to the same canonical /48.
     */
    private const IPV6 = '/(?<![\w:.%s])(?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{0,4}(?!\w)(?!\.\w)(?!:[0-9a-f])(?:\/\d+)?/i';
    /**
     * An IPv6 candidate glued to a label by a colon ("IP:2001:db8::1", "clientAddress:2001:db8::9"), which the pattern
     * above cannot see, since an address never starts right after a colon there. The label is consumed once, possessively, with no length cap;
     * the boundary excludes every label character (including hyphens), so matching never restarts inside it.
     * Together these make the pass linear even for a long label with no valid address.
     */
    private const LABELED_IPV6 = '/(?<![\w:.%s-])([a-z_][\w-]*+):((?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{0,4}(?!\w)(?!\.\w)(?!:[0-9a-f])(?:\/\d+)?)/i';
    /** ANSI escape sequences (CSI and OSC). */
    private const ANSI = '/\x{1B}(?:\[[0-9;?]*[ -\/]*[@-~]|\][^\x{07}\x{1B}]*(?:\x{07}|\x{1B}\\\\)?)?/u';
    /**
     * Control characters, C1 controls, the soft hyphen, every bidirectional control (ALM, LRM, RLM, the embeddings,
     * overrides and isolates) and the zero-width characters, the Mongolian vowel separator and the BOM included.
     */
    private const INVISIBLE = '/[\x{00}-\x{08}\x{0B}\x{0C}\x{0E}-\x{1F}\x{7F}-\x{9F}\x{00AD}\x{061C}\x{180E}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}]/u';
    /** Shown in place of a file name or path. */
    public const HIDDEN = '[hidden]';
    /**
     * Extensions that make a bare word a file name. A list, not "any dot": host names (apps.nextcloud.com), versions
     * (31.0.9), masked addresses (203.0.x.x) and class names stay readable.
     */
    private const EXTENSIONS = 'txt|md|markdown|pdf|docx?|dotx?|docm|xlsx?|xlsm|xltx|pptx?|ppsx?|odt|ods|odp|odg|odf|ott|rtf|csv|tsv'
        . '|json|xml|ya?ml|html?|css|js|ts|php|py|rb|java|c|h|cpp|cs|go|rs|sh|bat|ps1|sql|db|sqlite|mdb|accdb|log|ini|conf|cfg'
        . '|jpe?g|png|gif|webp|heic|heif|bmp|tiff?|svg|ico|raw|cr2|nef|dng|psd|ai|eps|indd|xcf|dwg|dxf|skp|stl|obj|blend'
        . '|mp3|m4a|wav|flac|ogg|oga|opus|aac|wma|mp4|m4v|mov|avi|mkv|webm|wmv|mpe?g|3gp'
        . '|zip|rar|7z|tar|gz|tgz|bz2|xz|zst|iso|dmg|img|apk|exe|msi|deb|rpm|jar|bak|tmp|part|ocTransferId\d+'
        . '|eml|msg|mbox|ics|vcf|pem|crt|cer|key|p12|pfx|kdbx|gpg|asc|epub|mobi|pages|numbers|keynote|one|vsdx?|mpp|xps';
    /**
     * Route prefixes of Nextcloud, longest first. Only the prefix stays when names are hidden: whatever follows it in
     * a path is the user's and becomes {@see self::HIDDEN}. A WebDAV path keeps its account, as the summary does; a
     * public share never keeps its token.
     */
    private const ROUTE_PREFIXES = [
        '~^/(?:index\.php/)?apps/[\w-]+/~i',
        '~^/ocs/v[12]\.php/(?:apps/[\w-]+/|cloud/)?~i',
        '~^/remote\.php/dav/(?:files|uploads|trashbin|versions)/[^/?#\s]+/~i',
        '~^/remote\.php/(?:webdav/|dav/[\w-]+/|dav/)?~i',
        '~^/public\.php/(?:dav/files/|webdav/)?~i',
        '~^/index\.php/(?:s/|f/)?~i',
        '~^/(?:s|f|ocs)/~i',
        '~^/(?:status|cron)\.php~i',
    ];
    /** A quoted text kept as it is: a version number. */
    private const VERSION = '/^v?\d+(?:\.\d+)+$/i';
    /** A quoted text kept as it is: a namespaced PHP class, optionally with a method ("OC\Files\View::fopen"). */
    private const PHP_NAME = '/^\\\\?[A-Za-z_]\w*(?:(?:\\\\[A-Za-z_]\w*)+(?:::\w+(?:\(\))?)?|::\w+(?:\(\))?)$/';
    /** A URL: scheme and host, then the rest. */
    private const URL = '~^([a-z][a-z0-9+.-]*://[^/?#\s]+)(.*)$~i';
    /**
     * A token with a slash that is not a path: a masked IPv6 prefix or a CIDR, a MIME type. A trailing punctuation
     * mark is allowed.
     */
    private const NOT_A_PATH = '~^(?:[0-9a-f:]+/\d{1,3}|[\dx.]+/\d{1,2}|(?:application|text|image|audio|video|multipart|font|model|message|httpd)/[\w.+-]+)[,.;:)\]]*$~i';

    /** Line breaks, tabs and the Unicode line and paragraph separators: each becomes a space. */
    private const BREAKS = ["\r\n", "\r", "\n", "\t", "\u{2028}", "\u{2029}"];

    private function __construct() {
    }

    /**
     * @param string $address the remoteAddr of an entry
     * @return string the masked address; '' and '--' unchanged; anything that is not an address becomes 'x'
     */
    public static function ip(string $address): string {
        $address = trim($address);
        if ($address === '' || $address === '--') {
            return $address;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $octets = explode('.', $address);
            return $octets[0] . '.' . $octets[1] . '.x.x';
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return self::NOT_AN_ADDRESS;
        }
        // An IPv4-mapped address is the IPv4 one.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $address, $mapped) === 1) {
            return self::ip($mapped[1]);
        }
        $prefix = substr((string)inet_pton($address), 0, 6) . str_repeat("\0", 10);
        return inet_ntop($prefix) . '/48';
    }

    /**
     * A text fit to show: valid UTF-8, no control or invisible characters, addresses masked, cut at the limit.
     *
     * @param string $text a message, a URL, a user agent
     * @param int $limit longest result in characters, before the marker of a cut
     * @param bool $userAgent true for a user agent: an address right after a slash is a version ("Chrome/141.0.0.0")
     *     and stays; any other address is masked as in every text
     * @return string the cleaned text, with '…' when it was cut
     */
    public static function text(string $text, int $limit, bool $userAgent = false): string {
        $processingCut = strlen($text) > self::PROCESSING_LIMIT;
        if ($processingCut) {
            // Drop the final unfinished token: it could be an address crossing the boundary.
            $text = preg_replace('/\S+$/', '', substr($text, 0, self::PROCESSING_LIMIT)) ?? '';
        }
        // Invalid bytes become U+FFFD, so the patterns below, which need valid UTF-8, never fail on them.
        $text = (string)json_decode(json_encode($text, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        $text = preg_replace(self::ANSI, '', $text) ?? '';
        $text = str_replace(self::BREAKS, ' ', $text);
        $text = preg_replace(self::INVISIBLE, '', $text) ?? '';
        $notAfter = $userAgent ? '\/' : '';
        $text = preg_replace_callback(sprintf(self::IPV4, $notAfter), static function (array $m): string {
            $masked = self::ip($m[0]);
            return $masked === self::NOT_AN_ADDRESS ? $m[0] : $masked;
        }, $text) ?? '';
        $text = preg_replace_callback(sprintf(self::IPV6, $notAfter), static fn (array $m): string => self::maskedIpv6($m[0]) ?? $m[0], $text) ?? '';
        $text = preg_replace_callback(sprintf(self::LABELED_IPV6, $notAfter), static function (array $m): string {
            // A hexadecimal label may actually be the first group of the whole address.
            return self::maskedIpv6($m[0]) ?? ($m[1] . ':' . (self::maskedIpv6($m[2]) ?? $m[2]));
        }, $text) ?? '';
        $text = trim($text);
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text . ($processingCut ? '…' : '');
    }

    /**
     * Replaces the names and paths of files in a message by {@see self::HIDDEN}, conservatively: free text cannot tell
     * where a name ends, since real names carry spaces, "#" and "?".
     * - Every quoted text ("…", “…”, '…') goes, unless it is all a URL (scheme and host stay, the path goes), a version
     *   number or a PHP class or method name.
     * - A bare word with a known extension goes (salary.txt).
     * - From the first token that looks like a path (it starts with "/", or has a "/" outside a URL) to the end of the
     *   text, everything goes. A route of Nextcloud at its start keeps only its prefix (/apps/<app>/, /ocs/v2.php/,
     *   /remote.php/dav/files/<account>/…); a URL keeps its scheme and host. A MIME type, a CIDR and a masked IPv6
     *   prefix are not paths.
     * What it cannot catch: a name standing alone, without quotes, without a slash and without a known extension, and a
     * Windows path written with backslashes.
     *
     * @param string $text a text already passed through {@see self::text()}
     * @return string the text with the names and paths replaced
     */
    public static function fileNames(string $text): string {
        foreach (['/"([^"]*)"/u' => ['"', '"'], '/“([^”]*)”/u' => ['“', '”'], "/(?<!\\w)'([^']*)'(?!\\w)/u" => ["'", "'"]] as $pattern => [$open, $close]) {
            $text = preg_replace_callback($pattern, static fn (array $m): string => $open . self::quoted($m[1]) . $close, $text) ?? '';
        }
        $text = preg_replace('~(?<![\w./\[-])[^\s"\'<>/\\\\|:*?]+\.(?:' . self::EXTENSIONS . ')(?![\w-])~iu', self::HIDDEN, $text) ?? '';
        if (preg_match_all('/\S+/u', $text, $tokens, PREG_OFFSET_CAPTURE) === false) {
            return self::HIDDEN;
        }
        foreach ($tokens[0] as [$token, $offset]) {
            $kept = self::pathStart($token);
            if ($kept !== null) {
                return substr($text, 0, $offset) . $kept . self::HIDDEN;
            }
        }
        return $text;
    }

    /**
     * @param string $url the url field of an entry, already passed through {@see self::text()}
     * @return string scheme, host and the route prefix of Nextcloud; the rest of the path and the whole query hidden
     */
    public static function url(string $url): string {
        $head = '';
        if (preg_match(self::URL, $url, $m) === 1) {
            [$head, $url] = [$m[1], $m[2]];
        }
        $cut = strcspn($url, '?#');
        $path = substr($url, 0, $cut);
        $prefix = self::routePrefix($path);
        if ($prefix === '' && str_starts_with($path, '/')) {
            $prefix = '/';
        }
        return $head . $prefix . (substr($path, strlen($prefix)) !== '' ? self::HIDDEN : '') . ($cut < strlen($url) ? '?' . self::HIDDEN : '');
    }

    /**
     * @param string $body a quoted text, without its quotes
     * @return string the text when it is a URL without a path, a version or a PHP name; otherwise hidden
     */
    private static function quoted(string $body): string {
        if ($body === '' || $body === self::HIDDEN || preg_match(self::VERSION, $body) === 1 || preg_match(self::PHP_NAME, $body) === 1) {
            return $body;
        }
        if (preg_match(self::URL, $body, $m) === 1 && !preg_match('/\s/u', $body)) {
            return $m[1] . (trim($m[2], '/') === '' ? $m[2] : '/' . self::HIDDEN);
        }
        return self::HIDDEN;
    }

    /**
     * @param string $token one word of a text
     * @return string|null what stays of a token where a path starts (the hiding then runs to the end), null when the
     *     token is not a path
     */
    private static function pathStart(string $token): ?string {
        $lead = '';
        // A quoted text was settled above and has no spaces any more: it is kept or already hidden.
        if (preg_match('/^[(\[<{=,;]*["“\'].*["”\'][,.;:)\]]*$/u', $token) === 1) {
            return null;
        }
        if (preg_match('/^[(\[<{=,;]+/', $token, $m) === 1) {
            $lead = $m[0];
            $token = substr($token, strlen($lead));
        }
        if (preg_match(self::URL, $token, $m) === 1) {
            return trim($m[2], '/') === '' ? null : $lead . $m[1] . self::routePrefix(substr($m[2], 0, strcspn($m[2], '?#')));
        }
        if (!str_contains($token, '/') || trim($token, '/') === '' || preg_match(self::NOT_A_PATH, $token) === 1) {
            return null;
        }
        if (!str_starts_with($token, '/')) {
            return $lead;
        }
        $prefix = self::routePrefix($token);
        // A route with nothing after it names no file.
        return rtrim(substr($token, strlen($prefix)), ',.;:)]') === '' ? null : $lead . $prefix;
    }

    /** @return string the route prefix of Nextcloud a path starts with, '' when none */
    private static function routePrefix(string $path): string {
        foreach (self::ROUTE_PREFIXES as $pattern) {
            if (preg_match($pattern, $path, $m) === 1) {
                return $m[0];
            }
        }
        return '';
    }

    /** Only our exact canonical /48 output is already redacted; all other CIDRs still need masking. */
    private static function maskedIpv6(string $candidate): ?string {
        $address = explode('/', $candidate, 2)[0];
        $suffix = '';
        if (!self::isIpv6($address) && str_ends_with($address, ':') && !str_ends_with($address, '::')) {
            $address = substr($address, 0, -1);
            $suffix = ':';
        }
        if (!self::isIpv6($address)) {
            return null;
        }
        $masked = self::ip($address);
        return $candidate === $masked ? $candidate : $masked . $suffix;
    }

    /** @return bool whether the whole string is an IPv6 address */
    private static function isIpv6(string $candidate): bool {
        return filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    /**
     * @param mixed $exception the `exception` field of an entry, as json_decode() gave it
     * @return array<string, mixed>|null class, message, code, place, the first frames and the previous exceptions; null
     *     when the value is not an exception
     */
    public static function exception(mixed $exception, bool $hideFileNames = false): ?array {
        return self::summary(is_object($exception) || is_array($exception) ? json_decode(json_encode($exception), true) : null, self::PREVIOUS_LEVELS, $hideFileNames);
    }

    /**
     * @param mixed $exception the exception as an array
     * @param int $levels previous exceptions still allowed below this one
     * @param bool $hideFileNames whether the names and paths of files in the message are hidden
     * @return array<string, mixed>|null the summary
     */
    private static function summary(mixed $exception, int $levels, bool $hideFileNames): ?array {
        if (!is_array($exception) || !is_string($exception['Exception'] ?? null) || $exception['Exception'] === '') {
            return null;
        }
        $trace = is_array($exception['Trace'] ?? null) ? array_values(array_filter($exception['Trace'], 'is_array')) : [];
        $summary = [
            'class' => self::text($exception['Exception'], 200),
            'message' => self::shown(is_scalar($exception['Message'] ?? null) ? (string)$exception['Message'] : '', self::MESSAGE_LIMIT, $hideFileNames),
            'code' => is_int($exception['Code'] ?? null) ? $exception['Code'] : 0,
            'at' => self::place($exception['File'] ?? null, $exception['Line'] ?? null),
            'trace' => array_map(self::frame(...), array_slice($trace, 0, self::TRACE_FRAMES)),
            'traceFrames' => count($trace),
        ];
        $previous = $levels > 0 ? self::summary($exception['Previous'] ?? null, $levels - 1, $hideFileNames) : null;
        if ($previous !== null) {
            $summary['previous'] = $previous;
        }
        return $summary;
    }

    /**
     * @param array<string, mixed> $frame one frame of the trace
     * @return array{call:string, at:string} class->function and file:line; the arguments are left behind
     */
    private static function frame(array $frame): array {
        $function = is_string($frame['function'] ?? null) ? $frame['function'] : '';
        $class = is_string($frame['class'] ?? null) ? $frame['class'] . (is_string($frame['type'] ?? null) ? $frame['type'] : '->') : '';
        return ['call' => self::text($class . $function, 300), 'at' => self::place($frame['file'] ?? null, $frame['line'] ?? null)];
    }

    /**
     * The fields of an entry the logs tools show, redacted: the extra `data` of the core and the version stay behind.
     *
     * @param \stdClass $entry one well-formed entry of the log
     * @param bool $hideFileNames whether the names and paths of files in url and messages are hidden
     * @return array<string, mixed> time, level by name, app, user, method, url, message, reqId, userAgent, masked
     *     remoteAddr and, when the entry has one, the exception summary
     */
    public static function entry(\stdClass $entry, bool $hideFileNames = false): array {
        $field = static fn (string $name, int $limit, bool $userAgent = false): string => is_scalar($entry->{$name} ?? null) ? self::text((string)$entry->{$name}, $limit, $userAgent) : '';
        $row = [
            'time' => $field('time', 64),
            'level' => LogAnalyzer::LEVELS[(int)$entry->level] ?? (string)$entry->level,
            'app' => $field('app', 100),
            'user' => $field('user', 100),
            'method' => $field('method', 16),
            'url' => is_scalar($entry->url ?? null) ? self::shownUrl((string)$entry->url, self::MESSAGE_LIMIT, $hideFileNames) : '',
            'message' => is_scalar($entry->message ?? null) ? self::shown((string)$entry->message, self::MESSAGE_LIMIT, $hideFileNames) : '',
            'reqId' => $field('reqId', 64),
            'userAgent' => $field('userAgent', 300, true),
            'remoteAddr' => is_string($entry->remoteAddr ?? null) ? self::ip($entry->remoteAddr) : '',
        ];
        $exception = self::exception($entry->exception ?? null, $hideFileNames);
        if ($exception !== null) {
            $row['exception'] = $exception;
        }
        return $row;
    }

    /**
     * @param string $text a message or a URL
     * @param int $limit longest result in characters
     * @param bool $hideFileNames whether the names and paths of files are hidden
     * @return string the text as the tools show it
     */
    public static function shown(string $text, int $limit, bool $hideFileNames): string {
        // Whole text first, cut after: a cut never leaves half a name the patterns no longer recognize.
        $text = self::text($text, PHP_INT_MAX);
        if ($hideFileNames) {
            $text = self::fileNames($text);
        }
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;
    }

    /**
     * @param string $url the url field of an entry
     * @param int $limit longest result in characters
     * @param bool $hideFileNames whether everything after the route prefix is hidden
     * @return string the URL as the tools show it
     */
    public static function shownUrl(string $url, int $limit, bool $hideFileNames): string {
        $url = self::text($url, PHP_INT_MAX);
        if ($hideFileNames) {
            $url = self::url($url);
        }
        return mb_strlen($url) > $limit ? mb_substr($url, 0, $limit) . '…' : $url;
    }

    /** @return string file:line, or '' without a file */
    private static function place(mixed $file, mixed $line): string {
        if (!is_string($file) || $file === '') {
            return '';
        }
        return self::text($file, 300) . (is_int($line) ? ':' . $line : '');
    }
}
