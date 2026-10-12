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
 * - With file names hidden ({@see self::fileNames()}), the names and paths of files in messages and URLs become a
 *   fixed marker. The place and the frames of an exception are the server's own PHP code and stay.
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
    /** Route prefixes of Nextcloud that are not files: an absolute path starting with one stays. */
    private const ROUTES = 'index\.php|remote\.php|public\.php|ocs|ocs-provider|apps|core|settings|login|logout|status\.php'
        . '|cron\.php|s|f|avatar|css|js|svg|dist|l10n|\.well-known';

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
     * Replaces the names and paths of files in a text by {@see self::HIDDEN}. It is a heuristic over free text,
     * applied to whole classes of text rather than to the names of hidden files, which free text cannot identify:
     * - a quoted text with a slash or a known extension, whole ("Orçamento final .xlsx");
     * - what follows the account in a WebDAV path (/remote.php/dav/files/<uid>/…, /remote.php/webdav/…,
     *   /public.php/dav/files/<token>/…);
     * - the value of a query parameter that carries a path (dir, path, file, files, filename, name, source, target,
     *   destination, also URL-encoded);
     * - any other absolute path that is not a route of Nextcloud (/alice/files/…, /var/www/data/…, /Docs/x);
     * - a relative path whose last part has a known extension (Docs/report.pdf), whole;
     * - a bare word with a known extension (salary.txt).
     * It does not catch a name without an extension or with one outside the list when it is neither quoted nor in a
     * path, a name with spaces outside quotes (only the part next to the extension goes), nor a name split by the app
     * that wrote the log into pieces of its own format.
     *
     * @param string $text a text already passed through {@see self::text()}
     * @return string the text with the names and paths replaced
     */
    public static function fileNames(string $text): string {
        $ext = '\.(?:' . self::EXTENSIONS . ')(?![\w-])';
        $marker = self::HIDDEN;
        // Characters that end a path or a name in free text.
        $stop = '\s"\'<>?#';
        $text = preg_replace(
            ['/"[^"]*(?:\/|' . $ext . ')[^"]*"/iu', '/“[^”]*(?:\/|' . $ext . ')[^”]*”/iu', '/(?<!\w)\'[^\']*(?:\/|' . $ext . ')[^\']*\'/iu'],
            ['"' . $marker . '"', '“' . $marker . '”', '\'' . $marker . '\''],
            $text,
        ) ?? '';
        $text = preg_replace('~(/(?:remote|public)\.php/(?:webdav|dav/[\w-]+/[^/' . $stop . ']+))/[^' . $stop . ']+~iu', '$1/' . $marker, $text) ?? '';
        $text = preg_replace('~([?&](?:dir|path|file|files|filename|name|source|target|destination)=)[^&' . $stop . ']*~iu', '$1' . $marker, $text) ?? '';
        $text = preg_replace('~(?<![\w.:/%\]\[-])/(?!(?:' . self::ROUTES . ')(?:[/' . $stop . ']|$))(?!' . preg_quote($marker, '~') . ')[^' . $stop . ']+~iu', $marker, $text) ?? '';
        $text = preg_replace('~(?<![\w.:/%\[-])[^/' . $stop . ']+(?:/[^/' . $stop . ']+)*/[^/' . $stop . ']*' . $ext . '~iu', $marker, $text) ?? '';
        return preg_replace('~(?<![\w./\[-])[^/\\\\|:*' . $stop . ']+' . $ext . '~iu', $marker, $text) ?? '';
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
            'url' => is_scalar($entry->url ?? null) ? self::shown((string)$entry->url, self::MESSAGE_LIMIT, $hideFileNames) : '',
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

    /** @return string file:line, or '' without a file */
    private static function place(mixed $file, mixed $line): string {
        if (!is_string($file) || $file === '') {
            return '';
        }
        return self::text($file, 300) . (is_int($line) ? ':' . $line : '');
    }
}
