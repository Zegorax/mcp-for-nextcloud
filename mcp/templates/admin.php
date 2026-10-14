<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Admin page of the "MCP for Cloud" settings section: five WAI-ARIA tabs, each with one
 * `role="tabpanel"` holding the core `.section` blocks of its area — overview (status), access and
 * permissions (the users × permissions matrix), clients and connections (OAuth clients and active
 * connections), data protection (exclusion tags) and advanced (server log, log privacy, OCR).
 * js/admin-tabs.js provides the tab behavior and the URL hash mapping; js/admin-picker.js is the
 * readable-search multi-select of the log groups and the exclusion tags; the matrix, OAuth clients,
 * tags and log groups are filled by js/admin-grants.js, the users list and the per-user detail panel by
 * js/admin-users.js, the allowed hosts list by js/admin-list.js and the connections by js/connections.js.
 * Each block says whether its changes save at once or need a Save button (data-save-mode). Without
 * JavaScript every panel stays visible, the tab bar stays hidden and the log privacy detail stays open.
 *
 * @var array{endpoint:string, serviceEnabled:bool, ocrActive:bool, ocrUrl:string, version:string, eligibleUsers:int, connectedUsers:int, activeConnections:int, unsupportedApps:list<array{name:string, installed:string, required:string}>, logsAvailable:bool, logType:string} $_
 * @var \OCP\IL10N $l
 */
