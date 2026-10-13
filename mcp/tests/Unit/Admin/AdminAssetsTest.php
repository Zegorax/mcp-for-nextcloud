<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/** The admin template's scripts and styles exist, and the removed user picker is gone. */
final class AdminAssetsTest extends TestCase {
    /**
     * Front-end globals Nextcloud 34 and 35 removed (developer manual, "Upgrade to Nextcloud 34/35"). The scripts have
     * no build step and run on every declared major, so none of them may appear.
     */
    private const REMOVED_GLOBALS = [
        '/\bOC\.Notifications?\b/' => 'OC.Notification (34)',
        '/\bOC\.Dialogs\.fileexists\b/' => 'OC.Dialogs.fileexists (34)',
        '/\bOC\.Apps\b/' => 'OC.Apps (34)',
        '/\bOC\.\w*[mM]enu\w*\b/' => 'OC.*menu* (34)',
        '/\bOC\.Files\.Client\b/' => 'OC.Files.Client (34)',
        '/\bsnapper\b/' => 'snapper (34)',
        '/\bjQuery\b|(?<![\w.])\$\s*\(/' => 'jQuery (34)',
        '/\bBackbone\b/' => 'Backbone (34)',
        '/\bHandlebars\b/' => 'Handlebars (34)',
        '/\boc_(?:appswebroots|config|current_user|debug|defaults|isadmin|requesttoken|webroot)\b/' => 'oc_* aliases (35)',
        '/\bOCDialogs\b/' => 'OCDialogs (35)',
        '/(?<![\w.$])_\.\w/' => 'underscore (35)',
        '/\bClipboard(?:JS)?\b/' => 'Clipboard (35)',
        '/(?<![\w.])dav\./' => 'dav (35)',
        '/(?<![\w.])moment\b/' => 'moment (35)',
    ];

