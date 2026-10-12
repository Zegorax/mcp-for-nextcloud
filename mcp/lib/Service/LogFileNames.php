<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service;

use OCP\IAppConfig;

/**
 * Whether the server log tools hide the names and paths of files, chosen by the administrator on the admin page.
 *
 * On by default. It only takes effect while files are hidden by tags: a configured list of hidden tags, or a stored
 * setting that cannot be read, which the guard treats as hiding everything. Off, the log tools show full names for
 * diagnosis and do not respect the hiding by tags.
 */
class LogFileNames {
    /** App config key, a boolean. */
    public const CONFIG_KEY = 'logs_hide_file_names';

    public function __construct(
        private IAppConfig $appConfig,
        private VisibilityGuard $visibility,
    ) {}

    /** @return bool the administrator's choice; true when it cannot be read */
    public function enabled(): bool {
        try {
            return $this->appConfig->getValueBool('mcp', self::CONFIG_KEY, true);
        } catch (\Throwable) {
            return true;
        }
    }

    /** @param bool $enabled whether the log tools hide file names while files are hidden by tags */
    public function setEnabled(bool $enabled): void {
        $this->appConfig->setValueBool('mcp', self::CONFIG_KEY, $enabled);
    }

    /** @return bool whether the log tools hide file names right now */
    public function active(): bool {
        return $this->enabled()
            && (!$this->visibility->isConfigurationValid() || $this->visibility->getHiddenTagIds() !== []);
    }
}