\OCP\Util::addScript('mcp', 'admin-picker');
\OCP\Util::addScript('mcp', 'admin-list');
\OCP\Util::addScript('mcp', 'admin-grants');
\OCP\Util::addScript('mcp', 'admin-users');
\OCP\Util::addScript('mcp', 'connections');
\OCP\Util::addScript('mcp', 'admin-tabs');
\OCP\Util::addStyle('mcp', 'admin');
?>
<div id="mcp-admin" class="mcp-settings" data-service-enabled="<?php p($_['serviceEnabled'] ? '1' : '0'); ?>">
    <div class="mcp-tab-bar" role="tablist" aria-label="<?php p($l->t('Sections')); ?>">
        <button type="button" role="tab" id="mcp-tab-overview" data-tab-hash="overview" aria-controls="mcp-panel-overview" aria-selected="true"><?php p($l->t('Overview')); ?></button>
        <button type="button" role="tab" id="mcp-tab-access" data-tab-hash="access" aria-controls="mcp-panel-access" aria-selected="false"><?php p($l->t('Access and permissions')); ?></button>
        <button type="button" role="tab" id="mcp-tab-clients" data-tab-hash="clients" aria-controls="mcp-panel-clients" aria-selected="false"><?php p($l->t('Clients and connections')); ?></button>
        <button type="button" role="tab" id="mcp-tab-protection" data-tab-hash="protection" aria-controls="mcp-panel-protection" aria-selected="false"><?php p($l->t('Data protection')); ?><span id="mcp-tab-protection-alert" class="mcp-tab-alert" hidden> <?php p($l->t('(action needed)')); ?></span></button>
        <button type="button" role="tab" id="mcp-tab-advanced" data-tab-hash="advanced" aria-controls="mcp-panel-advanced" aria-selected="false"><?php p($l->t('Advanced')); ?></button>
    </div>

    <div role="tabpanel" id="mcp-panel-overview" aria-labelledby="mcp-tab-overview" class="mcp-tab-panel" tabindex="0">
    <div class="section mcp-block" id="mcp-block-status" data-save-mode="immediate">
        <h2><?php p($l->t('Status')); ?></h2>
        <p class="mcp-save-mode"><?php p($l->t('Changes save at once')); ?></p>
        <div class="mcp-cards">
            <div class="mcp-card mcp-card-wide">
                <span class="mcp-card-label"><?php p($l->t('Endpoint')); ?></span>
                <span class="mcp-endpoint">
                    <code id="mcp-endpoint"><?php p($_['endpoint']); ?></code>
                    <button type="button" class="mcp-copy" data-copy="mcp-endpoint"><?php p($l->t('Copy')); ?></button>
                </span>
            </div>
            <div class="mcp-card">
                <span class="mcp-card-label"><?php p($l->t('Service')); ?></span>
                <span>
                    <input type="checkbox" class="checkbox" id="mcp-service" <?php if ($_['serviceEnabled']) { print_unescaped('checked'); } ?>>
                    <label for="mcp-service"><?php p($l->t('MCP service enabled')); ?></label>
                    <span class="mcp-status" id="mcp-service-status" aria-live="polite"></span>
                </span>
            </div>
            <div class="mcp-card">
                <span class="mcp-card-label"><?php p($l->t('App version')); ?></span>
                <span class="mcp-card-value"><?php p($_['version']); ?></span>
            </div>
            <div class="mcp-card">
                <span class="mcp-card-label"><?php p($l->t('Eligible users')); ?></span>
                <a class="mcp-card-value" id="mcp-count-eligible" href="#access?filter=eligible" title="<?php p($l->t('Show them in Access and permissions')); ?>"><?php p((string)$_['eligibleUsers']); ?></a>
            </div>
            <div class="mcp-card">
                <span class="mcp-card-label"><?php p($l->t('Connected users')); ?></span>
                <a class="mcp-card-value" id="mcp-count-connected" href="#access?filter=connected" title="<?php p($l->t('Show them in Access and permissions')); ?>"><?php p((string)$_['connectedUsers']); ?></a>
                <span class="mcp-hint"><?php p($l->t('Eligible users who activated their connection.')); ?></span>
            </div>
            <div class="mcp-card">
                <span class="mcp-card-label"><?php p($l->t('Active connections')); ?></span>
                <a class="mcp-card-value" id="mcp-count-connections" href="#mcp-connections" title="<?php p($l->t('Show them in Clients and connections')); ?>"><?php p((string)$_['activeConnections']); ?></a>
            </div>
        </div>
        <p class="settings-hint mcp-hint"><?php p($l->t('Paste the endpoint in the MCP client. Turning the service off signs every client out at once.')); ?></p>
        <div id="mcp-tags-invalid-overview" class="mcp-tags-warning" role="alert" hidden>
            <p><?php p($l->t('The saved exclusion tags setting cannot be read, so the MCP tools treat every file and folder as not found. Choose the exclusion tags and save them again.')); ?> <a href="#protection"><?php p($l->t('Open Data protection')); ?></a></p>
        </div>
    </div>

    <div class="section mcp-block" id="mcp-block-howto" data-save-mode="none">
        <h2><?php p($l->t('How access works')); ?></h2>
        <p class="settings-hint mcp-hint"><?php p($l->t('A user can use an MCP tool only when all four of these allow it. Nextcloud’s own permissions (sharing, file access) still apply on top.')); ?></p>
        <dl class="mcp-glossary">
            <dt><?php p($l->t('Can connect')); ?></dt>
            <dd><?php p($l->t('Set by an administrator for each user, in Access and permissions. Without it the user cannot use MCP at all.')); ?> <a href="#access"><?php p($l->t('Open Access and permissions')); ?></a></dd>
            <dt><?php p($l->t('Personal activation')); ?></dt>
            <dd><?php p($l->t('The user turns the connection on in their own personal settings. Only users who can connect see that switch; the administrator cannot turn it on for them.')); ?></dd>
            <dt><?php p($l->t('App permissions')); ?></dt>
            <dd><?php p($l->t('What the AI may do in each app (read, edit, create…), for each user. Reading starts allowed except the server log; writing, deleting and transferring start denied.')); ?></dd>
            <dt><?php p($l->t('OAuth connections')); ?></dt>
            <dd><?php p($l->t('Each MCP client a user signed in with. Revoking one signs that client out; the user can sign in again while they may connect.')); ?> <a href="#clients"><?php p($l->t('Open Clients and connections')); ?></a></dd>
        </dl>
    </div>
    </div>

    <div role="tabpanel" id="mcp-panel-access" aria-labelledby="mcp-tab-access" class="mcp-tab-panel" tabindex="0">
    <div class="section mcp-block" id="mcp-block-matrix" data-save-mode="immediate">
        <h2><?php p($l->t('Permissions')); ?></h2>
        <p class="mcp-save-mode"><?php p($l->t('Changes save at once')); ?></p>
        <p class="settings-hint mcp-hint"><?php p($l->t('Users need “Can connect” and must turn the connection on in their personal settings. Reading starts allowed except the server log; writing, deleting and transferring start denied; Nextcloud permissions still apply.')); ?> <a href="#mcp-block-howto"><?php p($l->t('How access works')); ?></a></p>
        <?php foreach ($_['unsupportedApps'] as $app) { ?>
            <p class="mcp-unsupported-app"><?php p($l->t('%1$s %2$s is installed, but MCP for Cloud supports it from version %3$s on. Its tools stay hidden until the app is updated; the saved permissions are kept.', [$app['name'], $app['installed'], $app['required']])); ?></p>
        <?php } ?>
        <div class="mcp-view-switch" role="group" aria-label="<?php p($l->t('View')); ?>" hidden>
            <button type="button" id="mcp-view-users" data-view="users" aria-pressed="true"><?php p($l->t('Users')); ?></button>
            <button type="button" id="mcp-view-matrix" data-view="matrix" aria-pressed="false"><?php p($l->t('Matrix (advanced)')); ?></button>
        </div>
        <div class="mcp-toolbar">
            <input type="search" id="mcp-search" placeholder="<?php p($l->t('Search user (name, user ID or e-mail)')); ?>" aria-label="<?php p($l->t('Search user')); ?>">
            <select id="mcp-group" aria-label="<?php p($l->t('Group')); ?>">
                <option value=""><?php p($l->t('All users')); ?></option>
            </select>
            <select id="mcp-filter" aria-label="<?php p($l->t('Show')); ?>">
                <option value=""><?php p($l->t('Everyone')); ?></option>
                <option value="eligible" selected><?php p($l->t('Only users who can connect')); ?></option>
                <option value="connected"><?php p($l->t('Only connected users')); ?></option>
            </select>
            <span class="mcp-matrix-only">
                <label for="mcp-app"><?php p($l->t('Show apps')); ?></label>
                <select id="mcp-app" disabled>
                    <option value=""><?php p($l->t('All apps')); ?></option>
                </select>
            </span>
            <span class="mcp-matrix-only">
                <input type="checkbox" class="checkbox" id="mcp-compact">
                <label for="mcp-compact"><?php p($l->t('Compact rows')); ?></label>
            </span>
            <span class="mcp-pager">
                <span id="mcp-count" class="mcp-count" aria-live="polite"></span>
                <button type="button" id="mcp-prev"><?php p($l->t('Previous')); ?></button>
                <span id="mcp-page-info"></span>
                <button type="button" id="mcp-next"><?php p($l->t('Next')); ?></button>
            </span>
        </div>
        <div class="mcp-users-wrap mcp-users-only" id="mcp-users-view">
            <table class="mcp-users-table" id="mcp-users" aria-busy="true">
                <thead>
                    <tr>
                        <th scope="col"><?php p($l->t('User')); ?></th>
                        <th scope="col"><?php p($l->t('Can connect')); ?></th>
                        <th scope="col"><?php p($l->t('Personal activation')); ?></th>
                        <th scope="col"><?php p($l->t('Access')); ?></th>
                        <th scope="col"><span class="hidden-visually"><?php p($l->t('Actions')); ?></span></th>
                    </tr>
                </thead>
                <tbody><tr><td colspan="5" class="mcp-empty"><?php p($l->t('Loading…')); ?></td></tr></tbody>
            </table>
        </div>
        <div class="mcp-matrix-wrap mcp-matrix-only">
            <table class="mcp-matrix" id="mcp-matrix" aria-busy="true">
                <thead></thead>
                <tbody><tr><td class="mcp-empty"><?php p($l->t('Loading…')); ?></td></tr></tbody>
            </table>
        </div>
        <div class="mcp-bulk-notice" id="mcp-bulk-notice" role="status" aria-live="polite">
            <span id="mcp-bulk-notice-text" tabindex="-1"></span>
            <button type="button" id="mcp-bulk-undo" hidden><?php p($l->t('Undo')); ?></button>
        </div>
        <div class="mcp-toolbar mcp-toolbar-bottom">
            <p class="mcp-legend mcp-matrix-only">
                <span class="mcp-legend-item"><input type="checkbox" checked disabled aria-hidden="true" tabindex="-1"> <?php p($l->t('Allowed')); ?></span>
                <span class="mcp-legend-item"><input type="checkbox" disabled aria-hidden="true" tabindex="-1"> <?php p($l->t('Denied')); ?></span>
                <span class="mcp-legend-item"><span class="mcp-state-mark" aria-hidden="true">⊘</span> <?php p($l->t('Unavailable')); ?></span>
                <span><?php p($l->t('Point at ⊘ for the reason.')); ?></span>
            </p>
            <span class="mcp-pager">
                <button type="button" id="mcp-prev-bottom"><?php p($l->t('Previous')); ?></button>
                <span id="mcp-page-info-bottom"></span>
                <button type="button" id="mcp-next-bottom"><?php p($l->t('Next')); ?></button>
            </span>
        </div>
    </div>

    <dialog id="mcp-user-dialog" class="mcp-dialog" aria-labelledby="mcp-user-dialog-title">
        <div class="mcp-dialog-head">
            <h3 id="mcp-user-dialog-title" tabindex="-1"></h3>
            <button type="button" id="mcp-user-dialog-close"><?php p($l->t('Close')); ?></button>
        </div>
        <p class="mcp-save-mode"><?php p($l->t('Changes save at once')); ?></p>
        <div id="mcp-user-dialog-body" class="mcp-dialog-body" aria-live="polite"></div>
    </dialog>
    </div>

    <div role="tabpanel" id="mcp-panel-clients" aria-labelledby="mcp-tab-clients" class="mcp-tab-panel" tabindex="0">
    <div class="section mcp-block" id="mcp-block-oauth" data-save-mode="mixed">
        <h2><?php p($l->t('OAuth clients')); ?></h2>
        <p class="mcp-save-mode"><?php p($l->t('The host list needs Save; the switch saves at once')); ?></p>
        <p class="mcp-field-label" id="mcp-oauth-hosts-label"><?php p($l->t('Allowed client hosts')); ?></p>
        <div id="mcp-oauth-hosts" class="mcp-list-editor" aria-labelledby="mcp-oauth-hosts-label"></div>
        <p>
            <button type="button" id="mcp-oauth-hosts-save"><?php p($l->t('Save')); ?></button>
            <span class="mcp-status" id="mcp-oauth-hosts-status" aria-live="polite"></span>
        </p>
        <p class="settings-hint mcp-hint"><?php p($l->t('One host per entry, without https://, port or path. claude.ai and chatgpt.com are the default. The host is the one in the URL the client uses as its client_id.')); ?></p>
        <p>
            <input type="checkbox" class="checkbox" id="mcp-native-client">
            <label for="mcp-native-client"><?php p($l->t('Allow local programs (native client)')); ?></label>
            <span class="mcp-status" id="mcp-native-client-status" aria-live="polite"></span>
        </p>
        <div id="mcp-native-client-details" class="mcp-native-client-details" hidden>
            <p>
                client_id: <code id="mcp-native-client-id"></code>
                <button type="button" class="mcp-copy" data-copy="mcp-native-client-id"><?php p($l->t('Copy')); ?></button>
            </p>
            <p><?php p($l->t('Accepted redirect URIs')); ?>:</p>
            <ul id="mcp-native-redirects" class="mcp-native-redirects"></ul>
            <p class="settings-hint mcp-hint"><?php p($l->t('Paste this client_id in the client settings, for example Gemini CLI settings.json → mcpServers.<name>.oauth.clientId.')); ?></p>
        </div>
    </div>

    <div class="section mcp-block" id="mcp-connections" data-scope="admin" data-save-mode="immediate">
        <h2><?php p($l->t('Active connections')); ?></h2>
        <p class="mcp-save-mode"><?php p($l->t('Revoking takes effect at once')); ?></p>
        <p class="settings-hint mcp-hint"><?php p($l->t('Each row is one MCP client signed in with OAuth. Revoking signs that client out at once; the user can sign in again while they may connect. App passwords are managed in each user’s security settings.')); ?></p>
        <div class="mcp-toolbar">
            <input type="search" id="mcp-connections-search" placeholder="<?php p($l->t('Search by user ID or client')); ?>" aria-label="<?php p($l->t('Search connections')); ?>">
            <span class="mcp-pager">
                <button type="button" id="mcp-connections-prev"><?php p($l->t('Previous')); ?></button>
                <span id="mcp-connections-page-info"></span>
                <button type="button" id="mcp-connections-next"><?php p($l->t('Next')); ?></button>
            </span>
            <span class="mcp-status" id="mcp-connections-status" aria-live="polite"></span>
        </div>
        <table class="mcp-connections-table" id="mcp-connections-table" aria-busy="true">
            <thead>
                <tr>
                    <th scope="col"><?php p($l->t('User')); ?></th>
                    <th scope="col"><?php p($l->t('Client')); ?></th>
                    <th scope="col"><?php p($l->t('Signed in')); ?></th>
                    <th scope="col"><?php p($l->t('Expires')); ?></th>
                    <th scope="col"><span class="hidden-visually"><?php p($l->t('Actions')); ?></span></th>
                </tr>
            </thead>
            <tbody><tr><td colspan="5" class="mcp-empty"><?php p($l->t('Loading…')); ?></td></tr></tbody>
        </table>
    </div>
    </div>

    <div role="tabpanel" id="mcp-panel-protection" aria-labelledby="mcp-tab-protection" class="mcp-tab-panel" tabindex="0">
    <div class="section mcp-block" id="mcp-block-tags" data-save-mode="immediate">
        <h2><?php p($l->t('Excluded from MCP access')); ?></h2>
        <p class="mcp-save-mode"><?php p($l->t('Changes save at once')); ?></p>
        <p class="settings-hint mcp-hint"><?php p($l->t('Files and folders tagged with a selected tag, and everything inside those folders, are completely hidden from the tools of this MCP connector. The server log tools respect this only while “Hide the text of log messages” is on.')); ?></p>
        <p class="settings-hint mcp-hint"><?php p($l->t('The protection applies only to this connector: it does not cover other AI tools or connectors, and nothing stops someone from sending a file to an AI by hand. Only the tags selected here exclude files; any other tag is an ordinary label.')); ?></p>
        <p class="settings-hint mcp-hint"><?php p($l->t('Recommended: invisible tags, which only administrators can assign or remove, or restricted tags, which only administrators and the groups they allow can assign or remove; users with edit access can remove collaborative tags.')); ?></p>
        <div id="mcp-hidden-tags-section" class="mcp-hidden-tags">
            <div id="mcp-tags-list" class="mcp-tags-container" aria-busy="true">
                <span class="mcp-tags-loading"><?php p($l->t('Loading tags…')); ?></span>
            </div>
            <div id="mcp-tags-invalid" class="mcp-tags-warning" role="alert" style="display: none;">
                <p><?php p($l->t('The saved exclusion tags setting cannot be read, so the MCP tools treat every file and folder as not found. Choose the exclusion tags and save them again.')); ?></p>
                <button type="button" id="mcp-tags-repair"><?php p($l->t('Save exclusion tags')); ?></button>
            </div>
            <div id="mcp-tags-warning" class="mcp-tags-warning" style="display: none;">
                <?php p($l->t('Warning: one or more selected tags are collaborative. Users with edit access can remove collaborative tags, which may expose excluded files.')); ?>
            </div>
            <span class="mcp-status" id="mcp-tags-status" aria-live="polite"></span>
        </div>
    </div>
    </div>

    <div role="tabpanel" id="mcp-panel-advanced" aria-labelledby="mcp-tab-advanced" class="mcp-tab-panel" tabindex="0">
    <div class="section mcp-block" id="mcp-block-logs" data-save-mode="immediate">
        <h2><?php p($l->t('Server log')); ?></h2>
        <p class="mcp-save-mode"><?php p($l->t('Changes save at once')); ?></p>
        <?php if (!$_['logsAvailable']) { ?>
            <p class="mcp-unsupported-app" id="mcp-logs-unavailable"><?php p($l->t('This server writes its log to %s, not to a file, so the server log tools stay hidden. They need the file log, the default of Nextcloud.', [$_['logType']])); ?></p>
        <?php } ?>
        <p class="settings-hint mcp-hint"><?php p($l->t('The server log shows file names, addresses and accounts of every user. Choose below which groups, besides administrators, may read it through MCP.')); ?></p>
        <div id="mcp-logs-groups" class="mcp-logs-groups" aria-busy="true">
            <span class="mcp-tags-loading"><?php p($l->t('Loading groups…')); ?></span>
        </div>
        <p class="mcp-picker-summary mcp-hint" id="mcp-logs-summary"></p>
        <span class="mcp-status" id="mcp-logs-status" aria-live="polite"></span>
    </div>

    <div class="section mcp-block" id="mcp-block-logs-privacy" data-save-mode="immediate">
        <h2><?php p($l->t('Log privacy')); ?></h2>
        <p class="mcp-save-mode"><?php p($l->t('Changes save at once')); ?></p>
        <p>
            <input type="checkbox" class="checkbox" id="mcp-logs-hide-file-names">
            <label for="mcp-logs-hide-file-names"><?php p($l->t('Hide the text of log messages')); ?></label>
            <span class="mcp-status" id="mcp-logs-hide-file-names-status" aria-live="polite"></span>
        </p>
        <p>
            <button type="button" class="mcp-learn-more" id="mcp-logs-privacy-more" aria-expanded="false" aria-controls="mcp-logs-privacy-detail" hidden><?php p($l->t('Learn more')); ?></button>
        </p>
        <div id="mcp-logs-privacy-detail" class="mcp-learn-more-box">
            <p class="settings-hint mcp-hint"><?php p($l->t('On (default): while exclusion tags are configured, the text of log messages and the identification of the client (user agent) are hidden; the date, level, app, user, error type and route stay. Off: the full messages and clients are shown for diagnosis, and the exclusion by tags is not respected. The option exists because some IT teams need the names to track problems.')); ?></p>
        </div>
    </div>

    <div class="section mcp-block" id="mcp-block-uploads" data-save-mode="button">
        <h2><?php p($l->t('Uploads')); ?></h2>
        <p class="mcp-save-mode"><?php p($l->t('Needs Save')); ?></p>
        <p>
            <label for="mcp-checkout-limit"><?php p($l->t('Checkout upload limit')); ?></label>
            <input type="number" id="mcp-checkout-limit" class="mcp-checkout-limit" min="1" max="102400" step="1" inputmode="numeric" aria-describedby="mcp-checkout-limit-info">
            <span><?php p($l->t('MiB')); ?></span>
            <button type="button" id="mcp-checkout-limit-save"><?php p($l->t('Save')); ?></button>
            <span class="mcp-status" id="mcp-checkout-limit-status" aria-live="polite"></span>
        </p>
        <p class="settings-hint mcp-hint" id="mcp-checkout-limit-info"></p>
    </div>

    <div class="section mcp-block" id="mcp-block-ocr" data-save-mode="none">
        <h2><?php p($l->t('OCR')); ?></h2>
        <?php if ($_['ocrActive']) { ?>
            <p class="mcp-ocr" id="mcp-ocr" data-active="1"><?php p($l->t('Workflow OCR is active. PDFs it processes gain a text layer that files_read can read.')); ?></p>
        <?php } else { ?>
            <p class="mcp-ocr settings-hint mcp-hint" id="mcp-ocr" data-active="0">
                <?php p($l->t('Workflow OCR is not active. Scanned PDFs and images have no text for the AI; it is told to ask you or to view the page as an image.')); ?>
                <a href="<?php p($_['ocrUrl']); ?>" target="_blank" rel="noopener noreferrer"><?php p($l->t('Get Workflow OCR in the App Store')); ?></a>.
                <?php p($l->t('It needs ocrmypdf installed on the server. Nothing else in MCP depends on it.')); ?>
            </p>
        <?php } ?>
    </div>
    </div>

    <footer class="mcp-attribution">
        <?php p($l->t('Developed by Sintax')); ?> · <a href="https://sintax.tech" target="_blank" rel="noopener noreferrer">sintax.tech</a>
    </footer>
</div>