    public function testScriptsAndTemplatesUseNoFrontEndGlobalRemovedUpToNextcloud35(): void {
        $app = dirname(__DIR__, 3);
        $files = [...glob($app . '/js/*.js') ?: [], ...glob($app . '/templates/*.php') ?: []];
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $source = (string)file_get_contents($file);
            foreach (self::REMOVED_GLOBALS as $pattern => $global) {
                $this->assertSame(0, preg_match($pattern, $source, $match), basename($file) . " uses $global: " . ($match[0] ?? ''));
            }
        }
    }

    /**
     * OCP.Toast still exists up to 35 but is deprecated: when it is missing the error must still reach the user through
     * something that is no Nextcloud global, never be dropped.
     */
    public function testErrorsReachTheUserEvenWithoutTheToastGlobal(): void {
        $app = dirname(__DIR__, 3);
        foreach (['connections', 'admin-grants'] as $script) {
            $source = (string)file_get_contents("$app/js/$script.js");
            $this->assertSame(1, preg_match('/function notifyError\(message\) \{(.*?)\n\t\}/s', $source, $body), $script);
            $this->assertStringContainsString('OCP.Toast.error(message)', $body[1], $script);
            $this->assertMatchesRegularExpression('/\} else \{/', $body[1], "$script: no unconditional fallback after OCP.Toast");
            $this->assertMatchesRegularExpression('/textContent = message|window\.alert\(message\)/', $body[1], "$script: the fallback must show the message");
        }
    }

    public function testReferencedScriptsAndStylesExist(): void {
        $app = dirname(__DIR__, 3);
        $template = (string)file_get_contents($app . '/templates/admin.php');
        preg_match_all("/Util::add(Script|Style)\\(\\s*'mcp'\\s*,\\s*'([^']+)'/", $template, $matches, PREG_SET_ORDER);
        $this->assertCount(5, $matches);
        foreach ($matches as [, $kind, $name]) {
            $file = $kind === 'Script' ? "$app/js/$name.js" : "$app/css/$name.css";
            $this->assertFileExists($file);
        }
        $this->assertFileDoesNotExist($app . '/js/admin-user-picker.js');
        $this->assertFileDoesNotExist($app . '/lib/Controller/UsersController.php');
    }

    /**
     * Every element id the admin scripts look up appears exactly once, and the div tags balance, so
     * getElementById() never binds to a stray copy of a section (the hidden-tags block was once merged in three times).
     */
    public function testAdminTemplateHasUniqueIdsAndBalancedDivs(): void {
        $app = dirname(__DIR__, 3);
        $template = (string)file_get_contents($app . '/templates/admin.php');
        $script = (string)file_get_contents($app . '/js/admin-grants.js') . file_get_contents($app . '/js/connections.js') . file_get_contents($app . '/js/admin-tabs.js') . file_get_contents($app . '/js/admin-picker.js');
        preg_match_all("/getElementById\\('([^']+)'\\)/", $script, $used);
        $this->assertNotEmpty($used[1]);
        foreach (array_unique($used[1]) as $id) {
            $this->assertSame(1, substr_count($template, 'id="' . $id . '"'), "id $id must appear exactly once");
        }
        $this->assertSame(1, substr_count($template, "t('Excluded from MCP access')"));
        $this->assertSame(preg_match_all('/<div\b/', $template), preg_match_all('/<\/div>/', $template), 'div tags must balance');
    }

    /**
     * The OAuth clients block opens the Clients and connections tab (before the Active connections
     * block), and each string of the section is translated.
     */
    public function testOauthClientsSectionIsPlacedAndTranslated(): void {
        $app = dirname(__DIR__, 3);
        $template = (string)file_get_contents($app . '/templates/admin.php');
        $start = strpos($template, 'id="mcp-block-oauth"');
        $end = strpos($template, 'id="mcp-connections"');
        $this->assertIsInt($start, 'the OAuth clients block');
        $this->assertIsInt($end, 'the active connections block');
        $this->assertTrue($start < $end, 'the OAuth clients block opens the Clients and connections tab');
        foreach (['mcp-oauth-hosts', 'mcp-oauth-hosts-status', 'mcp-native-client', 'mcp-native-client-status', 'mcp-native-client-details'] as $id) {
            $this->assertSame(1, substr_count($template, 'id="' . $id . '"'), $id);
        }
        $this->assertStringContainsString('data-copy="mcp-native-client-id"', $template);
        $section = substr($template, $start, $end - $start);
        preg_match_all("/\\\$l->t\\('([^']+)'\\)/", $section, $fromTemplate);
        $script = (string)file_get_contents($app . '/js/admin-grants.js');
        $oauthScript = substr($script, (int)strpos($script, 'async function initOauthClients'));
        preg_match_all("/t\\('mcp', '([^']+)'/", $oauthScript, $fromScript);
        $strings = array_unique(array_merge($fromTemplate[1], $fromScript[1]));
        $this->assertGreaterThan(8, count($strings));
        foreach (['pt_BR', 'es'] as $lang) {
            $translations = json_decode((string)file_get_contents("$app/l10n/$lang.json"), true)['translations'];
            foreach ($strings as $text) {
                $this->assertArrayHasKey($text, $translations, "$lang: $text");
            }
        }
    }

    /**
     * Five WAI-ARIA tabs in the planned order (overview, access, clients, protection, advanced), each
     * a mapped URL hash, each bound to a role=tabpanel that names the tab back, the panels wrapping
     * the named section blocks in that order, and none of them hidden: without JavaScript the whole
     * page stays visible. The tabs script is loaded by the template.
     */
    public function testAdminPageHasAccessibleTabsMappedToUrlHashes(): void {
        $app = dirname(__DIR__, 3);
        $template = (string)file_get_contents($app . '/templates/admin.php');

        $this->assertStringContainsString("Util::addScript('mcp', 'admin-tabs')", $template);
        $this->assertSame(1, substr_count($template, 'role="tablist"'));
        preg_match_all('/<button type="button" role="tab" id="(mcp-tab-[a-z]+)" data-tab-hash="([a-z]+)" aria-controls="(mcp-panel-[a-z]+)" aria-selected="(true|false)">/', $template, $tabs, PREG_SET_ORDER);
        $this->assertCount(5, $tabs);
        $this->assertSame(['overview', 'access', 'clients', 'protection', 'advanced'], array_map(static fn (array $tab) => $tab[2], $tabs));
        foreach ($tabs as [, $tabId, $hash]) {
            $this->assertSame($hash, substr($tabId, strlen('mcp-tab-')), "tab $tabId must carry its own name as the hash");
        }
        $this->assertSame('true', $tabs[0][4]);
        foreach (array_slice($tabs, 1) as $tab) {
            $this->assertSame('false', $tab[4], 'only the first tab starts selected');
        }

        $panels = [];
        preg_match_all('/<div role="tabpanel" id="(mcp-panel-[a-z]+)" aria-labelledby="(mcp-tab-[a-z]+)" class="mcp-tab-panel" tabindex="0">/', $template, $panelMatches, PREG_SET_ORDER);
        foreach ($panelMatches as $panel) {
            $panels[$panel[1]] = $panel[2];
        }
        $this->assertCount(5, $panels);
        foreach ($tabs as [, $tabId, , $panelId]) {
            $this->assertSame($tabId, $panels[$panelId] ?? null, "panel $panelId must name its tab");
        }
        $this->assertSame(0, preg_match('/class="mcp-tab-panel"[^>]*\shidden\b/', $template), 'no panel ships hidden: without JS the page must not be empty');

        // The panels wrap their blocks in the tab order; ids and classes of the blocks stay as they were.
        $ordered = [
            'role="tablist"',
            'id="mcp-panel-overview"', 'id="mcp-block-status"',
            'id="mcp-panel-access"', 'id="mcp-block-matrix"',
            'id="mcp-panel-clients"', 'id="mcp-block-oauth"', 'id="mcp-connections"',
            'id="mcp-panel-protection"', 'id="mcp-block-tags"',
            'id="mcp-panel-advanced"', 'id="mcp-block-logs"', 'id="mcp-block-logs-privacy"', 'id="mcp-block-ocr"',
            'class="mcp-attribution"',
        ];
        $positions = array_map(static fn (string $needle) => strpos($template, $needle), $ordered);
        $this->assertSame(false, in_array(false, $positions, true), 'every tab, panel and block must exist');
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'tabs, panels and blocks must appear in the tab order');
    }

    /** Eight blocks, each a core settings section with its own heading, in the tab order. */
    public function testAdminPageHasEightSectionBlocks(): void {
        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/admin.php');
        preg_match_all('/<div class="section mcp-block" id="([^"]+)"[^>]*>\s*<h2><\?php p\(\$l->t\(\'([^\']+)\'\)\); \?><\/h2>/', $template, $blocks);
        $this->assertSame(['mcp-block-status', 'mcp-block-matrix', 'mcp-block-oauth', 'mcp-connections', 'mcp-block-tags', 'mcp-block-logs', 'mcp-block-logs-privacy', 'mcp-block-ocr'], $blocks[1]);
        $this->assertSame(['Status', 'Permissions', 'OAuth clients', 'Active connections', 'Excluded from MCP access', 'Server log', 'Log privacy', 'OCR'], $blocks[2]);
        $this->assertStringContainsString('data-scope="admin"', $template);
    }

    /** The personal page loads the shared connections script, and every id that script looks up appears once. */
    public function testPersonalTemplateCarriesTheConnectionsBlock(): void {
        $app = dirname(__DIR__, 3);
        $template = (string)file_get_contents($app . '/templates/personal.php');
        $this->assertStringContainsString("Util::addScript('mcp', 'connections')", $template);
        $this->assertStringContainsString('data-scope="personal"', $template);
        preg_match_all("/getElementById\\('([^']+)'\\)/", (string)file_get_contents($app . '/js/connections.js'), $used);
        // Admin-only elements: the search box and the Status card the revoke buttons refresh.
        foreach (array_diff(array_unique($used[1]), ['mcp-connections-search', 'mcp-count-connections']) as $id) {
            $this->assertSame(1, substr_count($template, 'id="' . $id . '"'), "id $id must appear exactly once");
        }
        $admin = (string)file_get_contents($app . '/templates/admin.php');
        $this->assertSame(1, substr_count($admin, 'id="mcp-count-connections"'), 'the admin Status card the script refreshes');
        $this->assertStringNotContainsString('mcp-count-connections', $template, 'the personal page has no Status card');
        $this->assertStringNotContainsString('mcp-connections-search', $template, 'the personal list has no user search');
        $this->assertSame(preg_match_all('/<div\b/', $template), preg_match_all('/<\/div>/', $template), 'div tags must balance');
    }

    /** Revoke buttons name their connection, an emptied last page steps back, and narrow screens have a layout. */
    public function testConnectionsScriptAndStylesCoverAccessibilityAndNarrowScreens(): void {
        $app = dirname(__DIR__, 3);
        $script = (string)file_get_contents($app . '/js/connections.js');
        foreach (["'Revoke {client} for {user}'", "'Revoke {client}'", "'Revoke all connections of {user}'"] as $label) {
            $this->assertStringContainsString($label, $script);
        }
        $this->assertStringContainsString('state.page > 1', $script);
        $css = (string)file_get_contents($app . '/css/admin.css');
        $this->assertStringContainsString('@media (max-width: 768px)', $css);
        $narrow = substr($css, (int)strpos($css, '@media (max-width: 768px)'));
        $this->assertStringNotContainsString('#', preg_replace('/#mcp-admin/', '', $narrow), 'only theme variables and no fixed colors');
        $this->assertStringContainsString('overflow-x: auto', $narrow);
    }

    /** The checkout limit field repeats the controller's ceiling in its max attribute; the two must not drift apart. */
    public function testCheckoutLimitFieldMaxMatchesTheController(): void {
        $app = dirname(__DIR__, 3);
        $template = (string)file_get_contents($app . '/templates/admin.php');
        $this->assertSame(1, preg_match('/<input[^>]*id="mcp-checkout-limit"[^>]*>/', $template, $input));
        $this->assertSame(1, preg_match('/\bmax="(\d+)"/', $input[0], $max));
        $this->assertSame(\OCA\Mcp\Controller\CheckoutLimitController::MAX_MIB, (int)$max[1]);
        $this->assertStringContainsString('Math.max(1, Math.ceil(data.configuredBytes / 1048576))', (string)file_get_contents($app . '/js/admin-grants.js'));
    }

    public function testRoutesDropTheOldAdminFormsAndKeepOAuth(): void {
        $routes = array_column((require dirname(__DIR__, 3) . '/appinfo/routes.php')['routes'], 'name');
        foreach (['settings#global', 'settings#user', 'users#index'] as $gone) {
            $this->assertNotContains($gone, $routes);
        }
        foreach (['settings#personal', 'grants#index', 'grants#update', 'grants#bulk', 'grants#service', 'grants#oauthClients', 'grants#updateOauthClients', 'o_auth#token', 'metadata#protectedResource',
            'connections#index', 'connections#destroy', 'connections#revokeUser', 'my_connections#index', 'my_connections#destroy', 'checkout_limit#show', 'checkout_limit#update'] as $kept) {
            $this->assertContains($kept, $routes);
        }
    }

    /** Both checkout routes must exist, and the upload one has to answer PUT and POST. */
    public function testCheckoutRoutesExistAndAcceptPutAndPost(): void {
        $routes = (require dirname(__DIR__, 3) . '/appinfo/routes.php')['routes'];
        $checkout = array_values(array_filter($routes, static fn (array $route) => str_starts_with((string)$route['name'], 'checkout#')));
        $this->assertSame(['checkout#download', 'checkout#upload', 'checkout#uploadPost'],
            array_column($checkout, 'name'));
        $this->assertSame(['/checkout/{token}', '/checkout/{token}', '/checkout/{token}'], array_column($checkout, 'url'));
        $this->assertSame(['GET', 'PUT', 'POST'], array_column($checkout, 'verb'));
    }

    /**
     * The groups that may read the server log are saved one change at a time: the picker is locked
     * while a save runs, the save carries the list it changed, a 409 reloads the list and says so,
     * and a deleted group is shown so it can be removed.
     */
    public function testTheLogGroupsAreSavedOneAtATimeWithTheListTheyChanged(): void {
        $app = dirname(__DIR__, 3);
        $script = (string)file_get_contents($app . '/js/admin-grants.js');
        $logs = substr($script, (int)strpos($script, 'async function initLogsAccess'));
        $this->assertStringContainsString('groups: nextIds, previous: previousIds, version: version', $logs);
        $this->assertStringContainsString("version = data.version", $logs);
        $this->assertStringContainsString("e.message === '409'", $logs);
        $this->assertStringContainsString("t('mcp', 'The groups were changed in another window or by another administrator; the current list was loaded.')", $logs);
        $this->assertStringContainsString("t('mcp', '{group} (group removed)'", $logs);
        // One save at a time, with the control locked, is the picker's contract.
        $picker = (string)file_get_contents($app . '/js/admin-picker.js');
        $this->assertMatchesRegularExpression('/if \(busy \|\| \(fallbackText !== null && !force\)\) \{\s*return/', $picker);
        $this->assertStringContainsString("setAttribute('aria-busy', locked ? 'true' : 'false')", $picker);
    }

    /**
     * Log privacy keeps its native checkbox, id and saving; the "Learn more" button ships
     * hidden and the detail ships visible, so without JavaScript nothing of the page is lost.
     */
    public function testLogPrivacyHasItsOwnBlockWithAWiredSwitchAndLearnMore(): void {
        $app = dirname(__DIR__, 3);
        $template = (string)file_get_contents($app . '/templates/admin.php');
        $this->assertSame(1, substr_count($template, 'id="mcp-block-logs-privacy"'));
        $this->assertSame(1, substr_count($template, 'class="checkbox" id="mcp-logs-hide-file-names"'), 'the native Nextcloud checkbox');
        $this->assertSame(1, preg_match('/<button type="button" class="mcp-learn-more" id="mcp-logs-privacy-more" aria-expanded="false" aria-controls="mcp-logs-privacy-detail" hidden>/', $template));
        $this->assertSame(1, substr_count($template, 'id="mcp-logs-privacy-detail" class="mcp-learn-more-box"'), 'the detail ships visible for the no-JS page');
        $this->assertSame(1, substr_count($template, 'class="mcp-picker-summary mcp-hint" id="mcp-logs-summary"'));
        $script = (string)file_get_contents($app . '/js/admin-grants.js');
        $this->assertStringContainsString("more.hidden = false", $script);
        $this->assertStringContainsString("more.setAttribute('aria-expanded', detail.hidden ? 'false' : 'true')", $script);
    }

    /** The empty-tags repair bypasses only fallback, retaining confirmation, locking and saving. */
    public function testUnreadableTagsCanBeRepairedWithoutAnySystemTags(): void {
        $app = dirname(__DIR__, 3);
        $script = (string)file_get_contents($app . '/js/admin-grants.js');
        $picker = (string)file_get_contents($app . '/js/admin-picker.js');
        $this->assertStringContainsString('await picker.commit({ force: true })', $script);
        $this->assertStringContainsString('commit({ force = false } = {})', $picker);
        $this->assertStringContainsString('return commit(new Set(selected), force)', $picker);
        $this->assertStringContainsString('if (busy || (fallbackText !== null && !force))', $picker);
        $this->assertMatchesRegularExpression('/if \(spec.confirmSave && !\(await spec.confirmSave\(Array.from\(next\)\)\)\) \{\s*return/', $picker);
        $this->assertStringContainsString("confirmSave: (nextIds) => nextIds.length > 0", $script);
        $this->assertStringContainsString("await api('PUT', '/api/admin/tags', { tagIds })", $script);
        $this->assertStringContainsString('setInvalidState(false)', $script);
    }

    /**
     * Real containment, not just order: each block id's ancestor with role=tabpanel must be the panel
     * of its tab, so a block can never leak out of its tab and show on every one.
     */
    public function testEveryBlockLivesInsideItsOwnTabPanel(): void {
        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/admin.php');
        // The panel/block structure is plain HTML; the PHP in the template only echoes texts.
        $html = preg_replace('/<\?php.*?\?>/s', '', $template);
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            $loaded = $doc->loadHTML($html);
        } finally {
            libxml_use_internal_errors($previous);
        }
        $this->assertTrue($loaded);
        $blocks = [
            'mcp-panel-overview' => ['mcp-block-status'],
            'mcp-panel-access' => ['mcp-block-matrix'],
            'mcp-panel-clients' => ['mcp-block-oauth', 'mcp-connections'],
            'mcp-panel-protection' => ['mcp-block-tags'],
            'mcp-panel-advanced' => ['mcp-block-logs', 'mcp-block-logs-privacy', 'mcp-block-ocr'],
        ];
        foreach ($blocks as $panelId => $blockIds) {
            foreach ($blockIds as $blockId) {
                $block = $doc->getElementById($blockId);
                $this->assertNotNull($block, $blockId);
                $ancestor = $block->parentNode;
                while ($ancestor instanceof \DOMElement && $ancestor->getAttribute('role') !== 'tabpanel') {
                    $ancestor = $ancestor->parentNode;
                }
                $this->assertInstanceOf(\DOMElement::class, $ancestor, "$blockId must live inside a tab panel");
                $this->assertSame($panelId, $ancestor->getAttribute('id'), "$blockId must live inside $panelId");
            }
        }
    }

    /**
     * The unreadable exclusion setting refuses every file, so the sign of it cannot stay inside a
     * hidden tab panel: the Status block of Overview shows the same alert with a link to the
     * protection tab, the tab carries a marker, and the script toggles both.
     */
    public function testUnreadableTagsSettingIsSignalledInOverviewAndOnTheTab(): void {
        $app = dirname(__DIR__, 3);
        $template = (string)file_get_contents($app . '/templates/admin.php');
        $this->assertSame(1, preg_match('/<div id="mcp-tags-invalid-overview" class="mcp-tags-warning" role="alert" hidden>/', $template), 'the Overview alert starts hidden and is announced when shown');
        $this->assertSame(1, substr_count($template, '<a href="#protection">'), 'the alert links to the Data protection tab');
        $this->assertSame(1, substr_count($template, '<span id="mcp-tab-protection-alert" class="mcp-tab-alert" hidden>'), 'the tab marker');
        // The Overview alert repeats the repair sentence of the protection block.
        $this->assertSame(2, substr_count($template, "t('The saved exclusion tags setting cannot be read"), 'the same text on both alerts');
        $script = (string)file_get_contents($app . '/js/admin-grants.js');
        $this->assertStringContainsString("document.getElementById('mcp-tags-invalid-overview')", $script);
        $this->assertStringContainsString("document.getElementById('mcp-tab-protection-alert')", $script);
        $this->assertStringContainsString('overviewAlert.hidden = !isInvalid', $script);
        $this->assertStringContainsString('tabAlert.hidden = !isInvalid', $script);
    }
}